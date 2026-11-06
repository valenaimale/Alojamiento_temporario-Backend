<?php

namespace App\Mail;

use App\Config\Config;
use App\Log\Log;
use App\Validacion\ValidadorFechas;
use App\Validacion\VencimientoReserva;

//Avisa por mail al huésped el resultado del pago de la seña (L2-28, L2-30) y del saldo (L2-6, RF-26).
//Regla: nunca lanza excepciones (si el mail falla, el pago ya quedó registrado y la respuesta no tiene que fallar).
class AvisoPagoMail
{
    //"Tu reserva en Cabaña del Mar está confirmada"
    public static function senaConfirmada(array $huesped, string $unidad, string $ingreso, string $egreso, string $monto): void
    {
        self::avisar(
            $huesped,
            "Tu reserva en $unidad está confirmada",
            'Recibimos el pago de la seña ($' . number_format((float) $monto, 2, ',', '.') . ") de tu estadía en $unidad, del "
                . self::dia($ingreso) . ' al ' . self::dia($egreso) . '. Tu reserva está confirmada.'
        );
    }

    //"No pudimos confirmar el pago de la seña de tu reserva en Cabaña del Mar"
    //$venceEn: hasta cuándo puede volver a pagar (UTC), o null si la reserva no vence.
    public static function senaFallida(array $huesped, string $unidad, string $motivo, ?string $venceEn): void
    {
        $plazo = $venceEn === null
            ? ' Podés volver a pagarla desde tus reservas.'
            : ' Podés volver a pagarla hasta las ' . VencimientoReserva::horaArgentina($venceEn) . ': después las fechas se liberan.';
        self::avisar(
            $huesped,
            "No pudimos confirmar el pago de la seña de tu reserva en $unidad",
            "El pago de la seña de tu reserva en $unidad no se pudo confirmar: $motivo.$plazo"
        );
    }

    //"Recibimos el pago del saldo de tu reserva en Cabaña del Mar"
    public static function saldoConfirmado(array $huesped, string $unidad, string $ingreso, string $monto): void
    {
        self::avisar(
            $huesped,
            "Recibimos el pago del saldo de tu reserva en $unidad",
            'Recibimos el pago del saldo ($' . number_format((float) $monto, 2, ',', '.') . ") de tu estadía en $unidad. "
                . 'Tu reserva está paga: te esperamos el ' . self::dia($ingreso) . '.'
        );
    }

    //"No pudimos confirmar el pago del saldo de tu reserva en Cabaña del Mar" (RF-26)
    //$fechaCobro: hasta cuándo tenía que pagarlo, o null si no se sabe.
    public static function saldoFallido(array $huesped, string $unidad, string $motivo, ?string $fechaCobro): void
    {
        $plazo = match (true) {
            $fechaCobro === null => ' Podés volver a pagarlo desde tus reservas.',
            $fechaCobro < ValidadorFechas::hoy()->format('Y-m-d') => ' El plazo para pagarlo venció el ' . self::dia($fechaCobro) . ': pagalo antes de ingresar.',
            default => ' Podés volver a pagarlo hasta el ' . self::dia($fechaCobro) . '.',
        };
        self::avisar(
            $huesped,
            "No pudimos confirmar el pago del saldo de tu reserva en $unidad",
            "El pago del saldo de tu reserva en $unidad no se pudo confirmar: $motivo.$plazo"
        );
    }

    //"2026-12-05" → "05/12/2026"
    private static function dia(string $fecha): string
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha)->format('d/m/Y');
    }

    //Arma el mail (HTML y texto plano) y lo manda; si falla, lo deja en el log.
    //Los nombres los escribieron personas: en el HTML van escapados.
    private static function avisar(array $huesped, string $asunto, string $texto): void
    {
        try {
            $nombre = htmlspecialchars($huesped['nombre'], ENT_QUOTES, 'UTF-8');
            $textoHtml = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
            $enlace = Config::obtener('app.url_front') . '/vistas/homes/index-huesped.html';
            $boton = "<p><a href=\"{$enlace}\" style=\"display:inline-block;padding:12px 20px;border-radius:6px;background-color:#1f6f54;color:#ffffff;font-weight:bold;text-decoration:none\">Ver mis reservas</a></p>";

            $html = "<p>¡Hola, {$nombre}!</p><p>{$textoHtml}</p>{$boton}";
            $textoPlano = "¡Hola, {$huesped['nombre']}!\n\n{$texto}\n\nTus reservas: {$enlace}";

            EnviadorMail::enviar($huesped['mail'], $huesped['nombre'], $asunto, $html, $textoPlano);
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el aviso de pago', $e, ['usuario_id' => $huesped['id'] ?? null]);
        }
    }
}
