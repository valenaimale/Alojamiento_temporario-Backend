# Escalado horizontal y sesiones

## El problema

El backend identifica al usuario con **sesiones de PHP**. Al iniciar sesión, PHP guarda `$_SESSION` en un **archivo del servidor** (`sess_<id>`, en la carpeta temporal) y el navegador solo recibe el ID, en la cookie `PHPSESSID`.

Para atender a muchos usuarios a la vez se **escala horizontalmente**: se levantan varias copias (**réplicas**) del backend y un **balanceador de carga** reparte las peticiones entre ellas.

```
                          ┌─► Réplica 1   (tiene el archivo sess_abc123)
Navegador ──► Balanceador ┤
                          └─► Réplica 2   (no tiene ese archivo)
```

El login lo atiende la réplica 1, que crea `sess_abc123` **en su propio disco**. Si la petición siguiente cae en la réplica 2, esa réplica no tiene el archivo: para ella no hay sesión, y **el usuario aparece deslogueado**. Compartir una carpeta de archivos temporales entre todas las réplicas sería lento y frágil.

## Alternativas

### 1. *Session affinity* (*sticky sessions*)

El balanceador manda **siempre al mismo usuario a la misma réplica**. Las sesiones siguen en archivos, pero como cada usuario vuelve siempre a la réplica que tiene su archivo, no se pierden. Hay dos formas de implementarla:

- **Por IP del cliente** (en Nginx, `ip_hash`): el balanceador calcula a qué réplica va cada IP.
- **Por cookie del balanceador**: en la primera respuesta, el balanceador agrega su propia cookie que indica la réplica asignada, y en las peticiones siguientes la lee para mandar al usuario a la misma.

| A favor | En contra |
|---|---|
| No hay que cambiar el código | **Si esa réplica se cae o se reinicia, todos sus usuarios pierden la sesión** |
| | La carga queda despareja: una réplica puede acumular a los usuarios más activos |
| | Al agregar o sacar réplicas, se reasignan usuarios, y esos usuarios pierden la sesión |
| | Con `ip_hash`, muchos usuarios detrás de la misma IP (una oficina, una universidad, una red móvil) caen todos en la misma réplica |

La *session affinity* **esconde** el problema en lugar de resolverlo: las réplicas siguen teniendo estado propio.

### 2. Almacén de sesiones compartido (Redis) ✅ — la elegida

Las sesiones se guardan en un **servidor Redis**, una base de datos en memoria muy rápida, al que se conectan todas las réplicas. Cualquier réplica puede atender cualquier petición, porque todas leen la sesión del mismo lugar.

```
                          ┌─► Réplica 1 ──┐
Navegador ──► Balanceador ┤               ├──► Redis (sesiones)
                          └─► Réplica 2 ──┘
```

| A favor | En contra |
|---|---|
| Las réplicas quedan **sin estado**: se pueden agregar, sacar o reiniciar sin que nadie pierda la sesión | Es un servicio más para levantar |
| Redis borra solo las sesiones vencidas (TTL) | Redis pasa a ser un punto único de falla (en producción se usa con réplicas) |
| **El código de los controladores no cambia**: se sigue usando `$_SESSION` | |

### 3. Sesiones en MySQL

Igual que Redis, pero guardando las sesiones en una tabla. No suma otro servicio, pero implica una escritura en la base en cada petición, y hay que borrar las sesiones vencidas a mano.

### 4. JWT (sin sesión en el servidor)

Los datos del usuario viajan firmados en un token, así que no hay nada que compartir entre réplicas. Pero cerrar sesión o deshabilitar una cuenta no invalida el token hasta que vence. Ya lo habíamos descartado al implementar el inicio de sesión.

## Cómo está implementado

PHP permite reemplazar **dónde** guarda las sesiones con `session_set_save_handler()`. Le pasamos un objeto propio, `App\Sesion\ManejadorSesionRedis` (`sesion/ManejadorSesionRedis.php`), con los métodos que PHP llama por su cuenta:

| Método | Cuándo lo llama PHP | Qué hace en Redis |
|---|---|---|
| `read($id)` | En `session_start()` | `GET` de la clave `alojamiento:sesion:<id>` |
| `write($id, $datos)` | Al terminar la petición, si `$_SESSION` cambió | `SETEX`: guarda y fija el vencimiento (2 horas). **Si la sesión está vacía** (un visitante sin login), no la guarda: si no, cada visita anónima ocuparía memoria en Redis durante horas |
| `updateTimestamp($id)` | Al terminar la petición, si `$_SESSION` **no** cambió | `EXPIRE`: renueva el vencimiento, para que un usuario activo no pierda la sesión |
| `destroy($id)` | En `session_destroy()` (cerrar sesión) y `session_regenerate_id(true)` (login) | `DEL` |
| `validateId($id)` | En `session_start()`, con `session.use_strict_mode` activado | `EXISTS`: rechaza IDs inventados por el cliente |
| `gc()` | De vez en cuando | Nada: Redis ya borra lo vencido por TTL |

En `bootstrap/bootstrap.php`, antes de `session_start()`, se elige el almacén según la configuración. **Todo el resto del código (`$_SESSION`, `SesionUsuario`, `Autorizacion`) sigue igual.**

| Configuración | Dónde |
|---|---|
| `sesiones.driver` | `config/configuracion.php`: `'archivos'` (por defecto) o `'redis'` |
| `sesiones.redis.host`, `port`, `prefijo`, `duracion_segundos` | `config/configuracion.php` |
| `SESIONES_DRIVER`, `REDIS_HOST`, `REDIS_PORT` | Variables de entorno (las usa `docker-compose.yml`). Tienen prioridad sobre el archivo |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS` | Variables de entorno para los contenedores. Reemplazan los datos de `phinx.php` |

Además, el bootstrap agrega a **todas** las respuestas el header `X-Replica` con el nombre del servidor que la atendió. Se ve en el navegador, en F12 → *Network* → *Headers*.

Si Redis no responde, `session_start()` falla antes de llegar al router. Por eso el bootstrap lo envuelve en un `try/catch` que responde `503` con el mensaje genérico, y deja el error en la terminal del servidor.

## Cómo usarlo

### Desarrollo normal (sin Redis)

No hace falta hacer nada: `sesiones.driver` viene en `'archivos'`, y el backend funciona igual que siempre con `php -S localhost:8000 -t index`.

### Desarrollo con Redis, sin réplicas

```bash
docker run -d --name redis -p 6379:6379 redis:7-alpine
```

En `config/configuracion.php`: `'driver' => 'redis'`. Después, `php -S localhost:8000 -t index` como siempre.

### Demo de escalado: 2 réplicas, balanceador y Redis

Los archivos están en el repo:
- `docker-compose.yml`: define Redis, dos réplicas (`backend1` y `backend2`) y el balanceador Nginx en el puerto 8000.
- `docker/php/Dockerfile`: la imagen de cada réplica (PHP 8.5 con `pdo_mysql`). Tiene que ser PHP 8.4.1 o superior, porque lo exigen las dependencias de Phinx (Symfony 8) que están en `composer.lock`.
- `docker/nginx/default.conf`: la configuración del balanceador. Incluye `zone backends 64k;`: sin esa línea, cada proceso de Nginx lleva su propio turno del round-robin y, con pocas peticiones, todas caen en la misma réplica.

**Preparación (una sola vez):**

1. Crear un usuario de MySQL para los contenedores. `root` solo acepta conexiones desde la propia máquina, y los contenedores se conectan desde otra dirección. En `mysql -u root -p`:

   ```sql
   CREATE USER 'alojamiento_app'@'%' IDENTIFIED BY 'una-contrasenia';
   GRANT SELECT, INSERT, UPDATE, DELETE ON alojamiento.* TO 'alojamiento_app'@'%';
   ```

2. Copiar `docker/backend.env.example` como `docker/backend.env` y poner esa misma contraseña. `docker/backend.env` está ignorado por git (lo indica `docker/.gitignore`).
3. Tener Docker Desktop abierto.

**Levantar la demo:**

1. Frenar `php -S` si está corriendo, porque el balanceador usa el mismo puerto 8000.
2. Ejecutar `docker compose up --build` desde la raíz del backend. La primera vez tarda un poco, porque construye la imagen.
3. El frontend se usa igual que siempre, en `http://localhost:5500`.
4. Para terminar: `Ctrl + C` y `docker compose down`.

> Si reconstruís las réplicas (`docker compose up --build` después de cambiar el `Dockerfile`), reiniciá el balanceador con `docker compose restart balanceador`. Nginx resuelve las direcciones de las réplicas al arrancar, y al recrearlas cambian de IP.

## Pruebas para mostrar

> **Antes de empezar:** verificar con `docker ps` que ningún otro contenedor ni `php -S` esté usando el puerto 8000. Si lo está, el balanceador no arranca y las peticiones le llegan a otro programa.
>
> **Las sesiones anteriores no sirven:** una sesión iniciada con `php -S` quedó guardada en un archivo, no en Redis, y las réplicas no la conocen. Hay que **iniciar sesión de nuevo a través de la demo**.

1. **El balanceador reparte:** `curl.exe http://localhost:8000/estado` varias veces. El campo `replica` alterna entre `backend1` y `backend2`.
2. **La sesión se comparte.**
   - **Desde el navegador:** iniciar sesión en el front y navegar, con F12 → *Network* abierto. El header `X-Replica` de las peticiones a `/sesion` va alternando entre `backend1` y `backend2`, y la sesión se mantiene.
   - **Desde la consola (PowerShell):** primero iniciar sesión **guardando la cookie** con `-c`. El usuario tiene que existir en la base:

     ```powershell
     curl.exe --% -c cookies.txt -X POST http://localhost:8000/iniciar-sesion -H "Content-Type: application/json" -d "{\"mail\":\"un-usuario@mail.com\",\"contrasenia\":\"su-contrasenia\"}"
     ```

     El login funciona igual que siempre: busca el usuario en **MySQL** y verifica la contraseña. Lo único que cambia es dónde queda guardada la **sesión**: en Redis, en lugar de un archivo. Redis no guarda usuarios, solo sesiones (qué cookie pertenece a qué usuario).

     Después, consultar la sesión **enviando la cookie** con `-b`, varias veces. Con `-i` se ve el header `X-Replica`:

     ```powershell
     curl.exe -i -b cookies.txt http://localhost:8000/sesion
     ```

     `X-Replica` alterna entre `backend1` y `backend2`, y las dos devuelven el mismo usuario.
3. **La prueba clave: una réplica se cae y nadie pierde la sesión.** Con la sesión iniciada (paso 2), ejecutar `docker compose stop backend1` y seguir navegando, o repetir `curl.exe -i -b cookies.txt http://localhost:8000/sesion`. La sesión sigue, porque está en Redis y no en la réplica: ahora todas las respuestas son de `backend2`. Para volver: `docker compose start backend1`.
4. **Ver las sesiones en Redis:** `docker compose exec redis redis-cli KEYS "alojamiento:sesion:*"`. Hay una clave por cada usuario logueado; los visitantes sin login no generan ninguna. Después de cerrar sesión, la clave desaparece.
5. **Redis caído:** `docker compose stop redis`. El backend responde `503` con el mensaje genérico (no se rompe), y el error real aparece en `docker compose logs backend1`. Con `docker compose start redis` se recupera solo.
6. **Comparación con session affinity:** descomentar `ip_hash;` en `docker/nginx/default.conf` y ejecutar `docker compose restart balanceador`. Ahora `/estado` devuelve siempre la misma réplica. Si las sesiones estuvieran en archivos, al frenar esa réplica el usuario perdería la sesión. Con Redis no la pierde, aunque cambie de réplica.

## En producción

- Redis con réplicas (o un servicio administrado), para que no sea un punto único de falla.
- La cookie de sesión con `secure => true` (requiere HTTPS).
- Las réplicas detrás de un balanceador real (Nginx, HAProxy o el de un proveedor de la nube), con un servidor web con PHP-FPM en lugar de `php -S`, que es solo para desarrollo.
