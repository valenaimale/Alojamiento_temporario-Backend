<?php

namespace App\Validacion;

use App\Database\Conexion;

//Borra TODAS las reservas, para cambiar de contrato (decisión #76 de docs/pagos-decisiones.md: la reserva grupal
//necesita un contrato nuevo, con pagarSenaGrupal y pagarSaldoGrupal).
//
//Por qué todas: el ETH de cada reserva quedó en el contrato anterior, y el back habla con un solo contrato
//(CONTRATO_DIRECCION). Con el contrato nuevo, devolver o liquidar una reserva vieja fallaría siempre (SinSena,
//FondosInsuficientes) y quedaría reintentándose en el cron. Al 20/11 todas las reservas de producción son de prueba.
//
//Borra cada reserva con todo lo que cuelga de ella (ReservasSimuladas::borrar: pagos, recordatorios, liquidaciones,
//libro de huéspedes, estadías y extensiones) y después los grupos de las reservas grupales. Las unidades, los usuarios,
//las unidades grupales, los precios y los bloqueos no se tocan.
//La usa scripts/borrar-reservas-de-prueba.php.
class ReservasDePrueba
{
    //Cuántas reservas hay en cada estado y cuántos pagos, grupos y liquidaciones se irían con ellas
    public static function resumen(): array
    {
        $pdo = Conexion::obtener();
        return [
            'por_estado'    => $pdo->query('SELECT estado, COUNT(*) FROM reservas GROUP BY estado ORDER BY estado')->fetchAll(\PDO::FETCH_KEY_PAIR),
            'reservas'      => (int) $pdo->query('SELECT COUNT(*) FROM reservas')->fetchColumn(),
            'pagos'         => (int) $pdo->query('SELECT COUNT(*) FROM pagos')->fetchColumn(),
            'liquidaciones' => (int) $pdo->query('SELECT COUNT(*) FROM liquidaciones')->fetchColumn(),
            'grupos'        => (int) $pdo->query('SELECT COUNT(*) FROM grupos_reserva')->fetchColumn(),
        ];
    }

    //Borra todas las reservas con lo que cuelga de ellas y los grupos que quedan vacíos.
    //Las reservas se borran en una transacción (o todas o ninguna); los grupos, después. Devuelve cuántas reservas borró.
    public static function borrarTodas(): int
    {
        $pdo = Conexion::obtener();
        $ids = $pdo->query('SELECT id FROM reservas ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $borradas = ReservasSimuladas::borrar($ids);
        //un grupo sin reservas no sirve para nada (reservas.grupo_id apunta al grupo, así que va después)
        $pdo->exec('DELETE FROM grupos_reserva WHERE NOT EXISTS (SELECT 1 FROM reservas r WHERE r.grupo_id = grupos_reserva.id)');
        return $borradas;
    }
}
