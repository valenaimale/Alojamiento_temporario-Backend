<?php

namespace App\Controllers;

use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\ValidadorFechas;

//El precio de cada noche de una unidad (US2.7), con su historial.
//Un precio guardado nunca se modifica ni se borra: para cambiarlo se cierra la fila vigente
//(vigente_hasta = ahora) y se crea otra. Así se puede reconstruir a qué precio se reservó cada noche.
//Los precios no se quitan: una noche que ya tiene precio siempre tiene uno vigente. Para que no se reserve,
//el propietario la bloquea (US3.3). Cargar precios es solo del propietario; verlos, también del administrador
//asignado (gestiona la unidad: necesita ver el calendario completo).
class PreciosController
{
    private const MAXIMO_NOCHES = 366;   //un rango (para ver o para cargar) cubre como mucho un año

    //GET /unidades/precios?id=5&desde=2026-12-01&hasta=2026-12-31: cada noche del rango con su precio vigente
    //(o null), su temporada (o null) y el mínimo de noches que se le exige (1 si no tiene temporada).
    //Se pueden pedir noches pasadas: el calendario del mes en curso las muestra.
    //Lo ven el propietario y el administrador asignado.
    public function ver()
    {
        $unidad = PermisosUnidad::delPropietarioOAdministrador($_GET['id'] ?? '');//si no puede, responde y corta acá

        $rango = $this->validarRango((string) ($_GET['desde'] ?? ''), (string) ($_GET['hasta'] ?? ''));
        if (isset($rango['error'])) {
            return $this->error($rango['error']);
        }
        [$desde, $hasta] = $rango;
        $pdo = Conexion::obtener();

        $consulta = $pdo->prepare(
            'SELECT fecha, precio FROM precio_noche
             WHERE unidad_id = ? AND fecha BETWEEN ? AND ? AND vigente_hasta IS NULL'
        );
        $consulta->execute([$unidad['id'], $desde->format('Y-m-d'), $hasta->format('Y-m-d')]);
        $precios = $consulta->fetchAll(\PDO::FETCH_KEY_PAIR);//fecha → precio

        $consulta = $pdo->prepare(
            'SELECT nombre, fecha_inicio, fecha_fin, minimo_noches FROM temporadas
             WHERE unidad_id = ? AND fecha_inicio <= ? AND fecha_fin >= ?'
        );
        $consulta->execute([$unidad['id'], $hasta->format('Y-m-d'), $desde->format('Y-m-d')]);
        $temporadas = $consulta->fetchAll();

        $noches = [];
        for ($noche = $desde; $noche <= $hasta; $noche = $noche->modify('+1 day')) {
            $fecha = $noche->format('Y-m-d');
            $temporada = $this->temporadaDe($fecha, $temporadas);
            $noches[] = [
                'fecha'         => $fecha,
                'precio'        => $precios[$fecha] ?? null,
                'temporada'     => $temporada['nombre'] ?? null,
                'minimo_noches' => (int) ($temporada['minimo_noches'] ?? 1),
            ];
        }

        $consulta = $pdo->prepare('SELECT cobra_iva FROM propietarios WHERE usuario_id = ?');
        $consulta->execute([$unidad['propietario_id']]);

        http_response_code(200);
        echo json_encode([
            'cobra_iva' => (bool) $consulta->fetchColumn(),//si cobra IVA, la pantalla aclara que el precio lo incluye (RN-13)
            'noches'    => $noches,
        ]);
    }

    //POST /unidades/precios/cargar: {"unidad_id", "desde", "hasta", "precio"}: el mismo precio para todas las noches
    //del rango (las dos fechas incluidas), de hoy en adelante. Las noches que ya tenían ese precio no cambian.
    public function cargar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $rango = $this->validarRango((string) ($datos['desde'] ?? ''), (string) ($datos['hasta'] ?? ''));
        if (isset($rango['error'])) {
            return $this->error($rango['error']);
        }
        [$desde, $hasta] = $rango;
        if ($desde < ValidadorFechas::hoy()) {
            return $this->error('Solo podés cargar precios de hoy en adelante');
        }
        $precio = $this->validarPrecio($datos['precio'] ?? '');
        if ($precio === null) {
            return $this->error('El precio tiene que ser un monto mayor que 0, con hasta 2 decimales');
        }

        $unidadId = (int) $unidad['id'];
        $usuarioId = (int) $_SESSION['usuario']['id'];
        //El mismo instante para todo el rango, en UTC como el servidor (Railway). Se calcula en PHP y no con NOW()
        //de MySQL, que en cada máquina puede estar en otra zona horaria.
        $ahora = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            $this->bloquearUnidad($unidadId);

            //1. cierra los precios vigentes del rango que sean distintos del nuevo
            $consulta = $pdo->prepare(
                'UPDATE precio_noche SET vigente_hasta = ?
                 WHERE unidad_id = ? AND fecha BETWEEN ? AND ? AND vigente_hasta IS NULL AND precio <> ?'
            );
            $consulta->execute([$ahora, $unidadId, $desde->format('Y-m-d'), $hasta->format('Y-m-d'), $precio]);

            //2. las noches que siguen con un precio vigente ya tenían ese precio: se dejan como están
            $consulta = $pdo->prepare(
                'SELECT fecha FROM precio_noche
                 WHERE unidad_id = ? AND fecha BETWEEN ? AND ? AND vigente_hasta IS NULL'
            );
            $consulta->execute([$unidadId, $desde->format('Y-m-d'), $hasta->format('Y-m-d')]);
            $sinCambios = array_flip($consulta->fetchAll(\PDO::FETCH_COLUMN));

            //3. una fila nueva por cada noche que cambia
            $insertar = $pdo->prepare(
                'INSERT INTO precio_noche (unidad_id, fecha, vigente_desde, precio, creado_por) VALUES (?, ?, ?, ?, ?)'
            );
            $cambiadas = 0;
            for ($noche = $desde; $noche <= $hasta; $noche = $noche->modify('+1 day')) {
                $fecha = $noche->format('Y-m-d');
                if (!isset($sinCambios[$fecha])) {
                    $insertar->execute([$unidadId, $fecha, $ahora, $precio, $usuarioId]);
                    $cambiadas++;
                }
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            //la clave primaria es (unidad_id, fecha, vigente_desde): dos cambios de la misma noche en el mismo segundo chocan
            if ($e->getCode() === '23000') {
                return $this->error('Esperá un momento y volvé a intentar', 409);
            }
            throw $e;//lo atrapa el router y responde 500
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        http_response_code(200);
        echo json_encode([
            'ok'               => $cambiadas === 0 ? 'Esas noches ya tenían ese precio' : "Guardamos el precio de $cambiadas " . ($cambiadas === 1 ? 'noche' : 'noches'),
            'noches_cambiadas' => $cambiadas,
        ]);
    }

    //Valida un rango de noches (las dos fechas incluidas). Devuelve [desde, hasta] o ['error' => mensaje].
    private function validarRango(string $textoDesde, string $textoHasta): array
    {
        $desde = ValidadorFechas::fecha($textoDesde);
        $hasta = ValidadorFechas::fecha($textoHasta);
        if ($desde === null || $hasta === null) {
            return ['error' => 'Las fechas tienen que tener el formato AAAA-MM-DD'];
        }
        if ($desde > $hasta) {
            return ['error' => 'La fecha "desde" no puede ser posterior a la fecha "hasta"'];
        }
        if (ValidadorFechas::noches($desde, $hasta) + 1 > self::MAXIMO_NOCHES) {//+1: las dos fechas son noches incluidas
            return ['error' => 'El rango puede tener hasta ' . self::MAXIMO_NOCHES . ' noches'];
        }
        return [$desde, $hasta];
    }

    //El precio como texto con 2 decimales ("70000.50"), o null si no es un monto mayor que 0 con hasta 2 decimales
    //que entre en decimal(12,2). Acepta número (70000.5) o texto ("70000.50").
    private function validarPrecio(mixed $valor): ?string
    {
        if (!is_int($valor) && !is_float($valor) && !is_string($valor)) {
            return null;
        }
        $texto = trim((string) $valor);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {
            return null;
        }
        return number_format((float) $texto, 2, '.', '');
    }

    //La temporada que incluye esa noche, o null. Las temporadas de una unidad no se superponen: hay una como mucho.
    private function temporadaDe(string $fecha, array $temporadas): ?array
    {
        foreach ($temporadas as $temporada) {
            if ($temporada['fecha_inicio'] <= $fecha && $fecha <= $temporada['fecha_fin']) {//AAAA-MM-DD se compara como texto
                return $temporada;
            }
        }
        return null;
    }

    //Traba la fila de la unidad hasta el commit: dos cargas de precios a la vez sobre la misma unidad
    //se hacen de a una, así cada noche queda con un solo precio vigente.
    private function bloquearUnidad(int $unidadId): void
    {
        Conexion::obtener()->prepare('SELECT id FROM unidades WHERE id = ? FOR UPDATE')->execute([$unidadId]);
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
