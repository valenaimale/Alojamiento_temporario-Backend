<?php

namespace App\Mail;

use App\Config\Config;
use App\Log\Log;
use App\Validacion\ValidadorFechas;
use App\Validacion\VencimientoReserva;

//Avisa por mail al huésped el resultado del pago de la seña (L2-28, L2-30) y del saldo (L2-6, RF-26),
//y la cancelación con su devolución, al huésped y al propietario (L2-3, L2-18).
//Regla: nunca lanza excepciones (si el mail falla, el pago ya quedó registrado y la respuesta no tiene que fallar).
class AvisoPagoMail
{
    //"Tu reserva en Cabaña del Mar está confirmada"
    //$saldo: lo que falta pagar ($saldo['monto'], 'fecha_cobro', 'limite' y 'retencion', el % de la seña del tramo de
    //menos de 15 días), o null si no queda saldo. Decisión #23: se le avisa desde la seña hasta cuándo puede pagarlo
    //y qué pasa si no lo paga.
    public static function senaConfirmada(array $huesped, string $unidad, string $ingreso, string $egreso, string $monto, ?array $saldo = null): void
    {
        $texto = 'Recibimos el pago de la seña ($' . number_format((float) $monto, 2, ',', '.') . ") de tu estadía en $unidad, del "
            . self::dia($ingreso) . ' al ' . self::dia($egreso) . '. Tu reserva está confirmada.';
        if ($saldo !== null) {
            $texto .= ' Te queda pagar el saldo de ' . self::pesos($saldo['monto']) . '. Lo podés pagar desde ya, desde tus reservas;'
                . ' se cobra a partir del ' . self::dia($saldo['fecha_cobro']) . ' y tenés tiempo hasta el ' . self::dia($saldo['limite']) . ' inclusive.'
                . ' Si no lo pagás para esa fecha, la reserva se cancela sola: según la política de cancelación que aceptaste, se retiene el '
                . self::porcentaje($saldo['retencion']) . ' de la seña y se te devuelve el resto.';
        }
        self::avisar($huesped, "Tu reserva en $unidad está confirmada", $texto);
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
    //$limite: el último día para pagarlo (decisión #23), o null si no se sabe.
    public static function saldoFallido(array $huesped, string $unidad, string $motivo, ?string $limite): void
    {
        $plazo = $limite === null
            ? ' Podés volver a pagarlo desde tus reservas.'
            : ' Podés volver a pagarlo hasta el ' . self::dia($limite) . ' inclusive: si no, la reserva se cancela sola.';
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

    //"Cancelaste tu reserva en Cabaña del Mar" (L2-3). $devolucion: lo que se le devuelve (puede ser "0.00");
    //$enCamino: si la devolución todavía no salió (la red falló y queda para reintentar).
    //$limiteSaldo: si es la cancelación automática por el saldo impago (decisión #23), el último día que tenía para pagarlo.
    public static function cancelacionAlHuesped(array $huesped, string $unidad, string $devolucion, string $retencion, bool $enCamino, ?string $limiteSaldo = null): void
    {
        $asunto = $limiteSaldo === null ? "Cancelaste tu reserva en $unidad" : "Se canceló tu reserva en $unidad por falta de pago del saldo";
        $texto = $limiteSaldo === null
            ? "Cancelaste tu reserva en $unidad. "
            : "Tu reserva en $unidad se canceló porque el saldo no se pagó hasta el " . self::dia($limiteSaldo) . ', como te avisamos. ';
        if (bccomp($devolucion, '0', 2) <= 0) {
            $texto .= 'Según la política de cancelación que aceptaste, no corresponde devolución.';
        } else {
            $texto .= 'Según la política de cancelación que aceptaste, se retienen ' . self::pesos($retencion)
                . ' y te devolvemos ' . self::pesos($devolucion) . ' a la billetera con la que pagaste.'
                . ($enCamino ? ' La devolución está en proceso: puede tardar un poco en llegarte.' : '');
        }
        self::avisar($huesped, $asunto, $texto);
    }

    //"Un huésped canceló su reserva en Cabaña del Mar" (L2-18: se le avisa al propietario antes de devolver)
    //$porSaldoVencido: si la canceló la plataforma porque el huésped no pagó el saldo a tiempo (decisión #23)
    public static function cancelacionAlPropietario(array $propietario, string $unidad, string $ingreso, string $egreso, string $retencion, string $devolucion, bool $porSaldoVencido = false): void
    {
        self::avisar(
            $propietario,
            $porSaldoVencido ? "Se canceló una reserva en $unidad por falta de pago del saldo" : "Un huésped canceló su reserva en $unidad",
            ($porSaldoVencido ? 'El huésped no pagó el saldo a tiempo. ' : '') . "Se canceló la reserva de $unidad del " . self::dia($ingreso) . ' al ' . self::dia($egreso)
                . '. Esas noches quedaron libres. Según la política de cancelación, se retienen ' . self::pesos($retencion)
                . ' y se le devuelven ' . self::pesos($devolucion) . ' al huésped.'
                //decisión #64: el propietario tiene 48 hs para devolverle parte de lo retenido
                . (bccomp($retencion, '0', 2) > 0
                    ? ' Si querés, en las próximas 48 hs podés devolverle al huésped parte o todo lo que te toca de lo retenido, desde las reservas de tu unidad.'
                    : ''),
            '/vistas/homes/index-propietario.html',
            'Ver mis alojamientos'
        );
    }

    //"El propietario canceló tu reserva en Cabaña del Mar" (decisión #63): se le devuelve todo lo que pagó.
    //$enCamino: si la devolución todavía no salió (la red falló y queda para reintentar).
    public static function cancelacionDelPropietario(array $huesped, string $unidad, string $ingreso, string $egreso, string $motivo, string $devolucion, bool $enCamino): void
    {
        $texto = "El propietario de $unidad canceló tu reserva del " . self::dia($ingreso) . ' al ' . self::dia($egreso) . ". Motivo: $motivo. ";
        $texto .= bccomp($devolucion, '0', 2) > 0
            ? 'Te devolvemos todo lo que pagaste, ' . self::pesos($devolucion) . ', a la billetera con la que pagaste.'
                . ($enCamino ? ' La devolución está en proceso: puede tardar un poco en llegarte.' : '')
            : 'Todavía no habías pagado nada, así que no hay nada que devolverte.';
        self::avisar($huesped, "El propietario canceló tu reserva en $unidad", $texto . ' Lamentamos las molestias.');
    }

    //"Te devolvemos $X más por tu reserva cancelada en Cabaña del Mar" (decisión #64): la excepción del propietario.
    //Le llega con la liquidación de la reserva, en los próximos minutos.
    public static function excepcionAlHuesped(array $huesped, string $unidad, string $monto): void
    {
        self::avisar(
            $huesped,
            "Te devolvemos " . self::pesos($monto) . " más por tu reserva cancelada en $unidad",
            "El propietario de $unidad decidió devolverte " . self::pesos($monto) . ' de lo que se había retenido por tu cancelación.'
                . ' Te llega a la billetera con la que pagaste en los próximos minutos.'
        );
    }

    //"50.00" → "50 %", "12.50" → "12,5 %"
    private static function porcentaje(string $valor): string
    {
        return rtrim(rtrim(number_format((float) $valor, 2, ',', ''), '0'), ',') . ' %';
    }

    //"42000.00" → "$42.000,00"
    private static function pesos(string $monto): string
    {
        return '$' . number_format((float) $monto, 2, ',', '.');
    }

    //Arma el mail (HTML y texto plano) y lo manda; si falla, lo deja en el log.
    //Los nombres los escribieron personas: en el HTML van escapados.
    //$pagina y $boton: a dónde lleva el botón (por defecto, a las reservas del huésped).
    private static function avisar(array $usuario, string $asunto, string $texto, string $pagina = '/vistas/homes/index-huesped.html', string $boton = 'Ver mis reservas'): void
    {
        try {
            $nombre = htmlspecialchars($usuario['nombre'], ENT_QUOTES, 'UTF-8');
            $textoHtml = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
            $enlace = Config::obtener('app.url_front') . $pagina;
            $botonHtml = "<p><a href=\"{$enlace}\" style=\"display:inline-block;padding:12px 20px;border-radius:6px;background-color:#1f6f54;color:#ffffff;font-weight:bold;text-decoration:none\">{$boton}</a></p>";

            $html = "<p>¡Hola, {$nombre}!</p><p>{$textoHtml}</p>{$botonHtml}";
            $textoPlano = "¡Hola, {$usuario['nombre']}!\n\n{$texto}\n\n{$boton}: {$enlace}";

            EnviadorMail::enviar($usuario['mail'], $usuario['nombre'], $asunto, $html, $textoPlano);
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el aviso de pago', $e, ['usuario_id' => $usuario['id'] ?? null]);
        }
    }
}
