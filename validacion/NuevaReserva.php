<?php

namespace App\Validacion;

use App\Database\Conexion;

//Guarda una reserva nueva, pendiente de la seña. La usan la reserva común (ReservasController::crear) y cada unidad
//de una reserva grupal (ReservaGrupal::crear), así las dos copian exactamente lo mismo de la unidad: el importe con
//su descuento, la seña, el saldo, la fecha de cobro y la política que el huésped acepta ahora.
class NuevaReserva
{
    //Inserta la reserva (dentro de la transacción de quien la llama, con la unidad ya trabada) y devuelve su id.
    //$unidad: la fila de la unidad con porcentaje_sena y la política; $estadia: Estadia::calcular ya disponible;
    //$sena: Estadia::sena del precio total; $ahora y $expiraEn en UTC; $grupoId: el grupo, si es parte de una grupal.
    public static function insertar(array $unidad, int $huespedId, \DateTimeImmutable $ingreso, \DateTimeImmutable $egreso,
                                    int $personas, array $estadia, string $sena, string $ahora, string $expiraEn,
                                    ?int $grupoId = null): int
    {
        $pdo = Conexion::obtener();
        $pdo->prepare(
            'INSERT INTO reservas (unidad_id, huesped_id, grupo_id, fecha_ingreso, fecha_egreso, cantidad_personas, estado,
                                   importe_sin_descuento, porcentaje_descuento,
                                   importe_total, porcentaje_sena, importe_sena, monto_faltante, fecha_cobro,
                                   retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15,
                                   politica_aceptada_en, bloqueo_expira_en, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, \'pendiente\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $unidad['id'],
            $huespedId,
            $grupoId,
            $ingreso->format('Y-m-d'),
            $egreso->format('Y-m-d'),
            $personas,
            $estadia['importe_sin_descuento'],//foto del descuento por estadía larga (#69)
            $estadia['porcentaje_descuento'],
            $estadia['precio_total'],//con el descuento: de acá salen la seña, el saldo y la retención
            $unidad['porcentaje_sena'],
            $sena,
            bcsub($estadia['precio_total'], $sena, 2),//el saldo
            Estadia::fechaCobro($ingreso)->format('Y-m-d'),
            $unidad['retencion_mas_de_30'],
            $unidad['retencion_de_15_a_30'],
            $unidad['retencion_menos_de_15'],
            $ahora,
            $expiraEn,
            $ahora,
        ]);
        return (int) $pdo->lastInsertId();
    }
}
