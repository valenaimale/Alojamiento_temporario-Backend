<?php

namespace App\Validacion;

use App\Database\Conexion;

//Verificar el mail ANTES de crear la cuenta (tabla registros_pendientes, ver su migración).
//  1. POST /registrarse valida los datos y llama a guardar(): queda un registro pendiente y sale el mail con el enlace.
//  2. POST /verificar-mail llama a confirmar() con el token del enlace: recién ahí se crea la cuenta.
//  3. POST /reenviar-registro llama a reenviar(): un enlace nuevo, como mucho uno por minuto.
//  4. La tarea diaria llama a borrarVencidos().
//Del token se guarda solo el hash (sha256), igual que en verificaciones_mail: quien lea la tabla no puede usar los enlaces.
class RegistroPendiente
{
    public const HORAS_DE_VIGENCIA = 24;
    private const SEGUNDOS_ENTRE_MAILS = 60;

    //Guarda (o reemplaza, si ese mail ya tenía uno) el registro pendiente y devuelve el token del enlace.
    //$datos: nombre, mail, dni, rol y contrasenia (ya validados; la contraseña en texto plano: se hashea acá),
    //y datos_fiscales (lo que devolvió DatosFiscales::validar, o null para huésped y operador).
    public static function guardar(array $datos): string
    {
        $token = self::tokenNuevo();
        //ON DUPLICATE KEY UPDATE: si el mail ya tenía un registro pendiente, se pisa entero (decisión del 19/11):
        //el último que se registró es el que vale, y el enlace anterior deja de servir porque cambia el hash
        $consulta = Conexion::obtener()->prepare(
            'INSERT INTO registros_pendientes (mail, nombre, dni, contrasenia, rol, datos_fiscales, token_hash, expira_en, enviado_en)
             VALUES (:mail, :nombre, :dni, :contrasenia, :rol, :datos_fiscales, :token_hash,
                     DATE_ADD(NOW(), INTERVAL ' . self::HORAS_DE_VIGENCIA . ' HOUR), NOW())
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), dni = VALUES(dni), contrasenia = VALUES(contrasenia),
                 rol = VALUES(rol), datos_fiscales = VALUES(datos_fiscales), token_hash = VALUES(token_hash),
                 expira_en = VALUES(expira_en), enviado_en = VALUES(enviado_en), creado_en = NOW()'
        );
        $consulta->execute([
            'mail'           => $datos['mail'],
            'nombre'         => $datos['nombre'],
            'dni'            => $datos['dni'],
            'contrasenia'    => password_hash($datos['contrasenia'], PASSWORD_DEFAULT),
            'rol'            => $datos['rol'],
            'datos_fiscales' => $datos['datos_fiscales'] === null ? null : json_encode($datos['datos_fiscales']),
            'token_hash'     => hash('sha256', $token),
        ]);
        return $token;
    }

    //Genera un enlace nuevo para el registro pendiente de ese mail y extiende su vencimiento.
    //Devuelve ['nombre', 'token'] para mandar el mail, o null si no hay un registro pendiente con ese mail
    //o si el último mail salió hace menos de un minuto (así nadie usa el reenvío para llenarle la casilla a otro).
    public static function reenviar(string $mail): ?array
    {
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare(
            'SELECT id, nombre FROM registros_pendientes
             WHERE mail = ? AND enviado_en <= NOW() - INTERVAL ' . self::SEGUNDOS_ENTRE_MAILS . ' SECOND'
        );
        $consulta->execute([$mail]);
        $fila = $consulta->fetch();
        if (!$fila) {
            return null;
        }

        $token = self::tokenNuevo();
        $pdo->prepare(
            'UPDATE registros_pendientes SET token_hash = ?, enviado_en = NOW(),
                 expira_en = DATE_ADD(NOW(), INTERVAL ' . self::HORAS_DE_VIGENCIA . ' HOUR)
             WHERE id = ?'
        )->execute([hash('sha256', $token), $fila['id']]);
        return ['nombre' => $fila['nombre'], 'token' => $token];
    }

    //Crea la cuenta del registro pendiente de ese token (sin vencer) y borra el pendiente.
    //Devuelve:
    //  - null si el token no es de ningún registro pendiente vigente (puede ser un enlace viejo de verificaciones_mail);
    //  - ['error' => mensaje] si mientras tanto otra cuenta ocupó el mail, el DNI o el CUIT (el pendiente se borra);
    //  - ['usuario_id' => id] si la cuenta quedó creada, con el mail ya verificado.
    public static function confirmar(string $token): ?array
    {
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();
        try {
            //FOR UPDATE: si el enlace se abre dos veces al mismo tiempo, la segunda espera y después ya no lo encuentra
            $consulta = $pdo->prepare(
                'SELECT * FROM registros_pendientes WHERE token_hash = ? AND expira_en > NOW() FOR UPDATE'
            );
            $consulta->execute([hash('sha256', $token)]);
            $pendiente = $consulta->fetch();
            if (!$pendiente) {
                $pdo->rollBack();
                return null;
            }

            $fiscales = $pendiente['datos_fiscales'] === null ? null : json_decode($pendiente['datos_fiscales'], true);
            $ocupado = self::datoOcupado($pendiente['mail'], $pendiente['dni'], $fiscales['cuit'] ?? null);
            if ($ocupado !== null) {
                self::borrar((int) $pendiente['id']);
                $pdo->commit();
                return ['error' => $ocupado];
            }

            $pdo->prepare(
                'INSERT INTO usuarios (nombre, mail, dni, contrasenia, rol, mail_verificado_en)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            )->execute([$pendiente['nombre'], $pendiente['mail'], $pendiente['dni'], $pendiente['contrasenia'], $pendiente['rol']]);
            $usuarioId = (int) $pdo->lastInsertId();
            if ($fiscales !== null) {
                DatosFiscales::guardar($pendiente['rol'], $usuarioId, $fiscales);//en la misma transacción
            }
            self::borrar((int) $pendiente['id']);
            $pdo->commit();
            return ['usuario_id' => $usuarioId];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            //23000: alguien ocupó el mail, el DNI o el CUIT justo entre el control de arriba y el INSERT
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                return ['error' => 'El mail, el DNI o el CUIT ya están registrados. Si ya tenés cuenta, iniciá sesión.'];
            }
            throw $e;
        }
    }

    //Borra los registros pendientes vencidos (los corre la tarea diaria) y devuelve cuántos borró
    public static function borrarVencidos(): int
    {
        return Conexion::obtener()->exec('DELETE FROM registros_pendientes WHERE expira_en <= NOW()');
    }

    //Qué dato ya tiene otra cuenta (el mensaje para el usuario), o null si están todos libres
    private static function datoOcupado(string $mail, string $dni, ?string $cuit): ?string
    {
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare('SELECT 1 FROM usuarios WHERE mail = ? LIMIT 1');
        $consulta->execute([$mail]);
        if ($consulta->fetchColumn()) {
            return 'Ese mail ya tiene una cuenta. Iniciá sesión con tu contraseña.';
        }
        $consulta = $pdo->prepare('SELECT 1 FROM usuarios WHERE dni = ? LIMIT 1');
        $consulta->execute([$dni]);
        if ($consulta->fetchColumn()) {
            return 'Ese DNI ya tiene una cuenta. Si es tuya, iniciá sesión.';
        }
        if (DatosFiscales::cuitRegistrado($cuit)) {
            return 'Ese CUIT ya tiene una cuenta. Si es tuya, iniciá sesión.';
        }
        return null;
    }

    //Borra un registro pendiente por su id
    private static function borrar(int $id): void
    {
        Conexion::obtener()->prepare('DELETE FROM registros_pendientes WHERE id = ?')->execute([$id]);
    }

    //Un token nuevo: 64 caracteres hexadecimales, generados con el azar del sistema operativo
    private static function tokenNuevo(): string
    {
        return bin2hex(random_bytes(32));
    }
}
