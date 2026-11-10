<?php

namespace App\Controllers;

use App\Autorizacion\PermisosUnidad;
use App\Database\Conexion;
use App\Request\Request;

//La política de cancelación y el % de seña de una unidad (US2.8). Es una sola por unidad (no cambia por temporada).
//La seña es el % del total que paga el huésped al reservar: de 1 a 100 (decisión #52 de docs/pagos-decisiones.md:
//el contrato no acepta el saldo de una reserva sin seña, así que con 0 % la reserva no se podría pagar).
//La política tiene 3 tramos fijos, medidos en días antes del cobro del saldo: más de 30, de 15 a 30 y menos de 15.
//Para cada tramo, el propietario elige qué % DE LA SEÑA retiene si el huésped cancela (corrección del profesor:
//antes era un % del total). Como se cancela antes del cobro del saldo, lo único pagado es la seña: el huésped
//nunca pierde más de lo que pagó.
//Regla: retención de más de 30 <= de 15 a 30 <= de menos de 15 (cuanto más cerca de la fecha, más se retiene).
//La base la garantiza con un CHECK; el backend la valida antes, para responder qué regla no se cumple.
//Los cambios valen para las reservas futuras: cada reserva guarda su propia copia (Épica 4). Solo del propietario.
class PoliticaController
{
    //La seña: un % del total, sin relación de orden con las retenciones
    private const SENA = ['porcentaje_sena' => 'La seña'];

    //La seña mínima (decisión #52): con 0 % el huésped no podría pagar el saldo con el contrato
    private const SENA_MINIMA = 1;

    //Las 3 retenciones (% de la seña), en el orden en que tienen que ir creciendo, con su nombre para los mensajes
    private const RETENCIONES = [
        'retencion_mas_de_30'   => 'La retención de más de 30 días',
        'retencion_de_15_a_30'  => 'La retención de 15 a 30 días',
        'retencion_menos_de_15' => 'La retención de menos de 15 días',
    ];

    //GET /unidades/politica?id=5: la seña, las 3 retenciones y cuántos días antes del ingreso se cobra el saldo
    //(el front lo usa para explicar desde cuándo se cuentan los tramos)
    public function ver()
    {
        $unidad = PermisosUnidad::delPropietario($_GET['id'] ?? '');//si no puede, responde y corta acá

        $consulta = Conexion::obtener()->prepare("SELECT valor FROM parametros WHERE clave = 'dias_cobro_saldo'");
        $consulta->execute();

        $politica = [];
        foreach (array_keys(self::SENA + self::RETENCIONES) as $campo) {
            $politica[$campo] = $unidad[$campo];//PDO los devuelve como texto con 2 decimales ("20.00")
        }
        $politica['dias_cobro_saldo'] = (int) $consulta->fetchColumn();

        http_response_code(200);
        echo json_encode(['politica' => $politica]);
    }

    //POST /unidades/politica: {"unidad_id", "porcentaje_sena", "retencion_mas_de_30", "retencion_de_15_a_30", "retencion_menos_de_15"}
    public function guardar()
    {
        $datos = Request::datosJson();
        $unidad = PermisosUnidad::delPropietario($datos['unidad_id'] ?? '');

        $porcentajes = [];
        foreach (self::SENA + self::RETENCIONES as $campo => $nombre) {
            $porcentaje = $this->validarPorcentaje($datos[$campo] ?? '');
            if ($porcentaje === null) {
                return $this->error("$nombre tiene que ser un porcentaje de 0 a 100, con hasta 2 decimales");
            }
            $porcentajes[$campo] = $porcentaje;
        }
        if ((float) $porcentajes['porcentaje_sena'] < self::SENA_MINIMA) {
            return $this->error('La seña tiene que ser de al menos el ' . self::SENA_MINIMA . ' %: sin seña, el huésped no podría pagar el saldo');
        }

        //cada retención tiene que ser menor o igual que la siguiente (la misma regla que el CHECK de la base)
        $campos = array_keys(self::RETENCIONES);
        for ($i = 0; $i < count($campos) - 1; $i++) {
            [$actual, $siguiente] = [$campos[$i], $campos[$i + 1]];
            if ((float) $porcentajes[$actual] > (float) $porcentajes[$siguiente]) {
                //"La retención de más de 30 días no puede ser mayor que la retención de 15 a 30 días"
                return $this->error(self::RETENCIONES[$actual] . ' no puede ser mayor que ' . lcfirst(self::RETENCIONES[$siguiente]));
            }
        }

        $consulta = Conexion::obtener()->prepare(
            'UPDATE unidades
             SET porcentaje_sena = ?, retencion_mas_de_30 = ?, retencion_de_15_a_30 = ?, retencion_menos_de_15 = ?
             WHERE id = ?'
        );
        $consulta->execute([...array_values($porcentajes), $unidad['id']]);

        http_response_code(200);
        echo json_encode(['ok' => 'Guardamos la política de cancelación y la seña', 'politica' => $porcentajes]);
    }

    //El porcentaje como texto con 2 decimales ("20.00"), o null si no es un número de 0 a 100 con hasta 2 decimales.
    //Acepta número (20 o 20.5) o texto ("20.50").
    private function validarPorcentaje(mixed $valor): ?string
    {
        if (!is_int($valor) && !is_float($valor) && !is_string($valor)) {
            return null;
        }
        $texto = trim((string) $valor);
        if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $texto) || (float) $texto > 100) {
            return null;
        }
        return number_format((float) $texto, 2, '.', '');
    }

    //Responde un error en JSON con su código HTTP
    private function error(string $mensaje, int $codigo = 422)
    {
        http_response_code($codigo);
        echo json_encode(['error' => $mensaje]);
    }
}
