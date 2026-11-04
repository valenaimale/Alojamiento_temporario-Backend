<?php

namespace App\Controllers;

use App\Database\Conexion;
use App\Mail\RecuperacionContrasenia;
use App\Request\Request;

final class RecuperacionContraseniaController
{
    private const RESPUESTA = 'Si hay una cuenta con ese mail y contraseña, te vamos a enviar un enlace para recuperar el acceso.';
    private const ENLACE_INVALIDO = 'El enlace venció o ya no es válido. Pedí uno nuevo.';

    public function solicitar(): void
    {
        header('Cache-Control: no-store');
        $datos = Request::datosJson();
        $mail = $datos['mail'] ?? null;
        if (!is_string($mail) || strlen($mail) > 254 || !filter_var(trim($mail), FILTER_VALIDATE_EMAIL)) {
            $this->responder(['error' => 'Ingresá un mail válido'], 422);
            return;
        }
        $mail = strtolower(trim($mail));
        // REMOTE_ADDR no permite que el cliente eluda el límite inventando X-Forwarded-For.
        $ipPermitida = $this->permitir('ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 20, 900);
        $mailPermitido = $ipPermitida && $this->permitir('mail:' . $mail, 1, 60);
        // La limitación por mail también es silenciosa: no revela si la cuenta existe.
        $this->responder(['ok' => self::RESPUESTA], 200, true);
        if ($mailPermitido) {
            RecuperacionContrasenia::enviarEnlace($mail);
        }
    }

    public function restablecer(): void
    {
        header('Cache-Control: no-store');
        $datos = Request::datosJson();
        $token = $datos['token'] ?? null;
        $clave = $datos['contrasenia'] ?? null;
        $confirmacion = $datos['confirmar_contrasenia'] ?? null;
        if (!is_string($token) || !preg_match('/\A[0-9a-f]{64}\z/', $token)) {
            $this->responder(['error' => self::ENLACE_INVALIDO, 'codigo' => 'enlace_invalido'], 422);
            return;
        }
        // Misma política que el registro; bcrypt cuenta bytes, no caracteres Unicode.
        if (!is_string($clave) || strlen($clave) < 8 || strlen($clave) > 72) {
            $this->responder(['error' => 'La contraseña debe tener entre 8 y 72 bytes (las tildes y emojis ocupan más de uno)'], 422);
            return;
        }
        if ($confirmacion !== $clave) {
            $this->responder(['error' => 'Las contraseñas no coinciden'], 422);
            return;
        }
        $pdo = Conexion::obtener();
        $hash = hash('sha256', $token);
        $consulta = $pdo->prepare('SELECT usuario_id FROM recuperaciones_contrasenia WHERE token_hash = ?');
        $consulta->execute([$hash]);
        $usuarioId = $consulta->fetchColumn();
        if ($usuarioId === false) {
            $this->responder(['error' => self::ENLACE_INVALIDO, 'codigo' => 'enlace_invalido'], 422);
            return;
        }
        try {
            $pdo->beginTransaction();
            // Mismo orden de bloqueos que al emitir: usuario primero, token después.
            $consulta = $pdo->prepare('SELECT id, nombre, mail, activo, contrasenia FROM usuarios WHERE id = ? FOR UPDATE');
            $consulta->execute([$usuarioId]);
            $usuario = $consulta->fetch();
            $consulta = $pdo->prepare('SELECT usuario_id FROM recuperaciones_contrasenia
                WHERE usuario_id = ? AND token_hash = ? AND expira_en > UTC_TIMESTAMP() FOR UPDATE');
            $consulta->execute([$usuarioId, $hash]);
            if (!$usuario || !(int) $usuario['activo'] || $usuario['contrasenia'] === null || !$consulta->fetchColumn()) {
                $pdo->rollBack();
                $this->responder(['error' => self::ENLACE_INVALIDO, 'codigo' => 'enlace_invalido'], 422);
                return;
            }
            $pdo->prepare('UPDATE usuarios SET contrasenia = ?, version_sesion = version_sesion + 1 WHERE id = ?')
                ->execute([password_hash($clave, PASSWORD_DEFAULT), $usuarioId]);
            $pdo->prepare('DELETE FROM recuperaciones_contrasenia WHERE usuario_id = ?')->execute([$usuarioId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->responder(['ok' => 'Tu contraseña se actualizó. Iniciá sesión con la nueva contraseña.'], 200, true);
        RecuperacionContrasenia::notificarCambio($usuario);
    }

    // Incremento atómico en MySQL: los límites también funcionan con varias réplicas.
    private function permitir(string $identificador, int $limite, int $segundos): bool
    {
        $pdo = Conexion::obtener();
        $clave = hash('sha256', $identificador);
        // Limpieza en autocommit: no retener bloqueos de rango al insertar contadores concurrentes.
        $pdo->exec('DELETE FROM limites_recuperacion WHERE inicio < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 100');
        $pdo->beginTransaction();
        try {
            $consulta = $pdo->prepare("INSERT INTO limites_recuperacion (clave, inicio, intentos) VALUES (?, UTC_TIMESTAMP(), 1)
                ON DUPLICATE KEY UPDATE
                intentos = IF(inicio <= UTC_TIMESTAMP() - INTERVAL $segundos SECOND, 1, LEAST(intentos + 1, 1000000)),
                inicio = IF(inicio <= UTC_TIMESTAMP() - INTERVAL $segundos SECOND, UTC_TIMESTAMP(), inicio)");
            $consulta->execute([$clave]);
            $consulta = $pdo->prepare('SELECT intentos FROM limites_recuperacion WHERE clave = ?');
            $consulta->execute([$clave]);
            $permitido = (int) $consulta->fetchColumn() <= $limite;
            $pdo->commit();
            return $permitido;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function responder(array $datos, int $codigo, bool $finalizar = false): void
    {
        http_response_code($codigo);
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE);
        if ($finalizar) {
            // El cliente recibe la respuesta completa antes del correo. No requiere otro worker.
            ignore_user_abort(true);
            session_write_close();
            header('Content-Length: ' . strlen($json));
            header('Connection: close');
        }
        echo $json;
        if ($finalizar) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            }
        }
    }
}
