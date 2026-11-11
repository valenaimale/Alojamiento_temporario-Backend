<?php

namespace App\Controllers;

use App\Autorizacion\Autorizacion;
use App\Database\Conexion;
use App\Request\Request;
use App\Validacion\Liquidacion;
use App\Validacion\PagoDeLiquidacion;
use App\Validacion\ValidadorFechas;
use App\Validacion\VerificadorBlockchain;

//Las liquidaciones de cada estadía: lo que cobra el propietario (RF-13), lo que cobra el administrador
//por su comisión (RF-09) y el comprobante sin valor fiscal de cada uno (RF-19, RN-25).
//La cuenta está en validacion/Liquidacion.php; acá están los permisos y la forma de la respuesta.
//
//Quién ve qué: el propietario ve el reparto completo de sus unidades, porque es su plata.
//El administrador ve su comisión y el nombre del propietario, pero no los datos fiscales ni el importe final
//del propietario: factura su comisión aparte y no necesita el resto.
class LiquidacionesController
{
    //GET /liquidaciones/mias: las liquidaciones de las unidades del propietario logueado, de la más nueva
    //a la más vieja, con los tres estados (pendiente, retenida por deuda y pagada).
    public function mias()
    {
        Autorizacion::requiere('propietario');

        $filas = $this->buscar('q.propietario_id = ?', [$_SESSION['usuario']['id']]);

        $liquidaciones = [];
        foreach ($filas as $fila) {
            $liquidaciones[] = [
                'id'                             => (int) $fila['id'],
                'estado'                         => $fila['estado'],
                'motivo'                         => $this->motivo($fila),
                'creado_en'                      => $this->momento($fila['creado_en']),
                'liquidada_en'                   => $this->momento($fila['liquidada_en']),
                'transaccion'                    => $this->transaccion($fila),
                'reserva'                        => $this->reservaDe($fila),
                'cobra_iva'                      => (bool) $fila['cobra_iva'],
                'importe_cobrado'                => $fila['importe_cobrado'],
                'porcentaje_iva'                 => $fila['porcentaje_iva'],
                'importe_iva'                    => $fila['importe_iva'],
                'base_comisiones'                => $fila['base_comisiones'],
                'porcentaje_comision_plataforma' => $fila['porcentaje_comision_plataforma'],
                'comision_plataforma'            => $fila['comision_plataforma'],
                'administrador'                  => $this->administradorDe($fila),
                'comision_administrador'         => $fila['comision_administrador'],
                'importe_propietario'            => $fila['importe_propietario'],
                'faltante'                       => $this->faltante($fila),
                'billetera'                      => $fila['propietario_billetera'],
                'faltan_billeteras'              => $this->faltanBilleteras($fila),
            ];
        }

        http_response_code(200);
        echo json_encode(['liquidaciones' => $liquidaciones]);
    }

    //GET /administrador/comisiones: lo que le corresponde al administrador logueado por cada estadía
    //de las unidades que administra (RF-09).
    public function comisiones()
    {
        Autorizacion::requiere('administrador');

        $filas = $this->buscar('q.administrador_id = ?', [$_SESSION['usuario']['id']]);

        $comisiones = [];
        foreach ($filas as $fila) {
            $comisiones[] = [
                'id'                => (int) $fila['id'],
                'estado'            => $fila['estado'],
                'motivo'            => $this->motivo($fila),
                'creado_en'         => $this->momento($fila['creado_en']),
                'liquidada_en'      => $this->momento($fila['liquidada_en']),
                'transaccion'       => $this->transaccion($fila),
                'reserva'           => $this->reservaDe($fila),
                'propietario'       => $fila['propietario'],
                'base_comisiones'   => $fila['base_comisiones'],
                'porcentaje'        => $fila['porcentaje_comision_administrador'],
                'comision'          => $fila['comision_administrador'],
                'faltante'          => $this->faltante($fila),
                'billetera'         => $fila['administrador_billetera'],
                'faltan_billeteras' => $this->faltanBilleteras($fila),
            ];
        }

        http_response_code(200);
        echo json_encode(['comisiones' => $comisiones]);
    }

    //GET /liquidaciones/comprobante?id=5: el comprobante SIN VALOR FISCAL de una liquidación (RN-25).
    //Lo pueden pedir el propietario de la unidad, el administrador de esa liquidación y el backoffice.
    //Según quién lo pide, trae el comprobante del propietario (el reparto completo) o el del administrador
    //(solo su comisión, con su condición fiscal, para facturarla aparte: RF-19).
    public function comprobante()
    {
        Autorizacion::requiereSesion();

        $id = filter_var(Request::parametroGet('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rol = $_SESSION['usuario']['rol'];
        $usuarioId = (int) $_SESSION['usuario']['id'];

        $fila = false;
        if ($id !== false) {
            //la liquidación de otro "no existe" (404), así nadie averigua importes ajenos probando números
            $condicion = match ($rol) {
                'propietario'   => 'q.id = ? AND q.propietario_id = ?',
                'administrador' => 'q.id = ? AND q.administrador_id = ?',
                'backoffice'    => 'q.id = ?',
                default         => null,
            };
            if ($condicion === null) {
                return $this->error('No tenés permiso para ver este comprobante', 403);
            }
            $parametros = $rol === 'backoffice' ? [$id] : [$id, $usuarioId];
            $fila = $this->buscar($condicion, $parametros)[0] ?? false;
        }
        if ($fila === false) {
            return $this->error('No encontramos ese comprobante', 404);
        }

        //el backoffice ve el del propietario, que es el que tiene todo el reparto
        $comprobante = $rol === 'administrador'
            ? $this->comprobanteDelAdministrador($fila)
            : $this->comprobanteDelPropietario($fila);

        http_response_code(200);
        echo json_encode(['comprobante' => $comprobante]);
    }

    //POST /liquidaciones/generar: calcula y guarda las liquidaciones de las estadías que todavía no se liquidaron.
    //Solo el backoffice: es la plataforma la que liquida, no el propietario.
    //Es idempotente: pedirla dos veces no duplica nada.
    public function generar()
    {
        Autorizacion::requiere('backoffice');

        $generadas = 0;
        foreach (Liquidacion::reservasSinLiquidar() as $reservaId) {
            if (Liquidacion::generar($reservaId) !== null) {
                $generadas++;
            }
        }

        http_response_code(200);
        echo json_encode([
            'ok'        => $generadas === 0 ? 'No había estadías sin liquidar' : "Se liquidaron $generadas estadías",
            'generadas' => $generadas,
        ]);
    }

    //POST /liquidaciones/pagar: {"liquidacion_id"}. El backoffice paga una liquidación: la plataforma llama a
    //liquidar() del contrato y cada uno recibe su parte en su billetera (PagoDeLiquidacion, decisiones #58 a #61).
    //Normalmente no hace falta: el cron de las tareas frecuentes paga solo las pendientes (scripts/pagar-liquidaciones.php).
    //No se paga una retenida por deuda (RN-16) ni una cuyo propietario o administrador no cargó su billetera (#44).
    public function pagar()
    {
        Autorizacion::requiere('backoffice');
        $datos = Request::datosJson();

        $id = is_bool($datos['liquidacion_id'] ?? null)
            ? false
            : filter_var($datos['liquidacion_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return $this->error('No encontramos esa liquidación', 404);
        }

        $resultado = PagoDeLiquidacion::pagar($id);
        if ($resultado['codigo'] >= 400) {
            return $this->error($resultado['mensaje'], $resultado['codigo']);
        }
        http_response_code($resultado['codigo']);
        echo json_encode(['ok' => $resultado['mensaje'], 'tx_hash' => $resultado['tx_hash']]);
    }

    //Quiénes de esta liquidación todavía no cargaron su billetera de cobro (#42 y #44 de docs/pagos-decisiones.md).
    //Mientras falte alguna, la liquidación queda pendiente: liquidar() del contrato le manda a cada uno su parte
    //a SU billetera, y no hay a dónde mandarla. El ETH sigue retenido en el contrato, que es lo seguro.
    private function faltanBilleteras(array $fila): array
    {
        return PagoDeLiquidacion::faltanBilleteras($fila);
    }

    //Por qué se liquida: 'estadia' (el huésped ingresó) o 'cancelacion' (lo que retuvo la política, decisión #57)
    private function motivo(array $fila): string
    {
        return $fila['reserva_estado'] === 'cancelada' ? 'cancelacion' : 'estadia';
    }

    //Cuánto falta cobrar (RN-16), o null. Una cancelada no debe nada: se liquida lo que se retuvo.
    private function faltante(array $fila): ?string
    {
        return Liquidacion::faltante($fila['importe_total'], $fila['importe_cobrado'], $fila['reserva_estado'] === 'cancelada');
    }

    //La transacción de liquidar() que la pagó, para verla en BaseScan, o null si todavía no salió.
    //'simulado': true si salió en la red de prueba local (no existe en ningún explorador).
    private function transaccion(array $fila): ?array
    {
        if ($fila['tx_hash'] === null) {
            return null;
        }
        return [
            'tx_hash'  => $fila['tx_hash'],
            'red'      => (int) $fila['tx_red'],
            'simulado' => (int) $fila['tx_red'] !== VerificadorBlockchain::RED_BASE_SEPOLIA,
        ];
    }

    //Las liquidaciones que cumplen la condición, con su reserva, su unidad y los datos fiscales de las partes,
    //de la más nueva a la más vieja.
    private function buscar(string $condicion, array $parametros): array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT q.id, q.reserva_id, q.estado, q.cobra_iva, q.creado_en, q.liquidada_en, q.administrador_id,
                    q.importe_cobrado, q.porcentaje_iva, q.importe_iva, q.base_comisiones,
                    q.porcentaje_comision_plataforma, q.comision_plataforma,
                    q.porcentaje_comision_administrador, q.comision_administrador, q.importe_propietario,
                    r.estado AS reserva_estado, r.fecha_ingreso, r.fecha_egreso, r.cantidad_personas, r.importe_total,
                    t.tx_hash, t.red AS tx_red,
                    u.id AS unidad_id, u.nombre AS unidad, l.nombre AS localidad, l.provincia,
                    h.nombre AS huesped,
                    pu.nombre AS propietario, pu.dni AS propietario_dni,
                    p.cuit AS propietario_cuit, p.razon_social AS propietario_razon_social,
                    p.domicilio_fiscal AS propietario_domicilio, p.billetera AS propietario_billetera,
                    au.nombre AS administrador, au.dni AS administrador_dni,
                    a.cobra_iva AS administrador_cobra_iva, a.cuit AS administrador_cuit,
                    a.razon_social AS administrador_razon_social, a.domicilio_fiscal AS administrador_domicilio,
                    a.billetera AS administrador_billetera
             FROM liquidaciones q
             JOIN reservas r ON r.id = q.reserva_id
             JOIN unidades u ON u.id = r.unidad_id
             JOIN localidades l ON l.id = u.localidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             JOIN usuarios pu ON pu.id = q.propietario_id
             JOIN propietarios p ON p.usuario_id = q.propietario_id
             LEFT JOIN usuarios au ON au.id = q.administrador_id
             LEFT JOIN administradores a ON a.usuario_id = q.administrador_id
             LEFT JOIN (SELECT reserva_id, MAX(tx_hash) AS tx_hash, MAX(red) AS red FROM pagos
                        WHERE tipo = 'liquidacion' AND estado = 'confirmado' GROUP BY reserva_id) t ON t.reserva_id = q.reserva_id
             WHERE $condicion
             ORDER BY q.creado_en DESC, q.id DESC"
        );
        $consulta->execute($parametros);
        return $consulta->fetchAll();
    }

    //El comprobante del propietario: todo lo que pidió el contador (RN-25)
    private function comprobanteDelPropietario(array $fila): array
    {
        return [
            'para'                           => 'propietario',
            'numero'                         => (int) $fila['id'],
            'estado'                         => $fila['estado'],
            'motivo'                         => $this->motivo($fila),
            'creado_en'                      => $this->momento($fila['creado_en']),
            'liquidada_en'                   => $this->momento($fila['liquidada_en']),
            'transaccion'                    => $this->transaccion($fila),
            'reserva'                        => $this->reservaDe($fila),
            'propietario'                    => [
                'nombre'           => $fila['propietario'],
                'dni'              => $fila['propietario_dni'],
                'cuit'             => $fila['propietario_cuit'],
                'razon_social'     => $fila['propietario_razon_social'],
                'domicilio_fiscal' => $fila['propietario_domicilio'],
                'cobra_iva'        => (bool) $fila['cobra_iva'],   //la condición fiscal del momento de liquidar
                'billetera'        => $fila['propietario_billetera'],   //null si todavía no la cargó (#44)
            ],
            'administrador'                  => $this->administradorDe($fila),
            'importe_cobrado'                => $fila['importe_cobrado'],
            'porcentaje_iva'                 => $fila['porcentaje_iva'],
            'importe_iva'                    => $fila['importe_iva'],
            'base_comisiones'                => $fila['base_comisiones'],
            'porcentaje_comision_plataforma' => $fila['porcentaje_comision_plataforma'],
            'comision_plataforma'            => $fila['comision_plataforma'],
            'comision_administrador'         => $fila['comision_administrador'],
            'importe_propietario'            => $fila['importe_propietario'],
            'faltante'                       => $this->faltante($fila),
            'faltan_billeteras'              => $this->faltanBilleteras($fila),
            'sin_valor_fiscal'               => true,
        ];
    }

    //El comprobante del administrador: solo su comisión, con SU condición fiscal, porque la factura aparte (RF-19)
    private function comprobanteDelAdministrador(array $fila): array
    {
        return [
            'para'              => 'administrador',
            'numero'            => (int) $fila['id'],
            'estado'            => $fila['estado'],
            'motivo'            => $this->motivo($fila),
            'creado_en'         => $this->momento($fila['creado_en']),
            'liquidada_en'      => $this->momento($fila['liquidada_en']),
            'transaccion'       => $this->transaccion($fila),
            'reserva'           => $this->reservaDe($fila),
            'propietario'       => ['nombre' => $fila['propietario']],
            'administrador'     => [
                'nombre'           => $fila['administrador'],
                'dni'              => $fila['administrador_dni'],
                'cuit'             => $fila['administrador_cuit'],
                'razon_social'     => $fila['administrador_razon_social'],
                'domicilio_fiscal' => $fila['administrador_domicilio'],
                'cobra_iva'        => (bool) $fila['administrador_cobra_iva'],
                'porcentaje'       => $fila['porcentaje_comision_administrador'],
                'billetera'        => $fila['administrador_billetera'],   //null si todavía no la cargó (#44)
            ],
            'base_comisiones'   => $fila['base_comisiones'],
            'comision'          => $fila['comision_administrador'],
            'faltante'          => $this->faltante($fila),
            'faltan_billeteras' => $this->faltanBilleteras($fila),
            'sin_valor_fiscal'  => true,
        ];
    }

    //Los datos de la reserva que liquida (los mismos en los listados y en el comprobante)
    private function reservaDe(array $fila): array
    {
        return [
            'id'            => (int) $fila['reserva_id'],
            'ingreso'       => $fila['fecha_ingreso'],
            'egreso'        => $fila['fecha_egreso'],
            'noches'        => ValidadorFechas::noches(ValidadorFechas::fecha($fila['fecha_ingreso']), ValidadorFechas::fecha($fila['fecha_egreso'])),
            'personas'      => (int) $fila['cantidad_personas'],
            'importe_total' => $fila['importe_total'],
            'huesped'       => $fila['huesped'],
            'unidad'        => [
                'id'        => (int) $fila['unidad_id'],
                'nombre'    => $fila['unidad'],
                'localidad' => $fila['localidad'],
                'provincia' => $fila['provincia'],
            ],
        ];
    }

    //El administrador de la liquidación con su comisión, o null si la unidad era de autogestión
    private function administradorDe(array $fila): ?array
    {
        if ($fila['administrador'] === null) {
            return null;
        }
        return [
            'nombre'     => $fila['administrador'],
            'cuit'       => $fila['administrador_cuit'],
            'cobra_iva'  => (bool) $fila['administrador_cobra_iva'],
            'porcentaje' => $fila['porcentaje_comision_administrador'],
            'billetera'  => $fila['administrador_billetera'],   //null si todavía no la cargó (#44)
        ];
    }

    //"2026-11-05 12:30:00" (como lo guarda la base, en UTC) → "2026-11-05T12:30:00Z"; null queda en null
    private function momento(?string $fecha): ?string
    {
        return $fecha === null ? null : str_replace(' ', 'T', $fecha) . 'Z';
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
