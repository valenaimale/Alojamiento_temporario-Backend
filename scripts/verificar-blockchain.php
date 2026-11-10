<?php

//Controla que la configuración de los pagos reales esté bien ANTES de activarlos, y que siga bien después
//(decisión #55 de docs/pagos-decisiones.md). Solo lee la red: no firma ni manda nada, y no gasta gas.
//
//Desde la raíz del backend (en producción, adentro de `railway ssh`):
//
//    php scripts/verificar-blockchain.php
//
//Controla:
//  1. que el RPC (BLOCKCHAIN_RPC_URL) responda y sea de la red configurada (BLOCKCHAIN_RED, 84532 por defecto);
//  2. que en CONTRATO_DIRECCION haya un contrato desplegado;
//  3. que la dueña del contrato (owner()) sea la billetera de PLATAFORMA_CLAVE_PRIVADA. Si no, devolver y liquidar
//     fallan siempre con OwnableUnauthorizedAccount. La clave NUNCA se muestra: solo la dirección pública que sale de ella;
//  4. que esa billetera tenga ETH para pagar el gas.
//Muestra también PAGOS_VERIFICADOR y PAGOS_FIRMADOR. Termina con código 1 si algo está mal.
//La carpeta scripts/ está fuera de index/, así que el servidor web no la expone.

use App\Config\Config;
use App\Validacion\ClienteRpc;
use App\Validacion\FirmaEthereum;
use App\Validacion\VerificadorBlockchain;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

const SELECTOR_OWNER = '0x8da5cb5b';//owner() de Ownable (OpenZeppelin)
const GAS_MINIMO_WEI = '10000000000000';//0,00001 ETH: alcanza para varias decenas de devolver o liquidar

$problemas = 0;

//Muestra una línea con OK o MAL y cuenta los problemas
$informar = function (bool $bien, string $texto) use (&$problemas): void {
    echo ($bien ? '  OK  ' : '  MAL ') . $texto . "\n";
    if (!$bien) {
        $problemas++;
    }
};

//Wei (texto decimal) a ETH con 8 decimales, para mostrar
$enEth = fn (string $wei): string => bcdiv($wei, '1000000000000000000', 8);

echo "Modo de los pagos:\n";
echo '  PAGOS_VERIFICADOR = ' . Config::obtener('pagos.verificador', 'simulado') . "\n";
echo '  PAGOS_FIRMADOR    = ' . Config::obtener('pagos.firmador', 'simulado') . "\n";

$red = (int) Config::obtener('blockchain.red', VerificadorBlockchain::RED_BASE_SEPOLIA);
$contrato = strtolower((string) Config::obtener('contrato.direccion', ''));
$rpc = new ClienteRpc();

echo "\nRed y contrato:\n";
try {
    $chainId = (int) ClienteRpc::hexADecimal((string) $rpc->llamar('eth_chainId'));
    $informar($chainId === $red, "el RPC es de la red $chainId (se espera la $red)");
} catch (\Throwable $e) {
    $informar(false, 'el RPC no responde: ' . $e->getMessage());
    echo "\nNo se puede seguir sin RPC.\n";
    exit(1);
}

if (!ClienteRpc::esDireccion($contrato)) {
    $informar(false, 'CONTRATO_DIRECCION no está configurada o no es una dirección');
    exit(1);
}
$codigo = (string) $rpc->llamar('eth_getCode', [$contrato, 'latest']);
$informar(strlen($codigo) > 2, "hay un contrato en $contrato");

$owner = null;
try {
    $respuesta = (string) $rpc->llamar('eth_call', [['to' => $contrato, 'data' => SELECTOR_OWNER], 'latest']);
    $owner = '0x' . substr($respuesta, -40);//la dirección son los últimos 20 bytes de la palabra
    echo "  la dueña del contrato es $owner\n";
} catch (\Throwable $e) {
    $informar(false, 'no se pudo leer owner() del contrato: ' . $e->getMessage());
}

echo "\nBilletera de la plataforma:\n";
$clave = (string) Config::obtener('plataforma.clave_privada', '');
if ($clave === '') {
    $informar(false, 'PLATAFORMA_CLAVE_PRIVADA no está configurada');
} else {
    try {
        $plataforma = (new FirmaEthereum($clave))->direccion();//solo la dirección pública; la clave no se muestra
        echo "  la clave configurada es de la billetera $plataforma\n";
        if ($owner !== null) {
            $informar($plataforma === strtolower($owner), 'la clave es la de la dueña del contrato');
        }
        $saldo = ClienteRpc::hexADecimal((string) $rpc->llamar('eth_getBalance', [$plataforma, 'latest']));
        $informar(bccomp($saldo, GAS_MINIMO_WEI, 0) >= 0, 'tiene ' . $enEth($saldo) . ' ETH para el gas');
    } catch (\Throwable $e) {
        $informar(false, $e->getMessage());
    }
}

echo $problemas === 0 ? "\nTodo bien.\n" : "\nHay $problemas problema(s).\n";
exit($problemas === 0 ? 0 : 1);
