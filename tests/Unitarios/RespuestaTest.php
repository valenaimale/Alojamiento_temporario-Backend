<?php

namespace Tests\Unitarios;

use App\Request\Respuesta;
use PHPUnit\Framework\TestCase;

//Las respuestas JSON de la API (App\Request\Respuesta): el cuerpo y el código HTTP que usan todos los controladores
final class RespuestaTest extends TestCase
{
    //json() escribe el cuerpo en JSON con el código pedido, y 200 si no se pide ninguno
    public function testJsonEscribeElCuerpoConSuCodigo(): void
    {
        // Act
        $creado = $this->capturar(fn () => Respuesta::json(['ok' => 'Listo', 'id' => 7], 201));
        $porDefecto = $this->capturar(fn () => Respuesta::json(['ok' => 'Listo']));

        // Assert
        $this->assertSame([201, ['ok' => 'Listo', 'id' => 7]], $creado);
        $this->assertSame([200, ['ok' => 'Listo']], $porDefecto);
    }

    //error() responde {"error": mensaje} con 422 si no se pide otro código, y suma los datos extra
    public function testErrorUsaElMensajeYSumaLosDatosExtra(): void
    {
        // Act
        $simple = $this->capturar(fn () => Respuesta::error('El DNI no es válido'));
        $conDatos = $this->capturar(fn () => Respuesta::error('Falta pagar el saldo', 409, ['saldo' => '1000.00']));

        // Assert
        $this->assertSame([422, ['error' => 'El DNI no es válido']], $simple);
        $this->assertSame([409, ['error' => 'Falta pagar el saldo', 'saldo' => '1000.00']], $conDatos);
    }

    //Corre la respuesta y devuelve [código HTTP, cuerpo decodificado]
    private function capturar(callable $responder): array
    {
        ob_start();
        $responder();
        $cuerpo = ob_get_clean();
        return [http_response_code(), json_decode($cuerpo, true)];
    }
}
