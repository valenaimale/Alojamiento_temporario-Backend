<?php

namespace App\Mail;

use App\Config\Config;
use PHPMailer\PHPMailer\PHPMailer;

//Único lugar que manda mails. Hay dos formas de enviar, según mail.transporte (variable MAIL_TRANSPORTE):
//  - 'smtp' (por defecto): PHPMailer con los datos de smtp.* de config/configuracion.php. En desarrollo apuntan
//    a Mailpit (127.0.0.1:1025), que atrapa los mails y los muestra en http://localhost:8025. Lo usa también el CI.
//  - 'brevo': la API HTTPS de Brevo (https://api.brevo.com/v3/smtp/email), con la clave de brevo.api_key
//    (variable BREVO_API_KEY). Es la de producción: Railway bloquea el SMTP saliente en el plan Hobby
//    (puertos 25, 465, 587 y 2525), pero deja salir HTTPS.
//El remitente es el mismo en los dos casos: smtp.remitente y smtp.nombre_remitente (en Brevo tiene que estar verificado).
//Si el envío falla, LANZA la excepción: quien llama decide qué hacer
//(VerificacionMail la atrapa, porque el registro no tiene que fallar por un mail).
class EnviadorMail
{
    private const URL_BREVO = 'https://api.brevo.com/v3/smtp/email';
    private const SEGUNDOS_DE_ESPERA = 10;   //si Brevo no responde en ese tiempo, se corta: el usuario no puede quedar esperando

    //Manda un mail (con versión HTML y de texto plano) por el transporte configurado
    public static function enviar(string $para, string $nombreDestinatario, string $asunto, string $html, string $textoPlano): void
    {
        $transporte = Config::obtener('mail.transporte', 'smtp');//por defecto smtp: los configuracion.php viejos no tienen la clave
        match ($transporte) {
            'smtp'  => self::enviarPorSmtp($para, $nombreDestinatario, $asunto, $html, $textoPlano),
            'brevo' => self::enviarPorBrevo(self::pedidoBrevo($para, $nombreDestinatario, $asunto, $html, $textoPlano)),
            default => throw new \RuntimeException("Transporte de mail desconocido: '$transporte' (tiene que ser smtp o brevo)"),
        };
    }

    //El cuerpo del pedido a la API de Brevo (lo que se manda como JSON). Separado del envío para poder probarlo sin mandar nada.
    public static function pedidoBrevo(string $para, string $nombreDestinatario, string $asunto, string $html, string $textoPlano): array
    {
        return [
            'sender'      => ['email' => Config::obtener('smtp.remitente'), 'name' => Config::obtener('smtp.nombre_remitente')],
            'to'          => [['email' => $para, 'name' => $nombreDestinatario]],
            'subject'     => $asunto,
            'htmlContent' => $html,
            'textContent' => $textoPlano,//lo ve quien tenga el cliente de mail sin HTML
        ];
    }

    //Manda el pedido a la API de Brevo. Brevo responde 201 si lo aceptó; cualquier otra respuesta es un error.
    //El mensaje de la excepción nunca incluye la clave.
    private static function enviarPorBrevo(array $pedido): void
    {
        $clave = (string) Config::obtener('brevo.api_key', '');
        if ($clave === '') {
            throw new \RuntimeException('Falta la clave de Brevo (variable BREVO_API_KEY)');
        }

        $curl = curl_init(self::URL_BREVO);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($pedido, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', "api-key: $clave"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::SEGUNDOS_DE_ESPERA,
        ]);
        $respuesta = curl_exec($curl);
        $codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errorDeRed = curl_error($curl);

        if ($respuesta === false) {
            throw new \RuntimeException("No se pudo conectar con Brevo: $errorDeRed");
        }
        if ($codigo !== 201) {
            //Brevo explica el error en {"code": ..., "message": ...}; por ejemplo, un remitente sin verificar
            $mensaje = json_decode((string) $respuesta, true)['message'] ?? $respuesta;
            throw new \RuntimeException("Brevo rechazó el mail (HTTP $codigo): $mensaje");
        }
    }

    //Manda el mail por SMTP con PHPMailer (desarrollo con Mailpit, y el CI)
    private static function enviarPorSmtp(string $para, string $nombreDestinatario, string $asunto, string $html, string $textoPlano): void
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
        $mailer->AltBody = $textoPlano;

        $mailer->send();
    }
}
