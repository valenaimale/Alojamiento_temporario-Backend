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
    //Antes de la primera clase de tests: prepara la base de prueba y levanta el backend (una sola vez)
    public static function setUpBeforeClass(): void
    {
        EntornoDePrueba::iniciar();
    }

    //Un "navegador" nuevo, sin cookies
    protected function cliente(): ClienteApi
    {
        return new ClienteApi();
    }

    //Conexión a la base de prueba, para preparar datos o revisar lo que quedó guardado
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

    //La fila completa de un usuario en la base (incluida la contraseña hasheada); el test falla si no existe
    protected function filaUsuario(string $mail): array
    {
        $consulta = $this->pdo()->prepare('SELECT * FROM usuarios WHERE mail = ?');
        $consulta->execute([$mail]);
        $fila = $consulta->fetch();
        $this->assertIsArray($fila, "No existe el usuario $mail en la base");
        return $fila;
    }

    // ---------- Épicas 2 y 3: unidades ----------
    //Arman los datos directo en la base, para que cada test de unidades no dependa de otras rutas.

    //Un propietario (o administrador) con su fila de datos fiscales y bancarios. Devuelve sus datos, con el id.
    protected function crearPropietarioEnBase(array $cambios = []): array
    {
        return $this->crearConDatosFiscales('propietario', 'propietarios', $cambios);
    }

    //Un administrador con su fila de datos fiscales y bancarios. Devuelve sus datos, con el id.
    protected function crearAdministradorEnBase(array $cambios = []): array
    {
        return $this->crearConDatosFiscales('administrador', 'administradores', $cambios);
    }

    //Crea el usuario y su fila en propietarios o administradores (sin IVA, con un CBU válido)
    private function crearConDatosFiscales(string $rol, string $tabla, array $cambios): array
    {
        $usuario = $this->crearUsuarioEnBase($rol, $cambios);
        $consulta = $this->pdo()->prepare(
            "INSERT INTO {$tabla} (usuario_id, cobra_iva, cbu, titular_cuenta) VALUES (?, 0, ?, ?)"
        );
        $consulta->execute([$usuario['id'], Datos::cbu(), $usuario['nombre']]);
        return $usuario;
    }

    //Una unidad en borrador, con la seña y la política por defecto. Devuelve su id.
    protected function crearUnidadEnBase(int $propietarioId, array $cambios = []): int
    {
        $unidad = array_merge([
            'propietario_id'        => $propietarioId,
            'localidad_id'          => 1,
            'nombre'                => 'Cabaña de Prueba',
            'direccion'             => 'Costanera 123',
            'capacidad'             => 4,
            'estado_publicacion'    => 'borrador',
            'porcentaje_sena'       => 20,
            'retencion_mas_de_30'   => 30,
            'retencion_de_15_a_30'  => 50,
            'retencion_menos_de_15' => 70,
        ], $cambios);
        $columnas = implode(', ', array_keys($unidad));
        $marcadores = implode(', ', array_fill(0, count($unidad), '?'));
        $this->pdo()->prepare("INSERT INTO unidades ($columnas) VALUES ($marcadores)")->execute(array_values($unidad));
        return (int) $this->pdo()->lastInsertId();
    }

    //Asigna un administrador a una unidad, con su % de comisión
    protected function asignarAdministradorEnBase(int $unidadId, int $administradorId, float $porcentajeComision = 15): void
    {
        $this->pdo()->prepare('INSERT INTO administraciones (unidad_id, administrador_id, porcentaje_comision) VALUES (?, ?, ?)')
            ->execute([$unidadId, $administradorId, $porcentajeComision]);
    }

    //El mismo precio para cada noche del rango (las dos fechas incluidas), vigente desde ahora
    protected function cargarPreciosEnBase(int $unidadId, string $desde, string $hasta, float $precio, int $usuarioId): void
    {
        $consulta = $this->pdo()->prepare(
            'INSERT INTO precio_noche (unidad_id, fecha, vigente_desde, precio, creado_por) VALUES (?, ?, NOW(), ?, ?)'
        );
        for ($noche = new \DateTimeImmutable($desde); $noche <= new \DateTimeImmutable($hasta); $noche = $noche->modify('+1 day')) {
            $consulta->execute([$unidadId, $noche->format('Y-m-d'), $precio, $usuarioId]);
        }
    }

    //Una temporada de la unidad (alta, media o baja) con su mínimo de noches. Devuelve su id.
    protected function crearTemporadaEnBase(int $unidadId, string $nombre, string $inicio, string $fin, int $minimoNoches = 1): int
    {
        $this->pdo()->prepare('INSERT INTO temporadas (unidad_id, nombre, fecha_inicio, fecha_fin, minimo_noches) VALUES (?, ?, ?, ?, ?)')
            ->execute([$unidadId, $nombre, $inicio, $fin, $minimoNoches]);
        return (int) $this->pdo()->lastInsertId();
    }

    //Un bloqueo de fechas de la unidad (las dos fechas son noches incluidas). Devuelve su id.
    protected function crearBloqueoEnBase(int $unidadId, string $desde, string $hasta, int $usuarioId, string $motivo = 'reserva_externa'): int
    {
        $this->pdo()->prepare('INSERT INTO bloqueos_calendario (unidad_id, fecha_desde, fecha_hasta, motivo, creado_por) VALUES (?, ?, ?, ?, ?)')
            ->execute([$unidadId, $desde, $hasta, $motivo, $usuarioId]);
        return (int) $this->pdo()->lastInsertId();
    }

    //Una fecha relativa a hoy en Argentina, como texto: $this->fecha('+10 days') → "2026-11-06"
    protected function fecha(string $desplazamiento = 'today'): string
    {
        return (new \DateTimeImmutable($desplazamiento, new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
    }
}
