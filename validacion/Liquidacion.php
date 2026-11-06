<?php

namespace App\Validacion;

use App\Database\Conexion;

//El reparto del dinero de una estadía: cuánto le queda al propietario, cuánto cobra la plataforma
//y cuánto el administrador (RF-09, RF-13 y RF-19).
//
//Las dos reglas son del contador:
//  - RN-24: si el propietario discrimina IVA, las comisiones se calculan sobre el NETO sin IVA
//    (el IVA del 21 % viene incluido en el precio que pagó el huésped); si no lo discrimina,
//    se calculan sobre el total. El IVA se lo queda el propietario, que es quien lo tiene que pagar.
//  - RN-25: el comprobante no tiene valor fiscal. El administrador factura su comisión aparte.
//
//Los importes se calculan en CENTAVOS ENTEROS, nunca en coma flotante: 0,1 + 0,2 no da 0,3 en un float, y acá
//los importes tienen que cerrar al centavo (RNF-22). Los porcentajes se tratan igual, en centésimas de punto:
//el 7,5 % es 750. Al final se vuelve a texto con dos decimales, como lo guarda la base.
class Liquidacion
{
    //Qué reservas corresponde liquidar: aquellas en las que el huésped ya ingresó.
    //Es cuando el contrato libera la plata que tenía retenida (plan de la entrega del 80 %, paso 0, punto 3):
    //no se espera al egreso, porque la plata ya es del propietario desde que el huésped entró.
    public static function reservasSinLiquidar(): array
    {
        $consulta = Conexion::obtener()->query(
            "SELECT r.id
             FROM reservas r
             JOIN estadias e ON e.reserva_id = r.id
             LEFT JOIN liquidaciones q ON q.reserva_id = r.id
             WHERE r.estado IN ('confirmada', 'finalizada') AND q.reserva_id IS NULL
             ORDER BY e.ingreso_en, r.id"
        );
        return array_map('intval', $consulta->fetchAll(\PDO::FETCH_COLUMN));
    }

    //Calcula y guarda la liquidación de una reserva. Devuelve su id, o null si no correspondía
    //(la reserva no existe, el huésped nunca ingresó o ya estaba liquidada).
    //Es idempotente: el índice único de reserva_id garantiza que una estadía se liquide una sola vez,
    //aunque el script y el backoffice la pidan al mismo tiempo.
    public static function generar(int $reservaId): ?int
    {
        $pdo = Conexion::obtener();

        //La condición fiscal y los porcentajes se leen AHORA y se guardan en la liquidación: si el propietario
        //cambia de categoría o renegocia la comisión, las liquidaciones ya hechas no se mueven.
        //Si la unidad dejó de tener administrador, no hay comisión que pagarle (el LEFT JOIN la deja en NULL).
        $consulta = $pdo->prepare(
            "SELECT r.importe_total, u.propietario_id, p.cobra_iva,
                    a.administrador_id, a.porcentaje_comision,
                    (SELECT COALESCE(SUM(g.monto_pesos), 0) FROM pagos g
                      WHERE g.reserva_id = r.id AND g.estado = 'confirmado' AND g.tipo IN ('sena', 'saldo')) AS cobrado
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN propietarios p ON p.usuario_id = u.propietario_id
             JOIN estadias e ON e.reserva_id = r.id
             LEFT JOIN administraciones a ON a.unidad_id = u.id
             WHERE r.id = ? AND r.estado IN ('confirmada', 'finalizada')"
        );
        $consulta->execute([$reservaId]);
        $datos = $consulta->fetch();
        if ($datos === false) {
            return null;
        }

        $cobraIva = (bool) $datos['cobra_iva'];
        $reparto = self::calcular(
            (string) $datos['cobrado'],
            $cobraIva,
            self::parametro('iva'),
            self::parametro('comision_plataforma'),
            $datos['porcentaje_comision'] === null ? null : (string) $datos['porcentaje_comision']
        );

        //RN-16, "no se liquida lo que todavía no se cobró": si falta parte del saldo, la liquidación se calcula
        //igual pero queda retenida, y el propietario ve cuánto falta.
        //Desde la decisión #25 de docs/pagos-decisiones.md (no se registra el ingreso sin el saldo pago) esto
        //casi no debería pasar: queda como red de seguridad para las estadías registradas antes de esa regla
        //y para cualquier caso en el que lo cobrado no llegue al total de la reserva.
        $estado = self::aCentesimas((string) $datos['cobrado']) < self::aCentesimas((string) $datos['importe_total'])
            ? 'retenida_por_deuda'
            : 'pendiente';

        try {
            $insercion = $pdo->prepare(
                'INSERT INTO liquidaciones (reserva_id, propietario_id, administrador_id, cobra_iva,
                                            importe_cobrado, porcentaje_iva, importe_iva, base_comisiones,
                                            porcentaje_comision_plataforma, comision_plataforma,
                                            porcentaje_comision_administrador, comision_administrador,
                                            importe_propietario, estado, creado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insercion->execute([
                $reservaId,
                $datos['propietario_id'],
                $datos['administrador_id'],
                $cobraIva ? 1 : 0,
                $reparto['importe_cobrado'],
                $reparto['porcentaje_iva'],
                $reparto['importe_iva'],
                $reparto['base_comisiones'],
                $reparto['porcentaje_comision_plataforma'],
                $reparto['comision_plataforma'],
                $reparto['porcentaje_comision_administrador'],
                $reparto['comision_administrador'],
                $reparto['importe_propietario'],
                $estado,
                //"Ahora" en UTC calculado en PHP, como en el resto del back: la base local puede tener otra zona horaria
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {   //clave duplicada: otro proceso la liquidó primero
                return null;
            }
            throw $e;
        }

        return (int) $pdo->lastInsertId();
    }

    //El reparto de un importe cobrado, con los porcentajes como texto ("21", "7.5", "15.00").
    //$porcentajeAdministrador en null = unidad de autogestión, sin comisión de administrador.
    //Devuelve todos los importes como texto con dos decimales, listos para guardar.
    //
    //Ejemplo del comprobante que pidió el contador: $425.000 cobrados a una propietaria que discrimina IVA,
    //con el 7,5 % de la plataforma y el 10 % del administrador, dan $73.760,33 de IVA, $351.239,67 de base,
    //$26.342,98 y $35.123,97 de comisiones, y $363.533,05 para la propietaria.
    public static function calcular(
        string $importeCobrado,
        bool $cobraIva,
        string $porcentajeIva,
        string $porcentajePlataforma,
        ?string $porcentajeAdministrador
    ): array {
        $cobrado = self::aCentesimas($importeCobrado);

        //El IVA viene incluido en el precio: el neto es el total dividido 1,21 (con el IVA al 21 %).
        //El IVA se calcula por resta, así base + IVA siempre da exactamente el total cobrado.
        $base = $cobraIva
            ? self::dividir($cobrado * 10000, 10000 + self::aCentesimas($porcentajeIva))
            : $cobrado;
        $iva = $cobrado - $base;

        $comisionPlataforma = self::dividir($base * self::aCentesimas($porcentajePlataforma), 10000);
        $comisionAdministrador = $porcentajeAdministrador === null
            ? 0
            : self::dividir($base * self::aCentesimas($porcentajeAdministrador), 10000);

        return [
            'importe_cobrado'                   => self::aPesos($cobrado),
            'porcentaje_iva'                    => $cobraIva ? $porcentajeIva : '0',
            'importe_iva'                       => self::aPesos($iva),
            'base_comisiones'                   => self::aPesos($base),
            'porcentaje_comision_plataforma'    => $porcentajePlataforma,
            'comision_plataforma'               => self::aPesos($comisionPlataforma),
            'porcentaje_comision_administrador' => $porcentajeAdministrador,
            'comision_administrador'            => self::aPesos($comisionAdministrador),
            'importe_propietario'               => self::aPesos($cobrado - $comisionPlataforma - $comisionAdministrador),
        ];
    }

    //Cuánto falta cobrar de una reserva, como texto, o null si está todo cobrado.
    //Es el motivo por el que una liquidación queda retenida (RN-16).
    public static function faltante(string $importeTotal, string $importeCobrado): ?string
    {
        $falta = self::aCentesimas($importeTotal) - self::aCentesimas($importeCobrado);
        return $falta > 0 ? self::aPesos($falta) : null;
    }

    //Un parámetro global de la plataforma, como texto. Si falta, la liquidación no se puede calcular.
    private static function parametro(string $clave): string
    {
        $consulta = Conexion::obtener()->prepare('SELECT valor FROM parametros WHERE clave = ?');
        $consulta->execute([$clave]);
        $valor = $consulta->fetchColumn();
        if ($valor === false || !is_numeric($valor) || (float) $valor < 0) {
            throw new \RuntimeException("Falta el parámetro $clave (o no es un número positivo)");
        }
        return (string) $valor;
    }

    //"425000.00" → 42500000 (centavos). También sirve para los porcentajes: "7.5" → 750 (centésimas de punto).
    //Se parte el texto en vez de multiplicar por 100 en coma flotante, así "0.07" no termina en 6 centavos.
    private static function aCentesimas(string $numero): int
    {
        [$entera, $decimal] = array_pad(explode('.', trim($numero), 2), 2, '0');
        $decimal = substr(str_pad($decimal, 2, '0'), 0, 2);
        $negativo = str_starts_with($entera, '-');
        $centesimas = (int) ltrim($entera, '+-') * 100 + (int) $decimal;
        return $negativo ? -$centesimas : $centesimas;
    }

    //42500000 (centavos) → "425000.00", el formato con el que la base guarda los importes
    private static function aPesos(int $centavos): string
    {
        return sprintf('%s%d.%02d', $centavos < 0 ? '-' : '', intdiv(abs($centavos), 100), abs($centavos) % 100);
    }

    //División entera con el medio para arriba, sin coma flotante: dividir(7, 2) da 4 y dividir(5, 2) da 3
    private static function dividir(int $numerador, int $divisor): int
    {
        return intdiv($numerador * 2 + $divisor, $divisor * 2);
    }
}
