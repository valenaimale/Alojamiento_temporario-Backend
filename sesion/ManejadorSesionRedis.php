<?php

namespace App\Sesion;

use Predis\Client;

//Guarda las sesiones de PHP en Redis en lugar de archivos, para que todas las réplicas del backend las compartan.
//Cada sesión es una clave "<prefijo><id de sesión>" que Redis borra sola al vencer (TTL).
//PHP llama a estos métodos por su cuenta (session_start, al terminar la petición, session_destroy...):
//el resto del código sigue usando $_SESSION igual que antes.
class ManejadorSesionRedis implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private Client $redis,
        private string $prefijo,
        private int $duracionSegundos
    ) {}

    //No hay nada que abrir ni cerrar: la conexión a Redis la maneja Predis
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    //Devuelve los datos de la sesión, o '' si no existe (PHP lo toma como sesión vacía)
    public function read(string $id): string|false
    {
        return (string) ($this->redis->get($this->prefijo . $id) ?? '');
    }

    //Guarda los datos y renueva el vencimiento.
    //Una sesión vacía (un visitante sin login) no se guarda: si no, cada visita anónima ocuparía
    //memoria en Redis durante horas. Si la sesión quedó vacía, se borra la clave.
    public function write(string $id, string $data): bool
    {
        if ($data === '') {
            $this->redis->del([$this->prefijo . $id]);
            return true;
        }
        $this->redis->setex($this->prefijo . $id, $this->duracionSegundos, $data);
        return true;
    }

    //Se llama en session_destroy() (cerrar sesión) y en session_regenerate_id(true) (login)
    public function destroy(string $id): bool
    {
        $this->redis->del([$this->prefijo . $id]);
        return true;
    }

    //Redis ya borra las sesiones vencidas por TTL: no hay nada que limpiar
    public function gc(int $max_lifetime): int|false { return 0; }

    //Con session.use_strict_mode, PHP rechaza IDs que no existen (evita que un atacante imponga un ID)
    public function validateId(string $id): bool
    {
        return $this->redis->exists($this->prefijo . $id) > 0;
    }

    //Si la sesión no cambió, PHP no llama a write(): igual hay que extender el vencimiento,
    //si no, un usuario activo que solo lee su sesión la perdería a las 2 horas
    public function updateTimestamp(string $id, string $data): bool
    {
        $this->redis->expire($this->prefijo . $id, $this->duracionSegundos);
        return true;
    }
}
