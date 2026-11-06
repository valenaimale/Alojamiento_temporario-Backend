<?php

namespace App\Validacion;

//Lo único que PagosController sabe de la red: si una transacción pagó lo que tenía que pagar.
//El huésped paga desde su billetera (MetaMask) llamando a pagarSena(reservaId) del contrato, y el front le pasa al back
//el hash de la transacción; el verificador la busca y controla que sea un pago de esa reserva, de ese tipo y por ese monto.
//Hoy existe VerificadorSimulado. Blockchain.php (RPC eth_getTransactionReceipt y el evento PagoRealizado del contrato)
//va a implementar esta misma interfaz: el controlador y la base no cambian, solo Verificadores::configurado().
interface VerificadorPagos
{
    //El chain id que se guarda en pagos.red (84532 = Base Sepolia)
    public function red(): int;

    //Lo que necesita el front para armar la transacción, además del monto (por ejemplo, la dirección del contrato)
    public function datosParaPagar(int $reservaId, string $tipo, string $montoWei): array;

    //Verifica la transacción $txHash. Lanza una excepción solo si no se pudo consultar la red:
    //una transacción fallida o que no corresponde es un resultado (fallido), no un error del servidor.
    public function verificar(string $txHash, int $reservaId, string $tipo, string $montoWei): ResultadoVerificacion;
}
