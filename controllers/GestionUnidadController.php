<?php

namespace App\Controllers;

use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Mail\AvisoGestionMail;
use App\Request\Request;

//Quién gestiona una unidad (US2.2 a US2.4): su administrador, con su comisión, y su operador de estadías.
//- Administrador: lo asigna, le cambia la comisión y lo quita solo el propietario. Una unidad tiene como mucho uno;
//  sin administrador, la unidad es de autogestión. Cada cambio se le avisa por mail.
//- Operador: lo pueden asignar y quitar el propietario y el administrador de la unidad.
//Se asigna por mail (no hay buscador de usuarios: así nadie puede listar las cuentas de otros).
class GestionUnidadController
{
    //GET /unidades/gestion?id=5: el administrador (o null) y el operador (o null) de la unidad.
    //Lo ven el propietario y el administrador asignado (el administrador maneja el operador).
    public function ver()
    {
        $unidad = PermisosUnidad::delPropietarioOAdministrador($_GET['id'] ?? '');//si no puede, responde y corta acá
        $pdo = Conexion::obtener();

        $consulta = $pdo->prepare(
            'SELECT u.nombre, u.mail, a.porcentaje_comision, a.desde
             FROM administraciones a
             JOIN usuarios u ON u.id = a.administrador_id
             WHERE a.unidad_id = ?'
        );
        $consulta->execute([$unidad['id']]);
        $administrador = $consulta->fetch() ?: null;

        $operador = null;
        if ($unidad['operador_id'] !== null) {
            $consulta = $pdo->prepare('SELECT nombre, mail FROM usuarios WHERE id = ?');
            $consulta->execute([$unidad['operador_id']]);
            $operador = $consulta->fetch() ?: null;
        }

        http_response_code(200);
        echo json_encode([
            'administrador'  => $administrador,
            'operador'       => $operador,
            'es_propietario' => $unidad['es_propietario'],//false = es el administrador: no puede cambiar su propia asignación
        ]);
    }

    //POST /unidades/administrador/asignar: {"unidad_id", "mail", "porcentaje_comision"}. Solo el propietario.
    public function asignarAdministrador()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $comision = $this->validarComision($datos['porcentaje_comision'] ?? '');
        if ($comision === null) {
            return $this->error('La comisión tiene que ser un porcentaje mayor que 0 y menor que 100, con hasta 2 decimales');
        }
        $administrador = $this->cuentaActiva(trim(strtolower((string) ($datos['mail'] ?? ''))), 'administrador');
        if ($administrador === null) {
            return $this->error('No hay un administrador registrado con ese mail');
        }

        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //traba la fila de la unidad: dos asignaciones a la vez no pueden dejar dos administradores
            $pdo->prepare('SELECT id FROM unidades WHERE id = ? FOR UPDATE')->execute([$unidad['id']]);
            $consulta = $pdo->prepare('SELECT 1 FROM administraciones WHERE unidad_id = ?');
            $consulta->execute([$unidad['id']]);
            if ($consulta->fetchColumn()) {
                $pdo->rollBack();
                return $this->error('La unidad ya tiene administrador. Quitalo antes de asignar otro.', 409);
            }

            $pdo->prepare('INSERT INTO administraciones (unidad_id, administrador_id, porcentaje_comision) VALUES (?, ?, ?)')
                ->execute([$unidad['id'], $administrador['id'], $comision]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;//lo atrapa el router y responde 500
        }

        AvisoGestionMail::asignacion($administrador, $unidad['nombre'], $_SESSION['usuario']['nombre'], $comision);
        http_response_code(200);
        echo json_encode(['ok' => "Asignaste a {$administrador['nombre']} como administrador"]);
    }

    //POST /unidades/administrador/comision: {"unidad_id", "porcentaje_comision"}. Solo el propietario.
    //Vale para las liquidaciones futuras: cada liquidación guarda su propio % (Épica 9).
    public function cambiarComision()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $comision = $this->validarComision($datos['porcentaje_comision'] ?? '');
        if ($comision === null) {
            return $this->error('La comisión tiene que ser un porcentaje mayor que 0 y menor que 100, con hasta 2 decimales');
        }
        $administrador = $this->administradorDe((int) $unidad['id']);
        if ($administrador === null) {
            return $this->error('La unidad no tiene administrador', 409);
        }
        if ($administrador['porcentaje_comision'] === $comision) {//el mismo %: no hay nada que cambiar ni que avisar
            return $this->ok('La comisión ya era esa');
        }

        Conexion::obtener()->prepare('UPDATE administraciones SET porcentaje_comision = ? WHERE unidad_id = ?')
            ->execute([$comision, $unidad['id']]);

        AvisoGestionMail::cambioDeComision($administrador, $unidad['nombre'], $_SESSION['usuario']['nombre'], $comision);
        $this->ok('Cambiaste la comisión');
    }

    //POST /unidades/administrador/quitar: {"unidad_id"}. Solo el propietario. La unidad vuelve a autogestión.
    public function quitarAdministrador()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $administrador = $this->administradorDe((int) $unidad['id']);
        if ($administrador === null) {
            return $this->error('La unidad no tiene administrador', 409);
        }

        Conexion::obtener()->prepare('DELETE FROM administraciones WHERE unidad_id = ?')->execute([$unidad['id']]);

        AvisoGestionMail::desvinculacion($administrador, $unidad['nombre'], $_SESSION['usuario']['nombre']);
        $this->ok("Quitaste a {$administrador['nombre']}: la unidad vuelve a ser de autogestión");
    }

    //POST /unidades/operador/asignar: {"unidad_id", "mail"}. El propietario o el administrador de la unidad.
    //Un operador puede tener varias unidades; una unidad, como mucho un operador. El operador nunca ve montos (RN-19).
    public function asignarOperador()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietarioOAdministrador($datos['unidad_id'] ?? '');

        $operador = $this->cuentaActiva(trim(strtolower((string) ($datos['mail'] ?? ''))), 'operador');
        if ($operador === null) {
            return $this->error('No hay un operador registrado con ese mail');
        }

        //solo si la unidad todavía no tiene operador (en el mismo UPDATE: dos asignaciones a la vez no se pisan)
        $consulta = Conexion::obtener()->prepare('UPDATE unidades SET operador_id = ? WHERE id = ? AND operador_id IS NULL');
        $consulta->execute([$operador['id'], $unidad['id']]);
        if ($consulta->rowCount() === 0) {
            return $this->error('La unidad ya tiene operador. Quitalo antes de asignar otro.', 409);
        }

        $this->ok("Asignaste a {$operador['nombre']} como operador");
    }

    //POST /unidades/operador/quitar: {"unidad_id"}. El propietario o el administrador de la unidad.
    public function quitarOperador()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietarioOAdministrador($datos['unidad_id'] ?? '');

        $consulta = Conexion::obtener()->prepare('UPDATE unidades SET operador_id = NULL WHERE id = ? AND operador_id IS NOT NULL');
        $consulta->execute([$unidad['id']]);
        if ($consulta->rowCount() === 0) {
            return $this->error('La unidad no tiene operador', 409);
        }

        $this->ok('Quitaste al operador');
    }

    //La cuenta activa con ese mail y ese rol ({id, nombre, mail}), o null si no hay
    private function cuentaActiva(string $mail, string $rol): ?array
    {
        $consulta = Conexion::obtener()->prepare('SELECT id, nombre, mail FROM usuarios WHERE mail = ? AND rol = ? AND activo = 1');
        $consulta->execute([$mail, $rol]);
        $cuenta = $consulta->fetch();
        return $cuenta === false ? null : $cuenta;
    }

    //El administrador de la unidad ({id, nombre, mail, porcentaje_comision}), o null si es de autogestión
    private function administradorDe(int $unidadId): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT u.id, u.nombre, u.mail, a.porcentaje_comision
             FROM administraciones a
             JOIN usuarios u ON u.id = a.administrador_id
             WHERE a.unidad_id = ?'
        );
        $consulta->execute([$unidadId]);
        $administrador = $consulta->fetch();
        return $administrador === false ? null : $administrador;
    }

    //La comisión como texto con 2 decimales ("15.00"), o null si no es un número mayor que 0 y menor que 100
    //con hasta 2 decimales (RN-05). Acepta número (15 o 17.5) o texto ("17.50").
    private function validarComision(mixed $valor): ?string
    {
        if (!is_int($valor) && !is_float($valor) && !is_string($valor)) {
            return null;
        }
        $texto = trim((string) $valor);
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {//hasta 99.99: menor que 100
            return null;
        }
        return number_format((float) $texto, 2, '.', '');
    }

    //Responde 200 con el mensaje
    private function ok(string $mensaje)
    {
        http_response_code(200);
        echo json_encode(['ok' => $mensaje]);
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
