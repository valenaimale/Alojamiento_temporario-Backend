<?php

namespace App\Validacion;

use App\Database\Conexion;

//Datos fiscales y bancarios de propietarios y administradores (DER v2, punto 19).
//Los dos roles tienen los mismos datos, así que se validan y se guardan en un solo lugar:
//lo usan el registro (RegistroController) y "hacerme propietario" (PropietarioController).
//  - cobra_iva: siempre. Si cobra IVA, también cuit, razon_social y domicilio_fiscal.
//  - cbu y titular_cuenta: siempre (donde cobra lo que se le liquida en pesos). alias: opcional.
//  - billetera: siempre (donde cobra en ETH lo que le liquida el contrato PasarelaPago).
class DatosFiscales
{
    //Las únicas tablas donde se guardan: el nombre de una tabla no se puede pasar como parámetro de PDO,
    //así que se controla contra esta lista antes de armar el SQL.
    private const TABLAS = ['propietario' => 'propietarios', 'administrador' => 'administradores'];

    //Valida los datos que llegan en el JSON y los deja listos para guardar.
    //Si algo está mal devuelve ['error' => 'mensaje'].
    //Si está bien devuelve cobra_iva (0 o 1), cuit, razon_social, domicilio_fiscal, cbu, alias, titular_cuenta y billetera,
    //con los datos fiscales en null cuando no cobra IVA y el alias en null cuando no lo cargó.
    public static function validar(array $datos): array
    {
        $cobraIva = $datos['cobra_iva'] ?? '';
        if ($cobraIva !== '0' && $cobraIva !== '1') {
            return ['error' => 'Indicá si cobrás IVA'];
        }

        $fiscales = ['cobra_iva' => 0, 'cuit' => null, 'razon_social' => null, 'domicilio_fiscal' => null];
        if ($cobraIva === '1') {//si cobra IVA se piden el CUIT, la razón social y el domicilio fiscal
            $cuit = ValidadorDocumentos::normalizarCuit(trim($datos['cuit'] ?? ''));//"20-30123456-3" pasa a "20301234563"
            $razonSocial = trim($datos['razon_social'] ?? '');
            $domicilioFiscal = trim($datos['domicilio_fiscal'] ?? '');

            if (!ValidadorDocumentos::esCuitValido($cuit)) {
                return ['error' => 'El CUIT no es válido'];
            }
            if (mb_strlen($razonSocial) < 2 || mb_strlen($razonSocial) > 150) {
                return ['error' => 'La razón social debe tener entre 2 y 150 caracteres'];
            }
            if (mb_strlen($domicilioFiscal) < 5 || mb_strlen($domicilioFiscal) > 200) {
                return ['error' => 'El domicilio fiscal debe tener entre 5 y 200 caracteres'];
            }
            $fiscales = ['cobra_iva' => 1, 'cuit' => $cuit, 'razon_social' => $razonSocial, 'domicilio_fiscal' => $domicilioFiscal];
        }

        //la cuenta bancaria se pide siempre
        $cbu = ValidadorDocumentos::normalizarCbu(trim($datos['cbu'] ?? ''));//"2850590 940090418135201" pasa a "2850590940090418135201"
        $alias = ValidadorDocumentos::normalizarAlias($datos['alias'] ?? '');//"Casa.Playa.Sol" pasa a "casa.playa.sol"
        $titular = trim($datos['titular_cuenta'] ?? '');

        if (preg_match('/^\d{22}$/', $cbu) !== 1) {
            return ['error' => 'El CBU tiene que tener 22 números'];
        }
        if (!ValidadorDocumentos::esCbuValido($cbu)) {
            return ['error' => 'El CBU no es válido: revisá que esté bien escrito'];
        }
        if ($alias !== '' && !ValidadorDocumentos::esAliasValido($alias)) {
            return ['error' => 'El alias debe tener entre 6 y 20 caracteres: letras sin tilde, números, puntos o guiones'];
        }
        if (mb_strlen($titular) < 2 || mb_strlen($titular) > 150) {
            return ['error' => 'El titular de la cuenta debe tener entre 2 y 150 caracteres'];
        }

        $billetera = self::validarBilletera($datos['billetera'] ?? '');
        if (isset($billetera['error'])) {
            return $billetera;
        }

        return $fiscales + ['cbu' => $cbu, 'alias' => $alias === '' ? null : $alias, 'titular_cuenta' => $titular]
            + $billetera;
    }

    //Valida la billetera de cobro. Devuelve ['billetera' => '0x…'] en minúsculas, o ['error' => 'mensaje'].
    //La usan validar() y el cambio de billetera desde el perfil (PropietarioController::billetera).
    public static function validarBilletera(mixed $billetera): array
    {
        $billetera = ValidadorDocumentos::normalizarBilletera(is_string($billetera) ? $billetera : '');
        if (!ValidadorDocumentos::esBilleteraValida($billetera)) {
            return ['error' => 'La billetera tiene que ser una dirección de Ethereum: 0x y 40 caracteres (copiala de MetaMask)'];
        }
        return ['billetera' => $billetera];
    }

    //Cambia (o carga por primera vez) la billetera de cobro del usuario, en propietarios o en administradores según $rol.
    //Devuelve false si el usuario no tiene su fila de datos fiscales.
    public static function cambiarBilletera(string $rol, int $usuarioId, string $billetera): bool
    {
        $tabla = self::TABLAS[$rol] ?? throw new \InvalidArgumentException("El rol $rol no tiene datos fiscales");
        $consulta = Conexion::obtener()->prepare("UPDATE {$tabla} SET billetera = ? WHERE usuario_id = ?");
        $consulta->execute([$billetera, $usuarioId]);
        //rowCount() cuenta las filas cambiadas: si la billetera era la misma da 0, así que se busca la fila
        return $consulta->rowCount() > 0 || self::buscar($rol, $usuarioId) !== null;
    }

    //Devuelve true si el CUIT ya lo tiene un propietario o un administrador.
    //Se controla en las dos tablas porque una persona (un DNI) tiene una sola cuenta, con un solo rol.
    public static function cuitRegistrado(?string $cuit): bool
    {
        if ($cuit === null) {//no cobra IVA: no hay CUIT para controlar
            return false;
        }
        $consulta = Conexion::obtener()->prepare(
            'SELECT 1 FROM propietarios WHERE cuit = ? UNION SELECT 1 FROM administradores WHERE cuit = ? LIMIT 1'
        );
        $consulta->execute([$cuit, $cuit]);
        return (bool) $consulta->fetchColumn();//fetchColumn devuelve false si no encontró ninguna fila
    }

    //Crea la fila del usuario en propietarios o en administradores (según $rol), con lo que devolvió validar().
    //Usa la misma conexión que quien la llama, así que queda dentro de su transacción.
    public static function guardar(string $rol, int $usuarioId, array $datos): void
    {
        $tabla = self::TABLAS[$rol] ?? throw new \InvalidArgumentException("El rol $rol no tiene datos fiscales");
        $consulta = Conexion::obtener()->prepare(
            "INSERT INTO {$tabla} (usuario_id, cobra_iva, cuit, razon_social, domicilio_fiscal, cbu, alias, titular_cuenta, billetera)
             VALUES (:usuario_id, :cobra_iva, :cuit, :razon_social, :domicilio_fiscal, :cbu, :alias, :titular_cuenta, :billetera)"
        );
        $consulta->execute([
            'usuario_id'       => $usuarioId,
            'cobra_iva'        => $datos['cobra_iva'],
            'cuit'             => $datos['cuit'],
            'razon_social'     => $datos['razon_social'],
            'domicilio_fiscal' => $datos['domicilio_fiscal'],
            'cbu'              => $datos['cbu'],
            'alias'            => $datos['alias'],
            'titular_cuenta'   => $datos['titular_cuenta'],
            'billetera'        => $datos['billetera'],
        ]);
    }

    //Los datos fiscales y bancarios de un usuario, para mostrarlos (perfil y backoffice).
    //billetera es null si es un usuario de antes de la billetera y todavía no la cargó.
    //Devuelve null si el rol no tiene datos fiscales o si el usuario no tiene su fila.
    public static function buscar(string $rol, int $usuarioId): ?array
    {
        $tabla = self::TABLAS[$rol] ?? null;
        if ($tabla === null) {
            return null;
        }
        $consulta = Conexion::obtener()->prepare(
            "SELECT cobra_iva, cuit, razon_social, domicilio_fiscal, cbu, alias, titular_cuenta, billetera FROM {$tabla} WHERE usuario_id = ?"
        );
        $consulta->execute([$usuarioId]);
        $fila = $consulta->fetch();//devuelve false si no encontró ninguna fila
        if (!$fila) {
            return null;
        }
        $fila['cobra_iva'] = (bool) $fila['cobra_iva'];//MySQL lo devuelve como 1 o 0
        return $fila;
    }
}
