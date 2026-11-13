<?php

namespace App\Validacion;

use App\Database\Conexion;

//Las reservas de prueba que quedaron de cuando los pagos eran simulados (decisión #53 de docs/pagos-decisiones.md).
//Se borran una sola vez, antes de pasar a los pagos reales: en el contrato no hay nada de ellas, así que con el
//firmador real su devolución o su liquidación fallarían siempre (SinSena, FondosInsuficientes) y quedarían pendientes.
//
//Es simulada una reserva que:
//  - tiene algún pago de otra red que la real (los simulados se guardan con red 31337), o
//  - está confirmada o finalizada sin una seña confirmada en la red real (las de 0 % de seña, que nacían confirmadas
//    sin pagar nada, o las que se confirmaron con un pago simulado que ya no está).
//Las pendientes, vencidas y canceladas sin pagos no molestan: no se tocan.
//La usa scripts/borrar-reservas-simuladas.php.
class ReservasSimuladas
{
    //Las tablas que apuntan a reservas, en el orden en que se borran (todas antes que la reserva)
    private const TABLAS_HIJAS = ['pagos', 'recordatorios_saldo', 'liquidaciones', 'huespedes_reserva', 'estadias'];

    //Las reservas simuladas, de la más vieja a la más nueva, con lo necesario para mostrarlas antes de borrarlas.
    //$redReal: el chain id de los pagos reales (84532, Base Sepolia).
    public static function listar(int $redReal): array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT r.id, r.estado, r.fecha_ingreso, r.fecha_egreso, u.nombre AS unidad, h.mail AS huesped,
                    (SELECT COUNT(*) FROM pagos p WHERE p.reserva_id = r.id) AS pagos
             FROM reservas r
             JOIN unidades u ON u.id = r.unidad_id
             JOIN usuarios h ON h.id = r.huesped_id
             WHERE EXISTS (SELECT 1 FROM pagos p WHERE p.reserva_id = r.id AND p.red <> :red1)
                OR (r.estado IN ('confirmada', 'finalizada')
                    AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.reserva_id = r.id AND p.tipo = 'sena'
                                    AND p.estado = 'confirmado' AND p.red = :red2))
             ORDER BY r.id"
        );
        $consulta->execute(['red1' => $redReal, 'red2' => $redReal]);
        return $consulta->fetchAll();
    }

    //Borra esas reservas con todo lo que cuelga de ellas, en una sola transacción: o se borran todas, o ninguna.
    //Devuelve cuántas reservas borró.
    //Las extensiones (reservas.extiende_reserva_id, decisión #70) se borran antes que las demás: apuntan a la
    //reserva que extienden con una clave foránea sin ON DELETE, así que si se borrara la original primero, MySQL
    //rechazaría el borrado y no se borraría nada. Una extensión no se puede extender, así que un paso alcanza.
    public static function borrar(array $ids): int
    {
        $ids = array_values(array_map('intval', $ids));
        if ($ids === []) {
            return 0;
        }
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            foreach (self::TABLAS_HIJAS as $tabla) {//el nombre sale de la constante, nunca de afuera
                $pdo->prepare("DELETE FROM {$tabla} WHERE reserva_id IN ($marcas)")->execute($ids);
            }
            //si una extensión que NO es simulada apunta a una que sí, se la desvincula: la original se va igual
            //y la extensión queda como una reserva suelta, que es mejor que no poder borrar nada
            $pdo->prepare("UPDATE reservas SET extiende_reserva_id = NULL
                           WHERE extiende_reserva_id IN ($marcas) AND id NOT IN ($marcas)")
                ->execute([...$ids, ...$ids]);
            $extensiones = $pdo->prepare("DELETE FROM reservas WHERE id IN ($marcas) AND extiende_reserva_id IS NOT NULL");
            $extensiones->execute($ids);
            $consulta = $pdo->prepare("DELETE FROM reservas WHERE id IN ($marcas)");
            $consulta->execute($ids);
            $borradas = $extensiones->rowCount() + $consulta->rowCount();
            $pdo->commit();
            return $borradas;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
