# Alojamiento Temporario — Backend

Este es el repositorio del **backend** del sistema de alojamiento temporario. El frontend (vistas HTML, estilos y JavaScript) está en un repositorio aparte.

El backend está hecho en **PHP** (8.1 o superior) y usa **Composer** para manejar las dependencias y la carga automática de clases. No devuelve vistas: recibe las peticiones que hace el frontend y responde con datos en formato **JSON**.

---

## Composer: qué es y por qué lo usamos

[Composer](https://getcomposer.org/) es el gestor de dependencias de PHP (lo mismo que npm en JavaScript o pip en Python). En este proyecto cumple dos funciones:

1. **Instala las librerías que usamos.** Las librerías que necesita el proyecto están listadas en `composer.json`. Composer las descarga en la carpeta `vendor/` y anota la versión exacta instalada en `composer.lock`. Así todo el equipo trabaja con las mismas versiones.
2. **Carga nuestras clases automáticamente (autoload).** En `composer.json` hay un mapeo de *namespace → carpeta* (estándar PSR-4):

   ```json
   "App\\Router\\": "router/",
   "App\\Controller\\": "controllers/",
   "App\\Request\\": "request/"
   ```

   Gracias a esto no hace falta hacer `require` de cada archivo. Se incluye una sola vez `vendor/autoload.php` al inicio de la aplicación, y después cada archivo solo declara `use App\Router\Router;`. Cuando se usa una clase, el autoloader busca y carga su archivo.

### ¿Qué pasaría si no usáramos Composer?

**Para cargar nuestras clases**, habría que hacer `require` a mano de cada archivo que se usa:

```php
require __DIR__ . '/../router/Router.php';
require __DIR__ . '/../request/Request.php';
require __DIR__ . '/../controllers/HomeController.php';
// ... uno por cada clase del proyecto
```

- La ruta es relativa al archivo que hace el `require`, así que cambia según la carpeta desde donde se pida la clase (`../`, `../../`, ...).
- El orden importa: si una clase usa un trait o hereda de otra, esa otra tiene que cargarse antes.
- Si un archivo se carga dos veces, PHP falla con `Cannot redeclare class`.
- Cada clase nueva o archivo que se mueve obliga a revisar los `require` de todo el proyecto.

**Para usar librerías** (por ejemplo, Phinx), habría que descargarlas a mano, descargar también las librerías de las que dependen, hacer `require` de todos sus archivos y asegurarse de que los cuatro integrantes tengan exactamente las mismas versiones. Cada actualización implicaría repetir todo.

### ¿Por qué `vendor/` no se sube al repo?

La carpeta `vendor/` está en el `.gitignore` porque **se puede regenerar en cualquier momento** con `composer install`:

- **No es código nuestro.** Tiene las librerías de terceros y el autoloader, que Composer genera automáticamente. Lo que sí es nuestro, la lista de dependencias (`composer.json`) y sus versiones exactas (`composer.lock`), sí se sube al repo.
- **Es pesada.** Solo Phinx y sus dependencias ya son cientos de archivos. Subirla llenaría el repo y el historial de git de archivos ajenos.
- **Evita conflictos.** Si se subiera, cada `composer install` o `dump-autoload` que hiciera alguien modificaría archivos de `vendor/` y generaría conflictos de git sin sentido entre los integrantes.
- **Mantiene la consistencia sin subirla.** Como `composer.lock` fija las versiones exactas, cada persona obtiene el mismo contenido de `vendor/` al ejecutar `composer install`.

---

## Cómo levantar el proyecto

### Requisitos

- PHP 8.1 o superior
- [Composer](https://getcomposer.org/download/)
- MySQL
- La extensión **`pdo_mysql`** de PHP habilitada (ver abajo)

#### Habilitar `pdo_mysql`

PHP necesita la extensión `pdo_mysql` para conectarse a MySQL. En una instalación nueva de PHP suele venir desactivada, y Phinx falla con este error:

```
RuntimeException: You need to enable the PDO_Mysql extension for Phinx to run properly.
```

Para habilitarla:

1. Buscar qué `php.ini` usa tu PHP:

   ```bash
   php --ini
   ```

   El archivo es el que figura en `Loaded Configuration File` (por ejemplo, `C:\Php\php.ini`).
2. Abrir ese archivo, buscar la línea `;extension=pdo_mysql` y quitarle el `;` del principio (el `;` la comenta):

   ```ini
   extension=pdo_mysql
   ```

3. Verificar que quedó habilitada. Este comando tiene que mostrar `pdo_mysql`:

   ```bash
   php -m
   ```

> Si `php --ini` muestra `Loaded Configuration File: (none)`, no hay un `php.ini` activo: en la carpeta de PHP, copiar `php.ini-development` como `php.ini` y repetir el paso 2.

### Pasos

1. Clonar el repositorio y entrar a la carpeta.
2. Instalar las dependencias:

   ```bash
   composer install
   ```

   Instala exactamente las versiones que figuran en `composer.lock` y genera el autoloader.
3. Crear la configuración local copiando la plantilla:

   ```bash
   cp config/configuracion.example.php config/configuracion.php
   ```

   `config/configuracion.php` tiene datos de servicios externos (SMTP, Google), así que está en el `.gitignore` y no se sube. No se llama `config.php` porque en Windows chocaría con la clase `config/Config.php`: Windows no distingue mayúsculas en los nombres de archivo.
4. Configurar la base de datos y correr las migraciones (ver la sección [Configuración](#configuración-phinxphp-y-phinxexamplephp)).

### Después de cada `git pull`

Cuando se traen cambios de otros integrantes, puede haber librerías nuevas, clases nuevas o cambios en la base de datos. Para que todo quede al día, después de cada `git pull`:

1. **Actualizar las dependencias y el autoloader:**

   ```bash
   composer install
   ```

   Si alguien agregó una librería (cambió `composer.lock`), la instala. Además, regenera el autoloader, así se reconocen los namespaces nuevos que se hayan agregado en `composer.json`. Si no hubo cambios, no hace nada, así que se puede ejecutar siempre.
2. **Aplicar las migraciones nuevas:**

   ```bash
   vendor/bin/phinx migrate
   ```

   Si alguien creó una migración (un archivo nuevo en `db/migrations/`), la aplica en tu base. Si no hay migraciones pendientes, no hace nada.
3. **Revisar si cambiaron `phinx.example.php` o `config/configuracion.example.php`.** Tus `phinx.php` y `config/configuracion.php` no se actualizan con el `git pull` porque no están en el repo. Si una plantilla cambió (por ejemplo, una opción nueva), hay que pasar ese cambio a mano a tu archivo, manteniendo tus datos.

Para ver qué archivos cambiaron en el último `git pull`:

```bash
git diff --stat HEAD@{1} HEAD
```

> **Nunca usar `composer update` después de un `git pull`.** El comando correcto es `composer install`, que respeta las versiones de `composer.lock`.

### Comandos de Composer

| Comando | Cuándo usarlo |
|---|---|
| `composer install` | Al clonar el proyecto y después de cada `git pull`. |
| `composer dump-autoload` | Cuando se agrega un namespace nuevo en la sección `autoload` de `composer.json`. |
| `composer require <paquete>` | Para agregar una librería nueva. Después se suben al repo `composer.json` y `composer.lock`. |
| `composer update` | Solo si se quiere actualizar las librerías a versiones más nuevas. **No usarlo para instalar el proyecto**: cambia `composer.lock` y puede dejar a cada uno con versiones distintas. |

---

## API: rutas y contratos

Estos son los **contratos** entre el frontend y el backend: qué ruta llamar, qué datos enviar y qué responde el backend en cada caso. Cualquier cambio en una ruta o en el formato de una respuesta hay que actualizarlo acá, y avisar a quien trabaje en el frontend.

### Convenciones generales

- **URL base (desarrollo):** `http://localhost:8000`
- **Formato:** todas las peticiones con cuerpo envían JSON (header `Content-Type: application/json`) y **todas las respuestas son JSON**.
- **Errores:** siempre tienen la forma `{"error": "mensaje"}`. El mensaje está pensado para mostrárselo al usuario.
- **Ruta inexistente:** `404 {"error": "Ruta no encontrada"}`
- **Error interno del servidor:** `500 {"error": "Estamos con algunos inconvenientes. Vuelva a intentar en unos instantes..."}`

### Registro de usuario

```
POST /registrarse
```

**Envía:**

```json
{
    "nombre": "Ana Pérez",
    "mail": "ana@mail.com",
    "contrasenia": "12345678",
    "rol": "huesped"
}
```

| Campo | Reglas |
|---|---|
| `nombre` | Obligatorio. Entre 2 y 70 caracteres. |
| `mail` | Obligatorio. Formato de mail válido. Se guarda en minúsculas y sin espacios en los extremos. |
| `contrasenia` | Obligatorio. Entre 8 y 72 caracteres. Se guarda hasheada con `password_hash()`. |
| `rol` | Obligatorio. Uno de: `huesped`, `propietario`, `administrador`, `operador`. `backoffice` no se puede registrar desde la web. |
| `cobra_iva` | Solo si `rol` es `propietario`: `"1"` o `"0"` (si no se envía, se toma `"0"`). Se guarda en la tabla `propietarios`. |

> La Tarea 1 amplía este contrato (DNI, confirmación de mail y contraseña, datos fiscales). El contrato completo va a quedar en [`docs/api/cuentas-y-roles.md`](docs/api/cuentas-y-roles.md).

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Cuenta creada exitosamente", "usuario": {...}}` (ver [El objeto `usuario`](#el-objeto-usuario)) | El usuario se registró. Además, **queda con la sesión iniciada**: el backend envía la cookie de sesión, igual que en el login. |
| `422` | `{"error": "Todos los campos son obligatorios"}` | Falta algún campo o está vacío. |
| `422` | `{"error": "El mail es invalido"}` | El mail no tiene formato válido. |
| `422` | `{"error": "La contraseña debe tener entre 8 y 72 caracteres"}` | Contraseña demasiado corta o larga. |
| `422` | `{"error": "El nombre debe tener entre 2 y 70 caracteres"}` | Nombre demasiado corto o largo. |
| `422` | `{"error": "El rol no existe"}` | El rol no es uno de los permitidos. |
| `422` | `{"error": "El mail ya esta registrado"}` | Ya existe una cuenta con ese mail. |

**Inicio de sesión automático:** al registrarse, el usuario no tiene que iniciar sesión aparte. El backend guarda sus datos en la sesión y responde el `usuario` con el mismo formato que `POST /iniciar-sesion`, así que el frontend puede usar `usuario.rol` para llevarlo al home que corresponde. Para que la cookie de sesión quede guardada, el `fetch` del registro tiene que incluir **`credentials: 'include'`** (ver [Autenticación](#autenticación-sesiones-de-php)).

### Autenticación (sesiones de PHP)

La autenticación usa **sesiones de PHP**. Al iniciar sesión, el backend guarda los datos del usuario en el servidor y le envía al navegador una **cookie de sesión** `HttpOnly` (el JavaScript no puede leerla). El navegador la envía automáticamente en cada petición siguiente, y así el backend sabe quién es el usuario.

Para que la cookie funcione entre el frontend y el backend:

- Todo `fetch` a estas rutas, y a cualquier ruta que necesite saber quién es el usuario, tiene que incluir **`credentials: 'include'`**.
- El frontend se abre como **`http://localhost:5500`**, no como `127.0.0.1:5500`. Para el navegador, `localhost` y `127.0.0.1` son sitios distintos, y restringe las cookies entre sitios distintos.

#### El objeto `usuario`

El registro, el inicio de sesión y `GET /sesion` devuelven el usuario con este formato. Es lo mismo que se guarda en `$_SESSION['usuario']`, que se arma siempre con `App\Autorizacion\SesionUsuario` (nunca a mano en un controlador):

```json
{
    "id": 7,
    "nombre": "Ana Pérez",
    "mail": "ana@mail.com",
    "dni": "30123456",
    "rol": "propietario",
    "mail_verificado": false
}
```

| Campo | Significado |
|---|---|
| `dni` | Puede ser `null` (cuentas creadas con Google que todavía no lo completaron, o usuarios anteriores al cambio). |
| `rol` | Uno solo: `huesped`, `propietario`, `administrador` (de hospedajes), `operador` (de estadía) o `backoffice` (administración interna de la plataforma). Qué puede hacer cada rol se explica en [Permisos por rol](#permisos-por-rol). |
| `mail_verificado` | `true` si el usuario confirmó su mail (ver Tarea 2). |

#### Iniciar sesión

```
POST /iniciar-sesion
```

**Envía:**

```json
{
    "mail": "ana@mail.com",
    "contrasenia": "12345678"
}
```

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Sesión iniciada", "usuario": {...}}` (ver [El objeto `usuario`](#el-objeto-usuario)) | Mail y contraseña correctos. Además, el backend envía la cookie de sesión. |
| `401` | `{"error": "Mail o contraseña incorrectos"}` | El mail no existe, la contraseña es incorrecta, o la cuenta se creó solo con Google (no tiene contraseña). Es **el mismo mensaje** en todos los casos, para no revelar qué mails tienen cuenta. |
| `403` | `{"error": "Tu cuenta está deshabilitada"}` | El mail y la contraseña son correctos, pero la cuenta tiene `activo = 0`. |

El frontend usa `usuario.rol` para decidir a qué página de inicio redirigir.

#### Consultar la sesión actual

```
GET /sesion
```

Sirve para saber si hay un usuario logueado y quién es, por ejemplo al cargar una página que requiere sesión. No envía cuerpo.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"usuario": {...}}` (ver [El objeto `usuario`](#el-objeto-usuario)) | Hay una sesión iniciada. |
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión, o expiró. |
| `401` | `{"error": "Tu cuenta está deshabilitada"}` | La cuenta se deshabilitó (`activo = 0`) mientras tenía la sesión abierta. La sesión se cierra. |

#### Cerrar sesión

```
POST /cerrar-sesion
```

No envía cuerpo.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Sesión cerrada"}` | La sesión se destruyó en el servidor. |

### Permisos por rol

Los roles son cinco: `huesped`, `propietario`, `operador`, `administrador` y `backoffice`. Cada usuario tiene uno solo, en la columna `rol`.

- El **propietario es un huésped con funciones extra**: puede hacer todo lo que hace un huésped y además lo propio de propietario. Un huésped se puede hacer propietario en cualquier momento: cambia su `rol` y se crea su fila en la tabla `propietarios`, con sus datos fiscales.
- **Operador y administrador son cuentas aparte**: no heredan de nadie. Si un operador o un administrador quiere alojarse, necesita otra cuenta con otro mail.
- **Backoffice** es la administración interna de la plataforma (ver y deshabilitar usuarios). No se registra desde la web.

| Rol del usuario | Pasa en rutas que piden |
|---|---|
| `huesped` | `huesped` |
| `propietario` | `propietario` y `huesped` |
| `operador` | `operador` |
| `administrador` | `administrador` |
| `backoffice` | `backoffice` |

Esta regla está en un solo lugar: `autorizacion/Autorizacion.php`. Para proteger una ruta se llama en la primera línea del método del controlador:

```php
use App\Autorizacion\Autorizacion;

class PropiedadesController{
    public function misPropiedades(){
        Autorizacion::requiere('propietario');
        //a partir de aca el usuario esta logueado y es propietario
        $idUsuario = $_SESSION['usuario']['id'];
    }
}
```

| Método | Para qué |
|---|---|
| `Autorizacion::requiere('rol')` | Rutas de un rol puntual. |
| `Autorizacion::requiereSesion()` | Rutas que puede usar cualquier usuario logueado. |
| `Autorizacion::requiereMailVerificado()` | Acciones que exigen el mail confirmado (por ejemplo, reservar). |

Las tres verifican además, en la base, que la cuenta siga activa: si se deshabilitó mientras el usuario tenía la sesión abierta, cierran la sesión. Si el usuario no puede seguir, `Autorizacion` responde y corta la ejecución, así que el controlador no tiene que hacer nada más:

| Código | Cuerpo | Cuándo |
|---|---|---|
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión, o expiró. El frontend manda al login. |
| `401` | `{"error": "Tu cuenta está deshabilitada"}` | La cuenta tiene `activo = 0`. La sesión se cierra. |
| `403` | `{"error": "No tenés permiso para hacer esto"}` | Hay sesión, pero el rol no alcanza. El frontend manda a "acceso denegado". |
| `403` | `{"error": "Tenés que verificar tu mail para hacer esto"}` | Solo con `requiereMailVerificado()`: el mail todavía no está confirmado. |

---

## Documentación por módulo

Las funcionalidades que salieron de la devolución del profesor se documentan en archivos aparte, para que cada integrante trabaje en el suyo sin generar conflictos en este `Readme.md`:

| Documento | Contenido |
|---|---|
| [`docs/api/cuentas-y-roles.md`](docs/api/cuentas-y-roles.md) | Registro completo y "hacerme propietario" (Tarea 1). |
| [`docs/api/verificacion-y-backoffice.md`](docs/api/verificacion-y-backoffice.md) | Verificación de mail y administración de usuarios (Tarea 2). |
| [`docs/escalado-horizontal.md`](docs/escalado-horizontal.md) | Sesiones compartidas en Redis y *session affinity* (Tarea 3). |
| [`docs/api/oauth.md`](docs/api/oauth.md) | Inicio de sesión con Google (Tarea 4). |

---

## Phinx: migraciones de la base de datos

[Phinx](https://phinx.org/) es una herramienta de **migraciones**: cada cambio en la estructura de la base de datos (crear una tabla, agregar una columna, etc.) se escribe en un archivo dentro de `db/migrations/`, que se sube al repo. Phinx registra en la tabla `phinxlog` qué migraciones ya se aplicaron, y cuando se ejecuta aplica solo las que faltan.

Así, nadie tiene que modificar su base a mano: después de un `git pull`, con un solo comando cada uno tiene la misma estructura.

### ¿Por qué Phinx y no Flyway?

- **Se instala con Composer**, igual que el resto del proyecto. Flyway es una herramienta de Java y cada uno tendría que instalarla aparte (o correrla con Docker).
- **Permite deshacer migraciones** (`phinx rollback`). En la versión gratuita de Flyway no se puede.
- **Nombra las migraciones con fecha y hora.** Con Flyway (`V1`, `V2`, ...) dos personas pueden crear la misma versión al mismo tiempo y chocar.
- **Tiene *seeders*** para cargar datos de prueba (por ejemplo, usuarios de prueba).

### Comandos

| Comando | Qué hace |
|---|---|
| `vendor/bin/phinx migrate` | Aplica las migraciones pendientes. |
| `vendor/bin/phinx status` | Muestra qué migraciones están aplicadas y cuáles no. |
| `vendor/bin/phinx create NombreEnCamelCase` | Crea una migración nueva en `db/migrations/`. |
| `vendor/bin/phinx rollback` | Deshace la última migración. |
| `vendor/bin/phinx seed:run` | Carga los datos de prueba. |

> **Regla del equipo:** una migración que ya se subió al repo **no se modifica**. Si hay que cambiar algo, se crea una migración nueva.

---

## Configuración: `phinx.php` y `phinx.example.php`

Phinx lee los datos de conexión a la base (host, nombre de la base, usuario y **contraseña**) desde el archivo `phinx.php`.

### ¿Por qué `phinx.php` está en el `.gitignore`?

- **Tiene la contraseña de la base de datos.** Cualquier cosa que se sube al repo queda en el historial de git para siempre, aunque después se borre. Las contraseñas nunca se suben.
- **Cada persona tiene datos distintos.** Cada uno tiene su propio MySQL con su propio usuario y contraseña. Si `phinx.php` estuviera en el repo, cada `git pull` pisaría la configuración de los demás.

### ¿Qué es `phinx.example.php`?

Es una **copia de `phinx.php` sin datos sensibles**, que **sí se sube al repo**. Sirve de plantilla: muestra la estructura que tiene que tener la configuración, sin la contraseña de nadie.

Para configurar tu entorno:

1. Copiá la plantilla con el nombre real:

   ```bash
   cp phinx.example.php phinx.php
   ```

2. Abrí `phinx.php` y completá tus datos en el entorno `development` (`user`, `pass` y, si hace falta, `host` y `port`).
3. Creá la base de datos vacía en MySQL, con el mismo nombre que figura en `name`. Phinx crea las tablas, pero no la base:

   ```sql
   CREATE DATABASE alojamiento CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. Corré las migraciones:

   ```bash
   vendor/bin/phinx migrate
   ```

> Si alguien cambia la **estructura** de la configuración (por ejemplo, agrega un entorno nuevo), tiene que actualizar también `phinx.example.php`, para que el resto del equipo lo reciba.

---

## Verificar los datos en la base de datos

Para comprobar que algo se guardó bien (por ejemplo, después de registrar un usuario), hay que entrar a MySQL desde la terminal:

```bash
mysql -u root -p
```

Pide la contraseña de MySQL (mientras se escribe no se ve nada, es normal). Si la terminal no reconoce el comando `mysql`, hay que usar la ruta completa. En PowerShell:

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe" -u root -p
```

Una vez en el prompt `mysql>`, ejecutar los comandos que hagan falta. Cada uno termina con `;`:

| Comando | Qué muestra |
|---|---|
| `USE alojamiento;` | Selecciona la base del proyecto. **Hay que ejecutarlo primero**, si no los demás comandos no saben en qué base buscar. |
| `SHOW TABLES;` | Las tablas que existen. Tiene que aparecer `usuarios` y `phinxlog`. |
| `DESCRIBE usuarios;` | Las columnas de la tabla y sus tipos. Sirve para verificar que una migración se aplicó bien. |
| `SELECT * FROM usuarios;` | Todos los registros de la tabla. |
| `SELECT id, nombre, mail, rol, fecha_alta FROM usuarios ORDER BY id DESC LIMIT 5;` | Los últimos 5 usuarios registrados, sin la columna de la contraseña. |
| `SELECT * FROM usuarios WHERE mail = 'ana@mail.com';` | Un usuario puntual. |
| `SELECT * FROM phinxlog;` | Las migraciones que Phinx ya aplicó en tu base. |
| `exit` | Sale de MySQL. |

**Qué revisar después de un registro:**

- El usuario aparece con el `nombre`, `mail` y `rol` enviados, y el mail guardado **en minúsculas**.
- La columna `contrasenia` tiene un hash que empieza con `$2y$`, **nunca la contraseña en texto plano**.
- `fecha_alta` tiene la fecha y hora del registro.

**Atajo:** para ejecutar una sola consulta sin entrar al prompt, se puede usar `-e`:

```bash
mysql -u root -p alojamiento -e "SELECT id, nombre, mail, rol FROM usuarios;"
```

> Para borrar los usuarios de prueba y empezar de cero: `DELETE FROM usuarios;`. Borra **todos** los registros de la tabla, solo de tu base local.

---

## CORS

### ¿Qué es?

Por seguridad, los navegadores aplican la **política del mismo origen** (*same-origin policy*): el JavaScript de una página solo puede leer respuestas de su **mismo origen**. El origen es la combinación de **protocolo + dominio + puerto**.

En este proyecto el frontend y el backend están en orígenes distintos:

| | Origen |
|---|---|
| Frontend (Live Server) | `http://localhost:5500` o `http://127.0.0.1:5500` |
| Backend (PHP) | `http://localhost:8000` |

Cambia el puerto, así que para el navegador son sitios distintos, y bloquea las respuestas del backend al frontend.

**CORS** (*Cross-Origin Resource Sharing*) es el mecanismo con el que el **servidor** le indica al navegador qué otros orígenes pueden leer sus respuestas. Lo hace mediante headers HTTP en cada respuesta.

### Líneas agregadas en `bootstrap/bootstrap.php`

Estas son las líneas que se agregaron por CORS. Están al principio de `bootstrap/bootstrap.php`, antes de crear el router, entre los comentarios `---------- CORS ----------` y `---------- fin CORS ----------`:

```php
//---------- CORS ----------
$origenesPermitidos = ['http://localhost:5500', 'http://127.0.0.1:5500'];
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origen, $origenesPermitidos, true)) {
    header("Access-Control-Allow-Origin: $origen");
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
//---------- fin CORS ----------
```

| Línea | Qué hace |
|---|---|
| `$origenesPermitidos = [...]` | Lista de orígenes del frontend que pueden usar el backend. Están los dos porque Live Server puede abrir la página como `localhost` o como `127.0.0.1`, y el navegador los considera orígenes distintos. |
| `$origen = $_SERVER['HTTP_ORIGIN'] ?? ''` | El navegador envía en el header `Origin` desde qué página se hizo la petición. |
| `Access-Control-Allow-Origin` | Autoriza a ese origen a leer la respuesta. Solo se envía si el origen está en la lista. |
| `Vary: Origin` | Indica que la respuesta cambia según el origen, para que ningún caché la reutilice con otro origen. |
| `Access-Control-Allow-Methods` | Métodos HTTP que el frontend puede usar. |
| `Access-Control-Allow-Headers: Content-Type` | Permite que el frontend envíe el header `Content-Type`, necesario para mandar JSON. |
| `if (... === 'OPTIONS') { ... exit; }` | Responde el *preflight* (ver abajo) y termina, sin pasar por el router. |

**El *preflight*:** antes de un `POST` que envía JSON (por ejemplo, el registro), el navegador hace automáticamente una petición previa con el método `OPTIONS` para preguntar si tiene permiso. Si la respuesta trae los headers de arriba, recién ahí envía el `POST` real. Por eso el backend tiene que responder las peticiones `OPTIONS`.

### ¿Qué pasaría si no incluimos estas líneas?

El frontend no podría comunicarse con el backend. Cada `fetch` fallaría y en la consola del navegador (F12) aparecería un error como este:

```
Access to fetch at 'http://localhost:8000/registro' from origin 'http://127.0.0.1:5500'
has been blocked by CORS policy: Response to preflight request doesn't pass access
control check: No 'Access-Control-Allow-Origin' header is present on the requested resource.
```

En concreto:

- **En un `POST` con JSON (registro, login):** el *preflight* no recibe permiso, así que el navegador **ni siquiera envía** el `POST`. El usuario no se registraría.
- **En un `GET`:** la petición sí llega al backend y se ejecuta, pero el navegador **no le deja leer la respuesta** al JavaScript. Para el frontend es como si hubiera fallado.

El error aparece solo en el navegador. Si se prueba el backend con `curl` o Postman funciona igual, porque esas herramientas no aplican CORS. Por eso, si algo anda con Postman pero no desde el frontend, lo primero que hay que revisar es CORS.

### Cosas a tener en cuenta

- **Si el frontend corre en otro puerto u otra dirección**, hay que agregar ese origen a `$origenesPermitidos`. Si no, el navegador lo bloquea.
- **CORS no protege al backend.** Solo controla qué páginas pueden leer las respuestas **desde un navegador**. Cualquiera puede hacer peticiones al backend con `curl` o Postman. La seguridad (validar datos, verificar la sesión y los permisos) se hace siempre en el backend.
