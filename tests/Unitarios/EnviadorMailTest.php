<?php

namespace Tests\Unitarios;

use App\Config\Config;
use App\Mail\EnviadorMail;
use PHPUnit\Framework\TestCase;

//El envío de mails por la API de Brevo (producción) sin mandar nada de verdad: cómo se arma el pedido
//y qué pasa si falta la configuración. El envío por SMTP lo prueban los tests de integración con Mailpit.
final class EnviadorMailTest extends TestCase
{
    //Después de cada test borra las variables de entorno, para no afectar a los demás
    protected function tearDown(): void
    {
        foreach (['SMTP_REMITENTE', 'SMTP_NOMBRE_REMITENTE', 'MAIL_TRANSPORTE', 'BREVO_API_KEY'] as $variable) {
            putenv($variable);//sin "=": borra la variable de entorno
        }
    }

    //El pedido a Brevo lleva el remitente de la configuración, el destinatario, el asunto y las dos versiones del mail
    public function testArmaElPedidoParaBrevo(): void
    {
        putenv('SMTP_REMITENTE=reservas@alojamiento.test');
        putenv('SMTP_NOMBRE_REMITENTE=Alojamiento temporario');

        $pedido = EnviadorMail::pedidoBrevo('juan@ejemplo.local', 'Juan Pérez', 'Verificá tu mail', '<p>Hola</p>', 'Hola');

        $this->assertSame([
            'sender'      => ['email' => 'reservas@alojamiento.test', 'name' => 'Alojamiento temporario'],
            'to'          => [['email' => 'juan@ejemplo.local', 'name' => 'Juan Pérez']],
            'subject'     => 'Verificá tu mail',
            'htmlContent' => '<p>Hola</p>',
            'textContent' => 'Hola',
        ], $pedido);
    }

    //Con transporte brevo y sin la clave, no intenta conectarse: lanza un error que dice qué variable falta.
    //Si quien corre los tests tiene una clave en su configuracion.php, se saltea: con la clave, mandaría un mail de verdad.
    public function testSinLaClaveDeBrevoLanzaUnErrorClaro(): void
    {
        if (Config::obtener('brevo.api_key', '') !== '') {
            $this->markTestSkipped('Hay una clave de Brevo en configuracion.php: este test mandaría un mail real');
        }
        putenv('MAIL_TRANSPORTE=brevo');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BREVO_API_KEY');
        EnviadorMail::enviar('juan@ejemplo.local', 'Juan', 'Asunto', '<p>Hola</p>', 'Hola');
    }

    //Un transporte que no existe (por ejemplo, un error de tipeo en Railway) se avisa en lugar de ignorarse
    public function testUnTransporteDesconocidoLanzaUnError(): void
    {
        putenv('MAIL_TRANSPORTE=sendgrid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Transporte de mail desconocido: 'sendgrid'");
        EnviadorMail::enviar('juan@ejemplo.local', 'Juan', 'Asunto', '<p>Hola</p>', 'Hola');
    }
}
