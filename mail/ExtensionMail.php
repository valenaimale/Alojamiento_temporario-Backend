<?php

namespace App\Mail;

use App\Config\Config;
use App\Log\Log;

//Avisa por mail que se confirmó (se pagó) una extensión de la estadía (US7.1, decisión #70): al huésped y al propietario.
//Regla: nunca lanza excepciones (si el mail falla, el pago ya quedó registrado y la respuesta no tiene que fallar).
class ExtensionMail
{
    //"Extendiste tu estadía en Cabaña del Mar". $ingreso y $egreso: las noches extra (AAAA-MM-DD).
    public static function confirmadaAlHuesped(array $huesped, string $unidad, string $ingreso, string $egreso, string $monto): void
    {
        self::avisar(
            $huesped,
            "Extendiste tu estadía en $unidad",
            'Recibimos el pago de la extensión (' . self::pesos($monto) . ") de tu estadía en $unidad: te quedás del "
                . self::dia($ingreso) . ' al ' . self::dia($egreso) . '. Tu nueva salida es el ' . self::dia($egreso) . '.'
                . ' La extensión ya está paga y no se puede cancelar.',
            '/vistas/homes/index-huesped.html',
            'Ver mis reservas'
        );
    }

    //"Un huésped extendió su estadía en Cabaña del Mar"
    public static function confirmadaAlPropietario(array $propietario, string $unidad, string $huesped, string $ingreso, string $egreso): void
    {
        self::avisar(
            $propietario,
            "Un huésped extendió su estadía en $unidad",
            "$huesped pagó una extensión de su estadía en $unidad, del " . self::dia($ingreso) . ' al ' . self::dia($egreso)
                . '. La nueva salida es el ' . self::dia($egreso) . '. Esas noches ya están ocupadas en el calendario de la unidad.',
            '/vistas/homes/index-propietario.html',
            'Ver mis alojamientos'
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
    private static function avisar(array $usuario, string $asunto, string $texto, string $pagina, string $boton): void
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
            Log::error('No se pudo enviar el aviso de la extensión', $e, ['usuario_id' => $usuario['id'] ?? null]);
        }
    }
}
