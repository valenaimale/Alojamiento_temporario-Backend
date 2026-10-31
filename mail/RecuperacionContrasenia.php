<?php

namespace App\Mail;

use App\Config\Config;
use App\Database\Conexion;
use App\Log\Log;

final class RecuperacionContrasenia
{
    // Se ejecuta después de cerrar la respuesta HTTP para que SMTP y la existencia de la cuenta
    // no cambien la respuesta pública. Usa el mismo transporte SMTP/Brevo del resto de la app.
    public static function enviarEnlace(string $mail): void
    {
        $pdo = Conexion::obtener();
        try {
            $pdo->beginTransaction();
            $consulta = $pdo->prepare('SELECT id, nombre, mail, activo, contrasenia FROM usuarios WHERE mail = ? FOR UPDATE');
            $consulta->execute([$mail]);
            $usuario = $consulta->fetch();
            if (!$usuario || !(int) $usuario['activo'] || $usuario['contrasenia'] === null) {
                $pdo->commit();
                return;
            }
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('DELETE FROM recuperaciones_contrasenia WHERE usuario_id = ?')->execute([$usuario['id']]);
            $pdo->prepare('INSERT INTO recuperaciones_contrasenia (usuario_id, token_hash, expira_en, creado_en)
                VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 30 MINUTE, UTC_TIMESTAMP())')
                ->execute([$usuario['id'], hash('sha256', $token)]);
            $pdo->commit();

            // El fragmento no viaja en requests ni encabezados Referer. Nunca usar el Host de la petición.
            $enlace = rtrim(Config::obtener('app.url_front'), '/') . '/vistas/sesion/restablecer-contrasenia.html#token=' . $token;
            $nombre = htmlspecialchars($usuario['nombre'], ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');
            $html = "<p>Hola, {$nombre}.</p><p>Recibimos una solicitud para cambiar tu contraseña de HayLugar.</p>"
                . "<p><a href=\"{$url}\">Crear una nueva contraseña</a></p>"
                . '<p>El enlace vence en 30 minutos y se puede usar una sola vez. Si pediste varios, usá el último.</p>'
                . '<p>Si no lo solicitaste, ignorá este mail. Tu contraseña sigue igual.</p>';
            $texto = "Para cambiar tu contraseña de HayLugar, abrí este enlace:\n\n{$enlace}\n\n"
                . 'Vence en 30 minutos y se usa una sola vez. Si pediste varios, usá el último. Si no lo solicitaste, ignorá este mail. Tu contraseña sigue igual.';
            EnviadorMail::enviar($usuario['mail'], $usuario['nombre'], 'Recuperá tu cuenta de HayLugar', $html, $texto);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // No registrar la excepción del proveedor: podría contener el mensaje con el enlace secreto.
            Log::error('No se pudo enviar el mail de recuperación');
        }
    }

    public static function notificarCambio(array $usuario): void
    {
        try {
            $texto = 'Se cambió la contraseña de tu cuenta de HayLugar y se cerraron las sesiones anteriores. '
                . 'Si no lo hiciste vos, entrá a HayLugar y usá «Olvidé mi contraseña» para recuperar el acceso.';
            EnviadorMail::enviar($usuario['mail'], $usuario['nombre'], 'Tu contraseña de HayLugar cambió', '<p>' . $texto . '</p>', $texto);
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el aviso de cambio de contraseña');
        }
    }
}
