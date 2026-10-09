<?php

namespace App\Validacion;

//Validación de DNI y CUIT. Siempre se normaliza primero y se valida el resultado normalizado.
class ValidadorDocumentos
{
    //Saca puntos, guiones y espacios: "30.123.456" → "30123456"
    public static function normalizarDni(string $dni): string
    {
        return preg_replace('/[\s.\-]/', '', $dni);
    }

    //DNI ya normalizado: solo dígitos, 7 u 8
    public static function esDniValido(string $dni): bool
    {
        return preg_match('/^\d{7,8}$/', $dni) === 1;
    }

    //"20-30123456-3" → "20301234563"
    public static function normalizarCuit(string $cuit): string
    {
        return preg_replace('/[\s.\-]/', '', $cuit);
    }

    //CUIT ya normalizado: 11 dígitos y dígito verificador correcto (módulo 11)
    public static function esCuitValido(string $cuit): bool
    {
        if (preg_match('/^\d{11}$/', $cuit) !== 1) {
            return false;
        }
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma += (int) $cuit[$i] * $pesos[$i];
        }
        $resto = $suma % 11;
        $verificador = $resto === 0 ? 0 : 11 - $resto;
        if ($verificador === 10) {
            return false;
        }
        return $verificador === (int) $cuit[10];
    }
}
