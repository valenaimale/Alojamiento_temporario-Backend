<?php

//Crea un usuario con rol 'backoffice' (administración interna de la plataforma).
//Estas cuentas NO se registran desde la web: si se pudiera, cualquiera se haría
//administrador de la plataforma. Se crean por consola, desde la raíz del backend:
//
//    php scripts/crear-usuario-backoffice.php "Nombre Apellido" admin@alojamiento.local
//
//La carpeta scripts/ está fuera de index/, así que el servidor web no la expone.
//El mail queda verificado de entrada: no hace falta mandarle un mail a una cuenta interna.

use App\Database\Conexion;

//por si alguien lo deja dentro de la carpeta pública por error: desde el navegador no corre
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

//$argv[0] es el nombre del script, así que los datos empiezan en 1
$nombre = trim($argv[1] ?? '');
$mail   = strtolower(trim($argv[2] ?? ''));

if ($nombre === '' || $mail === '') {
    echo "Uso: php scripts/crear-usuario-backoffice.php \"Nombre Apellido\" mail@dominio.com\n";
    exit(1);
}
if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 70) {
    echo "El nombre debe tener entre 2 y 70 caracteres.\n";
    exit(1);
}
if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
    echo "El mail es invalido.\n";
    exit(1);
}

//la contraseña se pide por consola y no por argumento, así no queda en el historial de la terminal
echo 'Contraseña: ';
$contrasenia = trim(fgets(STDIN));
echo 'Repetí la contraseña: ';
$repetida = trim(fgets(STDIN));

//strlen y no mb_strlen: el límite de bcrypt es de 72 BYTES, no de caracteres
if (strlen($contrasenia) < 8 || strlen($contrasenia) > 72) {
    echo "La contraseña debe tener entre 8 y 72 caracteres.\n";
    exit(1);
}
if ($contrasenia !== $repetida) {
    echo "Las contraseñas no coinciden.\n";
    exit(1);
}

$pdo = Conexion::obtener();

$consulta = $pdo->prepare('SELECT 1 FROM usuarios WHERE mail = ? LIMIT 1');
$consulta->execute([$mail]);
if ($consulta->fetchColumn()) {
    echo "Ya existe una cuenta con el mail $mail.\n";
    exit(1);
}

$insercion = $pdo->prepare(
    "INSERT INTO usuarios (nombre, mail, contrasenia, rol, mail_verificado_en)
     VALUES (?, ?, ?, 'backoffice', NOW())"
);
$insercion->execute([$nombre, $mail, password_hash($contrasenia, PASSWORD_DEFAULT)]);

echo 'Usuario de backoffice creado con id ' . $pdo->lastInsertId() . "\n";
