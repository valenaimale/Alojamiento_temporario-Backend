<?php

namespace App\Mail;

use App\Config\Config;
use PHPMailer\PHPMailer\PHPMailer;

//Único lugar que manda mails: arma el PHPMailer con los datos de smtp.* de config/configuracion.php.
//En desarrollo esos datos apuntan a Mailpit (127.0.0.1:1025), que atrapa todos los mails
//y los muestra en http://localhost:8025. En producción se cambian por los de un proveedor real,
//sin tocar este código.
//Si el envío falla, LANZA la excepción: quien llama decide qué hacer
//(VerificacionMail la atrapa, porque el registro no tiene que fallar por un mail).
class EnviadorMail
{
    public static function enviar(string $para, string $nombreDestinatario, string $asunto, string $html, string $textoPlano): void
    {
        $mailer = new PHPMailer(true);   //true: lanza excepciones en lugar de devolver false

        $mailer->isSMTP();
        $mailer->Host    = Config::obtener('smtp.host');
        $mailer->Port    = (int) Config::obtener('smtp.port');
        $mailer->CharSet = 'UTF-8';     //para que las tildes y la ñ lleguen bien

        $usuario = Config::obtener('smtp.usuario');
        if ($usuario !== '') {          //Mailpit no pide usuario ni contraseña; un proveedor real sí
            $mailer->SMTPAuth = true;
            $mailer->Username = $usuario;
            $mailer->Password = Config::obtener('smtp.contrasenia');
        }

        $cifrado = Config::obtener('smtp.cifrado');
        if ($cifrado !== '') {          //'tls' o 'ssl'; vacío en desarrollo
            $mailer->SMTPSecure = $cifrado;
        }

        $mailer->setFrom(Config::obtener('smtp.remitente'), Config::obtener('smtp.nombre_remitente'));
        $mailer->addAddress($para, $nombreDestinatario);

        $mailer->isHTML(true);
        $mailer->Subject = $asunto;
        $mailer->Body    = $html;
        $mailer->AltBody = $textoPlano;   //la ve quien tenga el cliente de mail sin HTML

        $mailer->send();
    }
}
