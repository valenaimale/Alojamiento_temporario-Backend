<?php

namespace App\Validacion;

use App\Database\Conexion;

//Descuentos por estadía larga de cada unidad (US2.10, decisión #69 de docs/pagos-decisiones.md).
//Cada unidad tiene escalones en descuentos_estadia (por ejemplo, 10 % desde 10 noches y 15 % desde 14), y a una
//estadía se le aplica el escalón más alto que alcanza. Valen en todas las temporadas. Lo usa Estadia::calcular:
//así la búsqueda, el detalle, la reserva y la extensión cobran lo mismo.
class Descuentos
{
    public const NOCHES_MINIMAS = 2;  //un descuento "desde 1 noche" sería bajar el precio: eso se hace con la tarifa
    public const NOCHES_MAXIMAS = 60; //la estadía más larga que se puede reservar (Estadia::MAXIMO_NOCHES)

    //El % de descuento ("10.00") que le toca a una estadía de $noches noches en la unidad; "0.00" si no alcanza ninguno
    public static function porcentaje(int $unidadId, int $noches): string
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT porcentaje FROM descuentos_estadia
             WHERE unidad_id = ? AND noches_minimas <= ?
             ORDER BY noches_minimas DESC
             LIMIT 1'
        );
        $consulta->execute([$unidadId, $noches]);
        $porcentaje = $consulta->fetchColumn();
        return $porcentaje === false ? '0.00' : $porcentaje;//PDO devuelve el decimal como texto ("10.00")
    }

    //Los escalones de una unidad, de menos a más noches: [['noches_minimas' => 10, 'porcentaje' => '10.00'], ...]
    public static function deLaUnidad(int $unidadId): array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT noches_minimas, porcentaje FROM descuentos_estadia WHERE unidad_id = ? ORDER BY noches_minimas'
        );
        $consulta->execute([$unidadId]);
        return array_map(fn (array $fila) => [
            'noches_minimas' => (int) $fila['noches_minimas'],
            'porcentaje'     => $fila['porcentaje'],
        ], $consulta->fetchAll());
    }

    //Valida los escalones que manda el propietario. Devuelve la lista normalizada y ordenada por noches
    //(['noches_minimas' => 10, 'porcentaje' => '10.00']), o ['error' => mensaje].
    public static function validar(mixed $escalones): array
    {
        if (!is_array($escalones) || !array_is_list($escalones)) {
            return ['error' => 'Mandá los descuentos como una lista'];
        }
        if (count($escalones) > 10) {
            return ['error' => 'Podés cargar hasta 10 descuentos por unidad'];
        }

        $validos = [];
        foreach ($escalones as $escalon) {
            if (!is_array($escalon)) {
                return ['error' => 'Cada descuento tiene que tener noches y porcentaje'];
            }
            $noches = is_bool($escalon['noches_minimas'] ?? null) ? false : filter_var($escalon['noches_minimas'] ?? '', FILTER_VALIDATE_INT,
                ['options' => ['min_range' => self::NOCHES_MINIMAS, 'max_range' => self::NOCHES_MAXIMAS]]);
            if ($noches === false) {
                return ['error' => 'Las noches tienen que ser un número de ' . self::NOCHES_MINIMAS . ' a ' . self::NOCHES_MAXIMAS];
            }
            $porcentaje = self::porcentajeValido($escalon['porcentaje'] ?? null);
            if ($porcentaje === null) {
                return ['error' => 'El descuento tiene que ser un porcentaje mayor que 0 y menor que 100, con hasta 2 decimales'];
            }
            if (isset($validos[$noches])) {
                return ['error' => 'Hay dos descuentos desde la misma cantidad de noches'];
            }
            $validos[$noches] = ['noches_minimas' => $noches, 'porcentaje' => $porcentaje];
        }
        ksort($validos);
        return array_values($validos);
    }

    //El porcentaje como texto con 2 decimales ("10.00"), o null si no es mayor que 0 y menor que 100 con hasta
    //2 decimales. Acepta número (10 o 12.5) o texto ("12.50").
    private static function porcentajeValido(mixed $valor): ?string
    {
        if (!is_int($valor) && !is_float($valor) && !is_string($valor)) {
            return null;
        }
        $texto = trim((string) $valor);
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {
            return null;//dos dígitos enteros: menor que 100
        }
        return number_format((float) $texto, 2, '.', '');
    }
}
