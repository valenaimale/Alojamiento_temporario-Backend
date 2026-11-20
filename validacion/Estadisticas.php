<?php

namespace App\Validacion;

use App\Database\Conexion;

//Las estadísticas de una unidad en una temporada (RF-12, PN-12). No hay tabla que las guarde: se calculan sobre
//las reservas (decisión 10 de modelo-de-datos.md), porque guardarlas duplicaría datos que quedan desactualizados.
//
//Qué cuenta en una temporada:
//  - La OCUPACIÓN y la TARIFA PROMEDIO se cuentan noche por noche: una reserva que empieza en temporada baja y
//    termina en alta le aporta a cada una las noches que le tocan.
//  - La ESTADÍA PROMEDIO, las CANCELACIONES y la cantidad de reservas cuentan la reserva entera en la temporada
//    donde empieza: son propiedades de la reserva, no de cada noche.
//  - Los HUÉSPEDES RECURRENTES son el histórico de la unidad, no de la temporada: un huésped es recurrente
//    porque volvió, y bien puede haber vuelto en otra temporada.
//  - Si la temporada está EN CURSO, la ocupación se mide hasta anoche y no hasta su última noche: si no, toda
//    temporada empezada daría una ocupación falsamente baja.
//  - Una EXTENSIÓN (reservas.extiende_reserva_id, decisión #70) no es una reserva aparte: sus noches suman a la
//    estadía de la reserva que extiende.
//
//El tramo de una cancelación se mide contra fecha_cobro, igual que la devolución que se le hizo al huésped
//(Devolucion::calcular, corrección del profesor): si se midiera contra fecha_ingreso, la estadística diría una
//cosa y la plata devuelta otra.
//
//calcular() no toca la base: recibe las reservas y se prueba con tests unitarios, como Devolucion.
class Estadisticas
{
    //Las reservas que ocupan la unidad: una pendiente todavía puede expirar, una cancelada no ocupó nada, y en un
    //no-show (decisión #67) el huésped nunca llegó, aunque la plata haya quedado retenida.
    public const OCUPAN = ['confirmada', 'finalizada'];

    //Cuántas reservas distintas tiene que tener un huésped en la unidad para contar como recurrente
    public const RESERVAS_PARA_SER_RECURRENTE = 2;

    //Las cancelaciones que no son del huésped (las del propietario y las de la plataforma) no entran en ningún
    //tramo: no hubo retención por política, se devolvió todo (decisión #63).
    public const OTRAS_CANCELACIONES = 'otras';

    //Las estadísticas de la temporada [$primeraNoche, $ultimaNoche] a partir de TODAS las reservas de la unidad.
    //Cada reserva: id, huesped_id, extiende_reserva_id, fecha_ingreso, fecha_egreso, fecha_cobro, estado,
    //importe_total, cancelada_en y cancelada_por, como las guarda la tabla.
    //$hoy se pasa solo en los tests; en la aplicación sale de ValidadorFechas::hoy().
    public static function calcular(
        array $reservas,
        string $primeraNoche,
        string $ultimaNoche,
        ?\DateTimeImmutable $hoy = null
    ): array {
        $desde = ValidadorFechas::fecha($primeraNoche);
        $hasta = ValidadorFechas::fecha($ultimaNoche)?->modify('+1 day');//exclusivo: fecha_fin es la última NOCHE
        if ($desde === null || $hasta === null || $hasta <= $desde) {
            return self::sinDatos();
        }

        //hasta dónde se mide la ocupación: la última noche de la temporada o anoche, lo que venga primero
        $corte = ValidadorFechas::fecha(($hoy ?? ValidadorFechas::hoy())->format('Y-m-d')) ?? $desde;
        $corte = min($corte, $hasta);
        $corte = max($corte, $desde);

        $porId = [];
        foreach ($reservas as $reserva) {
            $porId[(int) $reserva['id']] = $reserva;
        }

        $nochesOcupadas = 0;
        $centavosDeEsasNoches = 0;
        $nochesDeLaEstadia = [];    //id de la reserva raíz => noches, extensiones incluidas
        $raizEnLaTemporada = [];    //id de la reserva raíz => empieza dentro de la temporada
        $reservasDelHuesped = [];   //huesped_id => reservas distintas en la unidad, histórico
        $cancelaciones = [
            Devolucion::MAS_DE_30     => 0,
            Devolucion::DE_15_A_30    => 0,
            Devolucion::MENOS_DE_15   => 0,
            self::OTRAS_CANCELACIONES => 0,
        ];

        foreach ($reservas as $reserva) {
            $ingreso = ValidadorFechas::fecha($reserva['fecha_ingreso']);
            $egreso  = ValidadorFechas::fecha($reserva['fecha_egreso']);
            if ($ingreso === null || $egreso === null) {
                continue;//una fila con fechas rotas no se inventa: se deja afuera
            }

            $idRaiz      = self::idRaiz($reserva, $porId);
            $ingresoRaiz = ValidadorFechas::fecha($porId[$idRaiz]['fecha_ingreso'] ?? '') ?? $ingreso;
            $esExtension = (int) ($reserva['extiende_reserva_id'] ?? 0) !== 0;

            $raizEnLaTemporada[$idRaiz] = $ingresoRaiz >= $desde && $ingresoRaiz < $hasta;

            if (in_array($reserva['estado'], self::OCUPAN, true)) {
                $noches    = ValidadorFechas::noches($ingreso, $egreso);
                $enVentana = self::nochesEnVentana($ingreso, $egreso, $desde, $corte);
                if ($noches > 0) {
                    $nochesDeLaEstadia[$idRaiz] = ($nochesDeLaEstadia[$idRaiz] ?? 0) + $noches;
                    if ($enVentana > 0) {
                        $nochesOcupadas += $enVentana;
                        //lo que valen esas noches: el total de la reserva prorrateado noche por noche
                        $centavosDeEsasNoches += intdiv(self::centavos($reserva['importe_total']) * $enVentana, $noches);
                    }
                }
                if (!$esExtension) {
                    $huesped = (int) $reserva['huesped_id'];
                    $reservasDelHuesped[$huesped] = ($reservasDelHuesped[$huesped] ?? 0) + 1;
                }
            }

            if ($reserva['estado'] === 'cancelada' && !$esExtension && $raizEnLaTemporada[$idRaiz]) {
                $cancelaciones[self::tramoDeCancelacion($reserva)]++;
            }
        }

        $estadias = [];
        foreach ($nochesDeLaEstadia as $idRaiz => $noches) {
            if ($raizEnLaTemporada[$idRaiz] ?? false) {
                $estadias[] = $noches;
            }
        }

        $nochesDisponibles = ValidadorFechas::noches($desde, $corte);
        $recurrentes = array_filter($reservasDelHuesped, fn (int $cuantas) => $cuantas >= self::RESERVAS_PARA_SER_RECURRENTE);

        return [
            'noches_disponibles'    => $nochesDisponibles,
            'noches_ocupadas'       => $nochesOcupadas,
            'ocupacion'             => $nochesDisponibles > 0
                ? number_format($nochesOcupadas * 100 / $nochesDisponibles, 1, '.', '')
                : '0.0',
            'tarifa_promedio'       => $nochesOcupadas > 0
                ? number_format(intdiv($centavosDeEsasNoches, $nochesOcupadas) / 100, 2, '.', '')
                : '0.00',
            'estadia_promedio'      => $estadias !== []
                ? number_format(array_sum($estadias) / count($estadias), 1, '.', '')
                : '0.0',
            'reservas'              => count($estadias),
            'cancelaciones'         => $cancelaciones,
            'huespedes_recurrentes' => count($recurrentes),
            'hay_datos'             => $estadias !== [] || array_sum($cancelaciones) > 0,
        ];
    }

    //Cuántas noches de la reserva [$ingreso, $egreso) caen dentro de [$desde, $hastaExclusivo)
    public static function nochesEnVentana(
        \DateTimeImmutable $ingreso,
        \DateTimeImmutable $egreso,
        \DateTimeImmutable $desde,
        \DateTimeImmutable $hastaExclusivo
    ): int {
        $arranca = max($ingreso, $desde);
        $termina = min($egreso, $hastaExclusivo);
        return $termina > $arranca ? ValidadorFechas::noches($arranca, $termina) : 0;
    }

    //En qué tramo de la política cayó la cancelación, con los mismos cortes que la devolución (Devolucion::calcular):
    //más de 30 días hasta el cobro del saldo, de 15 a 30, o menos de 15.
    public static function tramoDeCancelacion(array $reserva): string
    {
        $cancelada = ValidadorFechas::fecha(substr((string) ($reserva['cancelada_en'] ?? ''), 0, 10));
        $cobro     = ValidadorFechas::fecha((string) ($reserva['fecha_cobro'] ?? ''));
        if (($reserva['cancelada_por'] ?? null) !== 'huesped' || $cancelada === null || $cobro === null) {
            return self::OTRAS_CANCELACIONES;//la canceló el propietario o la plataforma: no hubo retención por política
        }

        $dias = (int) $cancelada->diff($cobro)->format('%r%a');//negativo si el cobro ya había pasado
        return match (true) {
            $dias > 30  => Devolucion::MAS_DE_30,
            $dias >= 15 => Devolucion::DE_15_A_30,
            default     => Devolucion::MENOS_DE_15,
        };
    }

    //Todas las reservas de la unidad, con lo que hace falta para las estadísticas. Es el histórico completo:
    //los huéspedes recurrentes se cuentan sobre todas las temporadas.
    public static function deLaUnidad(int $unidadId): array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT id, huesped_id, extiende_reserva_id, fecha_ingreso, fecha_egreso, fecha_cobro,
                    estado, importe_total, cancelada_en, cancelada_por
             FROM reservas
             WHERE unidad_id = ?
             ORDER BY fecha_ingreso, id'
        );
        $consulta->execute([$unidadId]);
        return $consulta->fetchAll();
    }

    //Las temporadas cargadas de la unidad, de la más nueva a la más vieja (es la que el propietario quiere ver primero)
    public static function temporadasDeLaUnidad(int $unidadId): array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT id, nombre, fecha_inicio, fecha_fin FROM temporadas WHERE unidad_id = ? ORDER BY fecha_inicio DESC, id DESC'
        );
        $consulta->execute([$unidadId]);
        return array_map(fn (array $fila) => [
            'id'           => (int) $fila['id'],
            'nombre'       => $fila['nombre'],
            'fecha_inicio' => $fila['fecha_inicio'],
            'fecha_fin'    => $fila['fecha_fin'],
        ], $consulta->fetchAll());
    }

    //La reserva de la que cuelga esta: si es una extensión, la que extiende (y si esa es otra extensión, la de más
    //atrás). El límite corta una cadena rota en la base, para no quedar en un bucle infinito.
    private static function idRaiz(array $reserva, array $porId): int
    {
        $id = (int) $reserva['id'];
        for ($saltos = 0; $saltos < 20; $saltos++) {
            $padre = (int) ($porId[$id]['extiende_reserva_id'] ?? 0);
            if ($padre === 0 || !isset($porId[$padre])) {
                return $id;
            }
            $id = $padre;
        }
        return $id;
    }

    //Los pesos como centavos enteros, para no arrastrar errores de coma flotante en la tarifa promedio
    private static function centavos(string $pesos): int
    {
        return (int) round(((float) $pesos) * 100);
    }

    //Una temporada sin reservas, o con fechas que no se entienden: todo en cero y hay_datos en false, que es lo que
    //el front usa para mostrar "Todavía no hay datos de esta temporada".
    private static function sinDatos(): array
    {
        return [
            'noches_disponibles'    => 0,
            'noches_ocupadas'       => 0,
            'ocupacion'             => '0.0',
            'tarifa_promedio'       => '0.00',
            'estadia_promedio'      => '0.0',
            'reservas'              => 0,
            'cancelaciones'         => [
                Devolucion::MAS_DE_30     => 0,
                Devolucion::DE_15_A_30    => 0,
                Devolucion::MENOS_DE_15   => 0,
                self::OTRAS_CANCELACIONES => 0,
            ],
            'huespedes_recurrentes' => 0,
            'hay_datos'             => false,
        ];
    }
}
