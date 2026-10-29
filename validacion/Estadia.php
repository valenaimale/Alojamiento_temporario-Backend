<?php

namespace App\Validacion;

use App\Database\Conexion;

//Si una unidad se puede reservar para una estadía (de ingreso a egreso) y cuánto cuesta (US3.2).
//La usan la búsqueda y el detalle de una unidad: así la regla está escrita una sola vez.
//Una estadía del 5 al 10 son las noches 5, 6, 7, 8 y 9: la fecha de egreso no es una noche.
//No mira el estado de la unidad (publicada, disponible, capacidad): eso lo filtra quien la llama.
class Estadia
{
    private const MAXIMO_NOCHES = 60;

    //Valida las fechas de una estadía. Devuelve ['ingreso' => fecha, 'egreso' => fecha, 'noches' => n] o ['error' => mensaje].
    //El ingreso es de mañana en adelante: hoy ya no se puede reservar.
    public static function validarFechas(string $textoIngreso, string $textoEgreso): array
    {
        if ($textoIngreso === '' || $textoEgreso === '') {
            return ['error' => 'Elegí las fechas de ingreso y de egreso'];
        }
        $ingreso = ValidadorFechas::fecha($textoIngreso);
        $egreso = ValidadorFechas::fecha($textoEgreso);
        if ($ingreso === null || $egreso === null) {
            return ['error' => 'Las fechas tienen que tener el formato AAAA-MM-DD'];
        }
        if ($ingreso <= ValidadorFechas::hoy()) {
            return ['error' => 'El ingreso tiene que ser de mañana en adelante'];
        }
        if ($egreso <= $ingreso) {
            return ['error' => 'El egreso tiene que ser posterior al ingreso'];
        }
        $noches = ValidadorFechas::noches($ingreso, $egreso);
        if ($noches > self::MAXIMO_NOCHES) {
            return ['error' => 'La estadía puede ser de hasta ' . self::MAXIMO_NOCHES . ' noches'];
        }
        return ['ingreso' => $ingreso, 'egreso' => $egreso, 'noches' => $noches];
    }

    //Calcula la estadía para una unidad. Devuelve:
    //  ['disponible' => true, 'noches' => 5, 'precio_total' => "350000.00"], o
    //  ['disponible' => false, 'motivo' => "Mínimo 7 noches en temporada alta"].
    //Una estadía se puede reservar si ninguna noche está ocupada, todas tienen precio, y la cantidad de noches
    //llega al mayor mínimo de las temporadas que toca (RN-09; 1 si no toca ninguna).
    public static function calcular(int $unidadId, \DateTimeImmutable $ingreso, \DateTimeImmutable $egreso): array
    {
        $noches = ValidadorFechas::noches($ingreso, $egreso);
        $primera = $ingreso->format('Y-m-d');
        $ultima = $egreso->modify('-1 day')->format('Y-m-d');//la última noche es la anterior al egreso
        $pdo = Conexion::obtener();

        if (self::nochesOcupadas($unidadId, $primera, $ultima) !== []) {
            return ['disponible' => false, 'motivo' => 'Hay noches ocupadas en esas fechas'];
        }

        //el precio total es la suma del precio vigente de cada noche (ya incluye el IVA, RN-13)
        $consulta = $pdo->prepare(
            'SELECT COUNT(*) AS con_precio, SUM(precio) AS total FROM precio_noche
             WHERE unidad_id = ? AND fecha BETWEEN ? AND ? AND vigente_hasta IS NULL'
        );
        $consulta->execute([$unidadId, $primera, $ultima]);
        $precios = $consulta->fetch();
        if ((int) $precios['con_precio'] < $noches) {
            return ['disponible' => false, 'motivo' => 'Hay noches sin precio en esas fechas'];
        }

        //RN-09: si toca varias temporadas, se exige el mayor de sus mínimos
        $consulta = $pdo->prepare(
            'SELECT nombre, minimo_noches FROM temporadas
             WHERE unidad_id = ? AND fecha_inicio <= ? AND fecha_fin >= ?
             ORDER BY minimo_noches DESC
             LIMIT 1'
        );
        $consulta->execute([$unidadId, $ultima, $primera]);
        $temporada = $consulta->fetch();
        if ($temporada !== false && $noches < (int) $temporada['minimo_noches']) {
            return ['disponible' => false, 'motivo' => "Mínimo {$temporada['minimo_noches']} noches en temporada {$temporada['nombre']}"];
        }

        return ['disponible' => true, 'noches' => $noches, 'precio_total' => $precios['total']];//SUM de un decimal: texto con 2 decimales
    }

    //La seña de una estadía: el % de seña de la unidad sobre el total, con 2 decimales, como texto ("105000.00")
    //igual que los demás montos. La usan el detalle (para mostrarla) y la reserva (para guardarla).
    public static function sena(string $precioTotal, string $porcentajeSena): string
    {
        return number_format(round((float) $precioTotal * (float) $porcentajeSena / 100, 2), 2, '.', '');
    }

    //Las noches ocupadas del rango (las dos fechas incluidas), como ["AAAA-MM-DD" => true, ...].
    //Una noche está ocupada si tiene un bloqueo o una reserva pendiente o confirmada (así la búsqueda, el detalle,
    //el calendario y la reserva las tienen en cuenta). La noche del egreso de una reserva queda libre:
    //ese día puede entrar otro huésped (RN-08).
    //Cuando lleguen los pagos, las pendientes vencidas (bloqueo_expira_en) van a dejar de ocupar.
    public static function nochesOcupadas(int $unidadId, string $primera, string $ultima): array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT fecha_desde AS desde, fecha_hasta AS hasta FROM bloqueos_calendario
             WHERE unidad_id = ? AND fecha_desde <= ? AND fecha_hasta >= ?
             UNION ALL
             SELECT fecha_ingreso, DATE_SUB(fecha_egreso, INTERVAL 1 DAY) FROM reservas
             WHERE unidad_id = ? AND estado IN ('pendiente', 'confirmada') AND fecha_ingreso <= ? AND fecha_egreso > ?"
        );
        $consulta->execute([$unidadId, $ultima, $primera, $unidadId, $ultima, $primera]);

        $ocupadas = [];
        foreach ($consulta->fetchAll() as $ocupacion) {
            //solo las noches que caen dentro del rango pedido (AAAA-MM-DD se compara como texto)
            $noche = ValidadorFechas::fecha(max($ocupacion['desde'], $primera));
            $fin = ValidadorFechas::fecha(min($ocupacion['hasta'], $ultima));
            for (; $noche <= $fin; $noche = $noche->modify('+1 day')) {
                $ocupadas[$noche->format('Y-m-d')] = true;
            }
        }
        return $ocupadas;
    }
}
