<?php

namespace App\Validacion;

// Valida el punto público y las distancias aproximadas informadas por el propietario (mapa, US2.12 y US3.4).
// La dirección exacta nunca se muestra: este punto lo elige el propietario para la búsqueda pública.
final class Ubicacion
{
    // Los campos de la ubicación pública, en el orden de las columnas de unidades
    public const CAMPOS = ['latitud', 'longitud', 'barrio', 'metros_playa', 'metros_centro'];

    // Valida la ubicación que manda el propietario. Todo es opcional, pero latitud y longitud van juntas.
    // Devuelve ['error' => mensaje] o los cinco campos listos para guardar (null los que no vinieron).
    public static function validar(array $datos): array
    {
        $ubicacion = [];
        foreach (['latitud' => 90, 'longitud' => 180] as $campo => $limite) {
            $valor = $datos[$campo] ?? '';
            if ($valor === '') {
                $ubicacion[$campo] = null;
            } elseif (!is_numeric($valor) || !is_finite((float) $valor) || abs((float) $valor) > $limite) {
                return ['error' => 'Revisá la latitud y la longitud del punto'];
            } else {
                $ubicacion[$campo] = round((float) $valor, 6);
            }
        }
        if (($ubicacion['latitud'] === null) !== ($ubicacion['longitud'] === null)) {
            return ['error' => 'Completá ambas coordenadas'];
        }
        $barrio = $datos['barrio'] ?? '';
        if (!is_string($barrio) || mb_strlen(trim($barrio)) > 100) {
            return ['error' => 'El barrio puede tener hasta 100 caracteres'];
        }
        $ubicacion['barrio'] = trim($barrio) === '' ? null : trim($barrio);
        foreach (['metros_playa', 'metros_centro'] as $campo) {
            $valor = $datos[$campo] ?? '';
            if ($valor !== '' && ((!is_int($valor) && !is_string($valor)) || !preg_match('/^\d{1,7}$/D', (string) $valor))) {
                return ['error' => 'Indicá las distancias en metros enteros, entre 0 y 9999999'];
            }
            $ubicacion[$campo] = $valor === '' ? null : (int) $valor;
        }
        return $ubicacion;
    }

    // Solo los campos de la ubicación pública de una fila (de la base o de lo que mandó el front)
    public static function publica(array $fila): array
    {
        return array_intersect_key($fila, array_flip(self::CAMPOS));
    }
}
