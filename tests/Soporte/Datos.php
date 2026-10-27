<?php

namespace Tests\Soporte;

//Datos de prueba válidos y únicos: cada test usa su propio mail, DNI y CUIT,
//así los tests no dependen unos de otros ni del orden en que se corren.
final class Datos
{
    public const CONTRASENIA = 'clave-segura-1';

    //Un mail único, para que cada test use el suyo
    public static function mail(): string
    {
        return 'prueba-' . bin2hex(random_bytes(6)) . '@test.local';
    }

    //Un DNI al azar de 8 dígitos
    public static function dni(): string
    {
        return (string) random_int(10_000_000, 99_999_999);
    }

    //Un CUIT válido (con el dígito verificador correcto, calculado con módulo 11)
    public static function cuit(): string
    {
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        do {
            $base = '20' . random_int(10_000_000, 99_999_999);
            $suma = 0;
            for ($i = 0; $i < 10; $i++) {
                $suma += (int) $base[$i] * $pesos[$i];
            }
            $resto = $suma % 11;
            $verificador = $resto === 0 ? 0 : 11 - $resto;
        } while ($verificador === 10);//con 10 no existe un CUIT válido para esa base
        return $base . $verificador;
    }

    //Los datos que manda el formulario de registro. $cambios reemplaza campos puntuales.
    public static function registro(string $rol = 'huesped', array $cambios = []): array
    {
        $mail = self::mail();
        $datos = [
            'nombre'                   => 'Persona de Prueba',
            'dni'                      => self::dni(),
            'mail'                     => $mail,
            'mail_confirmacion'        => $mail,
            'contrasenia'              => self::CONTRASENIA,
            'contrasenia_confirmacion' => self::CONTRASENIA,
            'rol'                      => $rol,
        ];
        if ($rol === 'propietario' || $rol === 'administrador') {
            $datos = array_merge($datos, ['cobra_iva' => '0'], self::cuentaBancaria());
        }
        return array_merge($datos, $cambios);
    }

    //Un CBU válido (los dos dígitos verificadores calculados como en ValidadorDocumentos::esCbuValido)
    public static function cbu(): string
    {
        $verificador = function (string $digitos, array $pesos): int {
            $suma = 0;
            foreach ($pesos as $i => $peso) {
                $suma += (int) $digitos[$i] * $peso;
            }
            return (10 - $suma % 10) % 10;
        };
        $bloque1 = '285' . random_int(1000, 9999);
        $bloque2 = (string) random_int(1_000_000_000_000, 9_999_999_999_999);
        return $bloque1 . $verificador($bloque1, [7, 1, 3, 9, 7, 1, 3])
            . $bloque2 . $verificador($bloque2, [3, 9, 7, 1, 3, 9, 7, 1, 3, 9, 7, 1, 3]);
    }

    //La cuenta bancaria que piden el registro de propietarios y administradores y "hacerme propietario"
    public static function cuentaBancaria(): array
    {
        return [
            'cbu'            => self::cbu(),
            'alias'          => 'cuenta.' . bin2hex(random_bytes(4)),
            'titular_cuenta' => 'Persona de Prueba',
        ];
    }

    //Datos fiscales de un propietario que cobra IVA
    public static function fiscalesConIva(): array
    {
        return [
            'cobra_iva'        => '1',
            'cuit'             => self::cuit(),
            'razon_social'     => 'Cabañas de Prueba SRL',
            'domicilio_fiscal' => 'Av. Costanera 123, Las Grutas',
        ];
    }
}
