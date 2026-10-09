<?php

namespace App\Mail;

use App\Config\Config;
use App\Database\Conexion;

//Envía el mail con el enlace para verificar la casilla.
//Lo llaman el registro (RegistroController) y POST /reenviar-verificacion.
//Regla: nunca lanza excepciones (si el mail falla, el registro no tiene que fallar).
//En la base se guarda el HASH del token, nunca el token: lo único que puede abrir
//el enlace es quien recibió el mail.
class VerificacionMail
{
    private const HORAS_DE_VIGENCIA = 24;

    public static function enviar(int $usuarioId): void
    {
        try {
            $pdo = Conexion::obtener();

            $consulta = $pdo->prepare('SELECT nombre, mail, mail_verificado_en FROM usuarios WHERE id = ?');
            $consulta->execute([$usuarioId]);
            $usuario = $consulta->fetch();

            //si el usuario no existe o ya verificó su mail, no hay nada que mandar
            if (!$usuario || $usuario['mail_verificado_en'] !== null) {
                return;
            }

            //los enlaces anteriores que no se usaron dejan de servir: siempre vale el último que se mandó
            $borrado = $pdo->prepare('DELETE FROM verificaciones_mail WHERE usuario_id = ? AND usado_en IS NULL');
            $borrado->execute([$usuarioId]);

            $token = bin2hex(random_bytes(32));   //64 caracteres hexadecimales, generados con el azar del sistema operativo

            //el vencimiento lo calcula MySQL, así que no depende de la zona horaria de PHP
            $insercion = $pdo->prepare(
                'INSERT INTO verificaciones_mail (usuario_id, token_hash, expira_en)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . self::HORAS_DE_VIGENCIA . ' HOUR))'
            );
            $insercion->execute([$usuarioId, hash('sha256', $token)]);

            $enlace = Config::obtener('app.url_front') . '/vistas/sesion/verificar-mail.html?token=' . $token;

            self::enviarMail($usuario['mail'], $usuario['nombre'], $enlace);
        } catch (\Throwable $e) {
            //el mail no se pudo mandar (Mailpit apagado, SMTP mal configurado, etc.):
            //queda en la terminal de php -S y el usuario puede pedir otro desde el aviso de su home
            error_log($e);
        }
    }

    //Arma el cuerpo del mail (HTML y texto plano) y lo manda.
    //El nombre lo escribió el usuario, así que en el HTML va escapado con htmlspecialchars.
    private static function enviarMail(string $mail, string $nombre, string $enlace): void
    {
        $nombreEscapado = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $horas = self::HORAS_DE_VIGENCIA;

        $html = <<<HTML
            <p>¡Hola, {$nombreEscapado}!</p>
            <p>Creaste una cuenta en Alojamiento temporario. Para confirmar que este mail es tuyo, hacé clic en el botón:</p>
            <p><a href="{$enlace}" style="display:inline-block;padding:12px 20px;border-radius:6px;background-color:#1f6f54;color:#ffffff;font-weight:bold;text-decoration:none">Verificar mi mail</a></p>
            <p>Si el botón no funciona, copiá y pegá esta dirección en tu navegador:<br><a href="{$enlace}">{$enlace}</a></p>
            <p>El enlace vence en {$horas} horas. Si no creaste una cuenta, ignorá este mail.</p>
            HTML;

        $textoPlano = <<<TEXTO
            ¡Hola, {$nombre}!

            Creaste una cuenta en Alojamiento temporario. Para confirmar que este mail es tuyo, abrí esta dirección:

            {$enlace}

            El enlace vence en {$horas} horas. Si no creaste una cuenta, ignorá este mail.
            TEXTO;

        EnviadorMail::enviar($mail, $nombre, 'Verificá tu mail en Alojamiento temporario', $html, $textoPlano);
    }
}
