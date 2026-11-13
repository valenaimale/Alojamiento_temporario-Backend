<?php

namespace App\Mail;

use App\Config\Config;
use App\Log\Log;
use App\Validacion\Liquidacion;
use App\Validacion\PlazoIngreso;

//Los avisos del no-show (decisión #67 de docs/pagos-decisiones.md): cuando pasan las 24 hs del horario de check-in
//sin que nadie registre el ingreso, la reserva pasa sola a 'no_show' (App\Validacion\NoShow) y se les avisa a los dos:
//  - al huésped, que no se presentó y que se retuvo todo lo que pagó;
//  - al propietario, que las noches quedaron libres y que tiene 48 hs para darle una excepción, sin obligación (#64).
//También avisa la excepción de un no-show, porque los mails de las canceladas van en AvisoPagoMail.
//Regla, como en los demás mails: nunca lanza excepciones (si el mail falla, la reserva ya quedó marcada y el script
//no tiene que fallar por eso; queda en el log).
class NoShowMail
{
    //"No te presentaste en Cabaña del Mar"
    //$retenido: todo lo que había pagado, que queda retenido (puede ser "0.00" si no se cobró nada).
    public static function alHuesped(array $huesped, string $unidad, string $ingreso, string $egreso, string $retenido): void
    {
        $texto = "Pasaron las " . PlazoIngreso::HORAS . " horas desde el horario de check-in y nadie registró tu ingreso en $unidad,"
            . ' para la estadía del ' . self::dia($ingreso) . ' al ' . self::dia($egreso) . '. La reserva quedó cerrada como "no se presentó"'
            . ' y esas noches volvieron a estar disponibles.';
        $texto .= bccomp($retenido, '0', 2) > 0
            ? ' Según la política que aceptaste, se retiene todo lo que pagaste: ' . self::pesos($retenido) . '.'
                . ' El propietario puede, si quiere, devolverte parte de lo retenido en las próximas '
                . Liquidacion::HORAS_EXCEPCION . ' horas; si lo hace, te avisamos.'
            : ' No habías pagado nada, así que no hay nada retenido.';
        $texto .= ' Si creés que hubo un error, escribile al propietario o al operador de la unidad.';

        self::avisar($huesped, "No te presentaste en $unidad", $texto);
    }

    //"El huésped no se presentó en Cabaña del Mar"
    //$retenido: todo lo que pagó el huésped; $maximo: cuánto de eso le puede devolver el propietario (#65).
    public static function alPropietario(
        array $propietario,
        string $unidad,
        string $huesped,
        string $ingreso,
        string $egreso,
        string $retenido,
        string $maximo
    ): void {
        $texto = "Pasaron las " . PlazoIngreso::HORAS . " horas desde el horario de check-in y nadie registró el ingreso de $huesped"
            . " en $unidad, para la estadía del " . self::dia($ingreso) . ' al ' . self::dia($egreso) . '.'
            . ' La reserva quedó cerrada como "no se presentó" y esas noches volvieron a estar disponibles.';
        $texto .= bccomp($retenido, '0', 2) > 0
            ? ' Se retiene todo lo que había pagado, ' . self::pesos($retenido) . ', y se liquida como una cancelación:'
                . ' a vos te tocan ' . self::pesos($maximo) . '.'
                . ' Si querés, en las próximas ' . Liquidacion::HORAS_EXCEPCION . ' horas podés devolverle al huésped parte o todo eso,'
                . ' desde las reservas de tu unidad. No es obligatorio: si no hacés nada, se liquida tal cual.'
            : ' No había pagado nada, así que no hay nada para liquidar.';

        self::avisar($propietario, "El huésped no se presentó en $unidad", $texto,
            '/vistas/homes/index-propietario.html', 'Ver mis alojamientos');
    }

    //"Te devolvemos $X por tu reserva en Cabaña del Mar" (#64 y #65): la excepción del propietario a un no-show.
    //Le llega con la liquidación de la reserva, en los próximos minutos.
    public static function excepcionAlHuesped(array $huesped, string $unidad, string $monto): void
    {
        self::avisar(
            $huesped,
            'Te devolvemos ' . self::pesos($monto) . " por tu reserva en $unidad",
            "El propietario de $unidad decidió devolverte " . self::pesos($monto) . ' de lo que se había retenido porque no te presentaste.'
                . ' Te llega a la billetera con la que pagaste en los próximos minutos.'
        );
    }

    //"2026-12-05" → "05/12/2026"
    private static function dia(string $fecha): string
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha)->format('d/m/Y');
    }

    //"42000.00" → "$42.000,00"
    private static function pesos(string $monto): string
    {
        return '$' . number_format((float) $monto, 2, ',', '.');
    }

    //Arma el mail (HTML y texto plano) y lo manda; si falla, lo deja en el log.
    //Los nombres los escribieron personas: en el HTML van escapados.
    //$pagina y $boton: a dónde lleva el botón (por defecto, a las reservas del huésped).
    private static function avisar(
        array $usuario,
        string $asunto,
        string $texto,
        string $pagina = '/vistas/homes/index-huesped.html',
        string $boton = 'Ver mis reservas'
    ): void {
        try {
            $nombre = htmlspecialchars($usuario['nombre'], ENT_QUOTES, 'UTF-8');
            $textoHtml = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
            $enlace = Config::obtener('app.url_front') . $pagina;
            $botonHtml = "<p><a href=\"{$enlace}\" style=\"display:inline-block;padding:12px 20px;border-radius:6px;background-color:#1f6f54;color:#ffffff;font-weight:bold;text-decoration:none\">{$boton}</a></p>";

            $html = "<p>¡Hola, {$nombre}!</p><p>{$textoHtml}</p>{$botonHtml}";
            $textoPlano = "¡Hola, {$usuario['nombre']}!\n\n{$texto}\n\n{$boton}: {$enlace}";

            EnviadorMail::enviar($usuario['mail'], $usuario['nombre'], $asunto, $html, $textoPlano);
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el aviso de no-show', $e, ['usuario_id' => $usuario['id'] ?? null]);
        }
    }
}
