<?php

namespace App\Validacion;

//Validación de DNI, CUIT, CBU y alias. Siempre se normaliza primero y se valida el resultado normalizado.
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

    //"2850590 9 4009041813520 1" → "2850590940090418135201"
    public static function normalizarCbu(string $cbu): string
    {
        return preg_replace('/[\s.\-]/', '', $cbu);
    }

    //CBU ya normalizado: 22 dígitos en dos bloques, cada uno con su dígito verificador.
    //Bloque 1 (8 dígitos): banco (3) + sucursal (4) + verificador.
    //Bloque 2 (14 dígitos): número de cuenta (13) + verificador.
    public static function esCbuValido(string $cbu): bool
    {
        if (preg_match('/^\d{22}$/', $cbu) !== 1) {
            return false;
        }
        return self::bloqueCbuValido(substr($cbu, 0, 8), [7, 1, 3, 9, 7, 1, 3])
            && self::bloqueCbuValido(substr($cbu, 8, 14), [3, 9, 7, 1, 3, 9, 7, 1, 3, 9, 7, 1, 3]);
    }

    //Controla el dígito verificador de un bloque del CBU (es el último dígito del bloque).
    //1. Se multiplica cada dígito anterior por su peso y se suman los resultados.
    //2. El verificador es lo que le falta a esa suma para llegar a la próxima decena: (10 - suma % 10) % 10.
    //   El "% 10" del final es para cuando la suma ya termina en 0: 10 - 0 = 10, y el verificador tiene que ser 0.
    //Ejemplo con el bloque 1 del CBU 2850590940090418135201 → "28505909", pesos 7, 1, 3, 9, 7, 1, 3:
    //   2*7 + 8*1 + 5*3 + 0*9 + 5*7 + 9*1 + 0*3 = 14 + 8 + 15 + 0 + 35 + 9 + 0 = 81
    //   81 % 10 = 1 → 10 - 1 = 9 → 9 % 10 = 9, y el último dígito del bloque es 9: es válido.
    private static function bloqueCbuValido(string $bloque, array $pesos): bool
    {
        $suma = 0;
        foreach ($pesos as $i => $peso) {
            $suma += (int) $bloque[$i] * $peso;
        }
        $verificador = (10 - $suma % 10) % 10;
        return $verificador === (int) $bloque[count($pesos)];
    }

    //El alias no distingue mayúsculas: se guarda en minúsculas. "  Casa.Playa.Sol " → "casa.playa.sol"
    public static function normalizarAlias(string $alias): string
    {
        return mb_strtolower(trim($alias));
    }

    //Alias ya normalizado (reglas del BCRA): de 6 a 20 caracteres, solo letras sin tilde ni ñ, números, puntos y guiones
    public static function esAliasValido(string $alias): bool
    {
        return preg_match('/^[a-z0-9.\-]{6,20}$/', $alias) === 1;
    }
}
