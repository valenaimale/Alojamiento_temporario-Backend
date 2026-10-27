<?php

namespace Tests\Soporte;

//Lo que respondió el backend: código HTTP, cuerpo y headers.
final class Respuesta
{
    //Guarda el código, el cuerpo y los headers de la respuesta
    public function __construct(
        public readonly int $codigo,
        public readonly string $cuerpo,
        private readonly array $headers,
    ) {
    }

    //El cuerpo decodificado. Todas las respuestas del backend son JSON: si no lo es, el test falla.
    public function json(): array
    {
        $datos = json_decode($this->cuerpo, true);
        if (!is_array($datos)) {
            throw new \RuntimeException("La respuesta no es JSON ({$this->codigo}): {$this->cuerpo}");
        }
        return $datos;
    }

    //El primer valor de un header (sin distinguir mayúsculas), o null si no vino.
    public function header(string $nombre): ?string
    {
        return $this->headers($nombre)[0] ?? null;
    }

    //Todos los valores de un header (sin distinguir mayúsculas)
    public function headers(string $nombre): array
    {
        foreach ($this->headers as $clave => $valores) {
            if (strcasecmp($clave, $nombre) === 0) {
                return $valores;
            }
        }
        return [];
    }
}
