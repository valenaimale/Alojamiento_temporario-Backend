<?php

namespace Tests\Soporte;

//Datos de prueba válidos y únicos: cada test usa su propio mail, DNI y CUIT,
//así los tests no dependen unos de otros ni del orden en que se corren.
final class Datos
{
    public const CONTRASENIA = 'clave-segura-1';

    public static function mail(): string
    {
        return 'prueba-' . bin2hex(random_bytes(6)) . '@test.local';
    }

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
        if ($rol === 'propietario') {
            $datos['cobra_iva'] = '0';
        }
        return array_merge($datos, $cambios);
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
