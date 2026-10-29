<?php

namespace App\Mail;

use App\Config\Config;
use App\Log\Log;

//Avisa por mail al administrador cuando un propietario lo asigna a una unidad, le cambia la comisión o lo quita (US2.2, US2.3).
//Regla: nunca lanza excepciones (si el mail falla, el cambio ya está hecho y no tiene que fallar).
class AvisoGestionMail
{
    //"Marisa te asignó la administración de Cabaña del Mar, con una comisión del 15 %"
    public static function asignacion(array $administrador, string $unidad, string $propietario, string $comision): void
    {
        self::avisar(
            $administrador,
            "Te asignaron la administración de $unidad",
            "$propietario te asignó la administración de $unidad, con una comisión del " . self::porcentaje($comision) . '. Ya la ves en tu cartera.',
            true
        );
    }

    //"Marisa cambió tu comisión en Cabaña del Mar: ahora es del 18 %"
    public static function cambioDeComision(array $administrador, string $unidad, string $propietario, string $comision): void
    {
        self::avisar(
            $administrador,
            "Cambió tu comisión en $unidad",
            "$propietario cambió tu comisión en $unidad: ahora es del " . self::porcentaje($comision) . '. Vale para las liquidaciones de las próximas estadías.',
            true
        );
    }

    //"Marisa dejó de tenerte como administrador de Cabaña del Mar"
    public static function desvinculacion(array $administrador, string $unidad, string $propietario): void
    {
        self::avisar(
            $administrador,
            "Ya no administrás $unidad",
            "$propietario dejó de tenerte como administrador de $unidad. Ya no aparece en tu cartera.",
            false
        );
    }

    //"15.00" → "15 %", "17.50" → "17,5 %"
    private static function porcentaje(string $valor): string
    {
        return str_replace('.', ',', (string) (float) $valor) . ' %';
    }

    //Arma el mail (HTML y texto plano) y lo manda; si falla, lo deja en el log.
    //$conEnlace: agrega el botón para ir a la cartera. Los nombres los escribieron personas: en el HTML van escapados.
    private static function avisar(array $administrador, string $asunto, string $texto, bool $conEnlace): void
    {
        try {
            $nombre = htmlspecialchars($administrador['nombre'], ENT_QUOTES, 'UTF-8');
            $textoHtml = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
            $enlace = Config::obtener('app.url_front') . '/vistas/homes/index-administrador.html';
            $boton = $conEnlace
                ? "<p><a href=\"{$enlace}\" style=\"display:inline-block;padding:12px 20px;border-radius:6px;background-color:#1f6f54;color:#ffffff;font-weight:bold;text-decoration:none\">Ver mi cartera</a></p>"
                : '';

            $html = "<p>¡Hola, {$nombre}!</p><p>{$textoHtml}</p>{$boton}";
            $textoPlano = "¡Hola, {$administrador['nombre']}!\n\n{$texto}" . ($conEnlace ? "\n\nTu cartera: {$enlace}" : '');

            EnviadorMail::enviar($administrador['mail'], $administrador['nombre'], $asunto, $html, $textoPlano);
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el aviso de gestión', $e, ['administrador_id' => $administrador['id'] ?? null]);
        }
    }
}
