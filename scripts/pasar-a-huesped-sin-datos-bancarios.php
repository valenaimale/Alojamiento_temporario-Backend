<?php

//Prepara una base de DESARROLLO para la migración AgregarDatosBancariosYAdministradores.
//Los propietarios y administradores que ya existen son cuentas de prueba sin CBU ni titular de la cuenta,
//y la migración no los puede completar. Este script los pasa a huésped (no borra ninguna cuenta):
//  - propietarios: se borra su fila en propietarios y el rol pasa a 'huesped'.
//  - administradores: el rol pasa a 'huesped'.
//Después pueden volver a ser propietarios desde "Quiero ser propietario", cargando todos los datos.
//Se corre por consola, desde la raíz del backend, ANTES de migrar:
//
//    php scripts/pasar-a-huesped-sin-datos-bancarios.php
//    vendor/bin/phinx migrate
//
//En producción no hace falta: no hay propietarios ni administradores.

use App\Database\Conexion;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$pdo = Conexion::obtener();

//si la migración ya corrió, las cuentas nuevas tienen sus datos: no hay nada que hacer
$migrada = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                        WHERE table_schema = DATABASE() AND table_name = 'administradores'")->fetchColumn();
if ((int) $migrada > 0) {
    echo "La migración ya corrió en esta base: no hay nada que hacer.\n";
    exit(0);
}

$cuentas = $pdo->query(
    "SELECT id, mail, rol FROM usuarios WHERE rol IN ('propietario', 'administrador') ORDER BY rol, id"
)->fetchAll();

if (count($cuentas) === 0) {
    echo "No hay propietarios ni administradores: ya podés migrar.\n";
    exit(0);
}

echo "Base: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "Estas cuentas van a pasar a huésped:\n";
foreach ($cuentas as $cuenta) {
    echo "  {$cuenta['id']}  {$cuenta['rol']}  {$cuenta['mail']}\n";
}
echo '¿Seguir? Escribí "si" para confirmar: ';
if (trim(fgets(STDIN)) !== 'si') {
    echo "No se cambió nada.\n";
    exit(1);
}

//las dos cosas juntas: no puede quedar un huésped con fila en propietarios
$pdo->beginTransaction();
try {
    $pdo->exec("DELETE FROM propietarios");
    $cambiadas = $pdo->exec("UPDATE usuarios SET rol = 'huesped' WHERE rol IN ('propietario', 'administrador')");
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "No se pudo completar: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Listo: $cambiadas cuentas pasaron a huésped. Ya podés correr 'vendor/bin/phinx migrate'.\n";
