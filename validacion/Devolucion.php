<?php

namespace App\Validacion;

//Cuánto se le devuelve al huésped que cancela una reserva, según la política que aceptó al reservar (L2-3, L2-18).
//Plan de la entrega del 80 %, Rol 2.
//La política retiene un % DE LA SEÑA según cuántos días faltan hasta el cobro del saldo (fecha_cobro): más de 30,
//de 15 a 30 o menos de 15 (corrección del profesor). Si el huésped ya pagó el saldo, no se le devuelve nada
//(decisión #62): el propietario puede darle una excepción en las 48 hs siguientes.
//Si cancela el PROPIETARIO, se le devuelve todo lo que pagó (Devolucion::total, decisión #63).
//Las cuentas se hacen sobre lo que entró de verdad al contrato (los pagos confirmados, en wei): así nunca se pide
//devolver más ETH del que hay para esa reserva, aunque cambie el tipo de cambio.
//No toca la base: recibe la reserva y sus pagos, así se prueba con tests unitarios.
class Devolucion
{
    public const MAS_DE_30 = 'mas_de_30';
    public const DE_15_A_30 = 'de_15_a_30';
    public const MENOS_DE_15 = 'menos_de_15';

    //$reserva: fecha_cobro (AAAA-MM-DD) y las 3 retenciones (retencion_mas_de_30, ...), como las guarda reservas.
    //$pagos: los pagos confirmados de la reserva, con tipo (sena o saldo), monto_pesos, monto_wei, pesos_por_eth y billetera.
    //Devuelve:
    //  ['tramo' => 'de_15_a_30', 'dias_hasta_cobro' => 20, 'porcentaje_retencion' => '50.00',
    //   'pagado_sena' => '42000.00', 'pagado_saldo' => '0.00', 'retencion' => '21000.00', 'devolucion' => '21000.00',
    //   'devolucion_wei' => '210000000000000', 'billetera' => '0x…' o null, 'pesos_por_eth' => '100000000.00' o null]
    //Lo que se le devuelve al huésped cuando cancela el propietario (decisión #63): TODO lo que pagó, seña y saldo,
    //sin retención. Con la misma forma que calcular(), para usar los mismos mails y la misma respuesta.
    public static function total(array $reserva, array $pagos, \DateTimeImmutable $hoy): array
    {
        return self::calcular(array_merge($reserva, [
            'retencion_mas_de_30' => '0.00', 'retencion_de_15_a_30' => '0.00', 'retencion_menos_de_15' => '0.00',
        ]), $pagos, $hoy, false);
    }

    //$retenerConSaldo: false solo para total() (el propietario cancela: se devuelve también el saldo)
    public static function calcular(array $reserva, array $pagos, \DateTimeImmutable $hoy, bool $retenerConSaldo = true): array
    {
        $cobro = ValidadorFechas::fecha($reserva['fecha_cobro']);
        //se comparan días de calendario: las dos fechas en la misma zona y sin hora (si no, con $hoy en UTC
        //y el cobro en hora de Argentina, la diferencia de 3 horas le come un día a la cuenta)
        $hoy = ValidadorFechas::fecha($hoy->format('Y-m-d'));
        $dias = (int) $hoy->diff($cobro)->format('%r%a');//negativo si el cobro ya pasó
        $tramo = match (true) {
            $dias > 30  => self::MAS_DE_30,
            $dias >= 15 => self::DE_15_A_30,
            default     => self::MENOS_DE_15,
        };
        $porcentaje = $reserva['retencion_' . $tramo];

        $pagado = ['sena' => ['0.00', '0'], 'saldo' => ['0.00', '0']];//[pesos, wei]
        $billetera = null;
        $pesosPorEth = null;
        foreach ($pagos as $pago) {
            if (!isset($pagado[$pago['tipo']])) {
                continue;//una devolución o liquidación anterior no es plata del huésped
            }
            $pagado[$pago['tipo']] = [
                bcadd($pagado[$pago['tipo']][0], $pago['monto_pesos'], 2),
                bcadd($pagado[$pago['tipo']][1], $pago['monto_wei'], 0),
            ];
            //se le devuelve a la billetera que pagó la seña (el contrato solo deja devolverle a quien pagó)
            if ($billetera === null || $pago['tipo'] === 'sena') {
                $billetera = $pago['billetera'];
                $pesosPorEth = $pago['pesos_por_eth'];
            }
        }
        [$senaPesos, $senaWei] = $pagado['sena'];
        [$saldoPesos, $saldoWei] = $pagado['saldo'];

        //la retención en pesos se redondea a centavos; en wei se trunca (a favor del huésped por menos de 1 wei)
        $retencionPesos = number_format(round((float) $senaPesos * (float) $porcentaje / 100, 2), 2, '.', '');
        $retencionWei = bcdiv(bcmul($senaWei, $porcentaje, 2), '100', 0);

        //Decisión #62 (reemplaza a la #31): si el huésped ya pagó el saldo, se retiene TODO lo que pagó.
        //Solo recupera algo si el propietario le da una excepción en las 48 hs siguientes (PoliticaExcepcion).
        if ($retenerConSaldo && bccomp($saldoWei, '0', 0) > 0) {
            return [
                'tramo'                => $tramo,
                'dias_hasta_cobro'     => $dias,
                'porcentaje_retencion' => '100.00',
                'pagado_sena'          => $senaPesos,
                'pagado_saldo'         => $saldoPesos,
                'retencion'            => bcadd($senaPesos, $saldoPesos, 2),
                'devolucion'           => '0.00',
                'devolucion_wei'       => '0',
                'billetera'            => $billetera,
                'pesos_por_eth'        => $pesosPorEth,
            ];
        }

        return [
            'tramo'                => $tramo,
            'dias_hasta_cobro'     => $dias,
            'porcentaje_retencion' => $porcentaje,
            'pagado_sena'          => $senaPesos,
            'pagado_saldo'         => $saldoPesos,
            'retencion'            => $retencionPesos,
            'devolucion'           => bcadd(bcsub($senaPesos, $retencionPesos, 2), $saldoPesos, 2),
            'devolucion_wei'       => bcadd(bcsub($senaWei, $retencionWei, 0), $saldoWei, 0),
            'billetera'            => $billetera,
            'pesos_por_eth'        => $pesosPorEth,
        ];
    }
}
