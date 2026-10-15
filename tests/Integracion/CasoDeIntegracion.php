<?php

namespace Tests\Integracion;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Soporte\ClienteApi;
use Tests\Soporte\Datos;
use Tests\Soporte\EntornoDePrueba;

//Base de los tests de integración: antes de la primera clase prepara la base de prueba y levanta el backend
//(una sola vez por ejecución), y ofrece atajos para registrar usuarios e iniciar sesión.
abstract class CasoDeIntegracion extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        EntornoDePrueba::iniciar();
    }

    //Un "navegador" nuevo, sin cookies
    protected function cliente(): ClienteApi
    {
        return new ClienteApi();
    }

    protected function pdo(): PDO
    {
        return EntornoDePrueba::pdo();
    }

    //Registra un usuario por la API. Devuelve [el cliente con la sesión ya iniciada, los datos enviados, el usuario que devolvió el back].
    protected function registrar(string $rol = 'huesped', array $cambios = []): array
    {
        $cliente = $this->cliente();
        $datos = Datos::registro($rol, $cambios);
        $respuesta = $cliente->post('/registrarse', $datos);
        $this->assertSame(201, $respuesta->codigo, "No se pudo registrar: {$respuesta->cuerpo}");
        return [$cliente, $datos, $respuesta->json()['usuario']];
    }

    //Crea un usuario directo en la base, para los casos que no se pueden armar desde la web
    //(cuentas de backoffice, usuarios sin DNI como los de Google). Devuelve sus datos, con el id.
    protected function crearUsuarioEnBase(string $rol, array $cambios = []): array
    {
        $usuario = array_merge([
            'nombre'      => 'Usuario en Base',
            'mail'        => Datos::mail(),
            'dni'         => Datos::dni(),
            'contrasenia' => password_hash(Datos::CONTRASENIA, PASSWORD_DEFAULT),
            'rol'         => $rol,
        ], $cambios);
        $consulta = $this->pdo()->prepare(
            'INSERT INTO usuarios (nombre, mail, dni, contrasenia, rol) VALUES (:nombre, :mail, :dni, :contrasenia, :rol)'
        );
        $consulta->execute($usuario);
        $usuario['id'] = (int) $this->pdo()->lastInsertId();
        return $usuario;
    }

    //Inicia sesión por la API y devuelve el cliente con la sesión iniciada
    protected function iniciarSesion(string $mail, string $contrasenia = Datos::CONTRASENIA): ClienteApi
    {
        $cliente = $this->cliente();
        $respuesta = $cliente->post('/iniciar-sesion', ['mail' => $mail, 'contrasenia' => $contrasenia]);
        $this->assertSame(200, $respuesta->codigo, "No se pudo iniciar sesión: {$respuesta->cuerpo}");
        return $cliente;
    }

    protected function filaUsuario(string $mail): array
    {
        $consulta = $this->pdo()->prepare('SELECT * FROM usuarios WHERE mail = ?');
        $consulta->execute([$mail]);
        $fila = $consulta->fetch();
        $this->assertIsArray($fila, "No existe el usuario $mail en la base");
        return $fila;
    }
}
