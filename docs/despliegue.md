# Despliegue

El sistema se despliega en tres servicios, uno por pieza:

| Pieza | Servicio | Qué corre ahí |
|---|---|---|
| **Frontend** | [Vercel](https://vercel.com) | Los archivos HTML, CSS y JS del repo `Alojamiento_temporario-Frontend` |
| **Backend** | [Railway](https://railway.app) | El backend PHP de este repo, como contenedor de Docker (`Dockerfile` de la raíz) |
| **Base de datos** | [Aiven](https://aiven.io/free-mysql-database) | MySQL 8, plan gratis (1 GB) |

```
                    ┌──────────────── Vercel ────────────────┐
Navegador ──────────►  /vistas/...  →  archivos del frontend  │
   (un solo sitio:  │  /api/...     →  se reenvía a Railway ──┼──► Backend PHP (Railway) ──► MySQL (Aiven, con SSL)
   alojamiento...   └─────────────────────────────────────────┘
   .vercel.app)
```

## La decisión clave: el frontend reenvía `/api` al backend

Desplegados, el frontend y el backend quedan en dominios distintos (`*.vercel.app` y `*.up.railway.app`). Para el navegador, eso es "otro sitio", igual que pasaba en desarrollo con `localhost` y `127.0.0.1`: la cookie de sesión `SameSite=Lax` no viajaría, y los navegadores bloquean cada vez más las cookies entre sitios distintos.

Para evitarlo, el frontend **no llama directo a Railway**. Llama a `/api/...` en su **propio dominio**, y Vercel reenvía esas peticiones al backend (un *rewrite*, configurado en `vercel.json`). Así:

- Para el navegador, frontend y backend son **el mismo sitio**: la cookie de sesión funciona sin cambios.
- No hace falta CORS (igual quedó configurado, por si se llama al backend directamente).
- La dirección real del backend aparece en un solo archivo (`vercel.json`), no en el código.

---

## Cambios en el código para poder desplegar

**Nada de esto cambia el desarrollo local:** con `php -S` y Live Server todo funciona igual que antes, y no hay que tocar `phinx.php` ni `config/configuracion.php`.

### Backend

| Archivo | Cambio | Por qué |
|---|---|---|
| `config/Config.php` | Cada clave se puede definir con una **variable de entorno**: el nombre en mayúsculas y con `_` en lugar de `.` (`smtp.host` → `SMTP_HOST`, `app.url_front` → `APP_URL_FRONT`). Si no existe `config/configuracion.php`, usa los valores de `configuracion.example.php`. Además, `Config::obtener($clave, $porDefecto)` acepta un valor por defecto | Desplegado no existe `configuracion.php` (está en el `.gitignore`). La configuración va en las variables de entorno de Railway, sin archivos con contraseñas en el repo |
| `config/configuracion.example.php` | Nueva clave `sesiones.cookie_segura` | Para activar `secure` en la cookie de sesión con HTTPS |
| `database/Conexion.php` | Funciona **sin `phinx.php`**, solo con variables de entorno: `DB_HOST`, `DB_PORT`, `DB_NAME` (nueva), `DB_USER`, `DB_PASS`. Y **SSL**: con `DB_SSL_CA` (o `mysql_attr_ssl_ca` en `phinx.php`) usa el certificado para conectarse cifrado | Desplegado no existe `phinx.php`. Aiven exige conexión cifrada |
| `bootstrap/bootstrap.php` | **CORS** también acepta la URL del frontend (`app.url_front`). **La cookie de sesión** usa `secure` si `sesiones.cookie_segura` es `1` | Producción usa HTTPS: con `secure`, la cookie solo viaja cifrada |
| `Dockerfile` (raíz, **nuevo**) | Imagen de producción: instala las dependencias con Composer (sin las de desarrollo), copia el código y arranca `php -S` en el puerto de la variable `PORT` | Railway construye y ejecuta esta imagen. Es distinta de `docker/php/Dockerfile`, que es solo para la demo de escalado y no copia el código |
| `.dockerignore` (**nuevo**) | Deja afuera de la imagen `phinx.php`, `config/configuracion.php`, `docker/backend.env`, `cookies.txt`, `vendor/` y `.git/` | Que ningún dato sensible quede dentro de la imagen |
| `certificados/` (**nueva**) | Acá va `aiven-ca.pem`, el certificado CA de Aiven | Lo necesita el backend desplegado para la conexión SSL. Es **público** (no es una contraseña), por eso puede estar en el repo |

### Frontend

| Archivo | Cambio | Por qué |
|---|---|---|
| `funcionalidades/sesion.js` | Nueva constante `URL_BACK`: vale `http://localhost:8000` en `localhost` (Live Server) y `/api` en cualquier otro dominio | Antes, `http://localhost:8000` estaba escrito en 11 `fetch`. Ahora la dirección del backend está en un solo lugar y cambia sola según dónde corre |
| 9 archivos de `funcionalidades/` | Los `fetch` usan `URL_BACK + '/ruta'` | Todas las páginas cargan `sesion.js` antes que su propio JS, así que la constante ya existe |
| `vercel.json` (**nuevo**) | `/api/...` se reenvía a Railway, y `/` redirige a `vistas/homes/index-sin-sesion.html` | El *rewrite* explicado arriba. La redirección, porque no hay un `index.html` en la raíz |

---

## Paso a paso

El orden importa: cada pieza necesita la dirección de la anterior.

### 1. Base de datos (Aiven)

1. Crear el servicio MySQL con el plan **Free** (1 GB de almacenamiento). **Verificar que el plan diga 1 GB**: Aiven ofrece primero planes pagos que consumen créditos de prueba y se apagan a los 30 días.
2. En la sección de bases de datos del servicio, crear la base **`alojamiento`**.
3. En "Información de conexión", descargar el **CA certificate** y guardarlo como `certificados/aiven-ca.pem` en este repo (se commitea).
4. Crear las tablas desde la máquina de un integrante. En `phinx.php`, completar el entorno `production` con los datos de Aiven:

   ```php
   'production' => [
       'adapter' => 'mysql',
       'host' => '<host de Aiven>',
       'name' => 'alojamiento',
       'user' => 'avnadmin',
       'pass' => '<contraseña de Aiven>',
       'port' => '<puerto de Aiven>',
       'charset' => 'utf8mb4',
       'mysql_attr_ssl_ca' => __DIR__ . '/certificados/aiven-ca.pem',
   ],
   ```

   Y ejecutar:

   ```bash
   vendor/bin/phinx migrate -e production
   ```

> El plan gratis se **apaga si no se usa** por un tiempo (Aiven avisa por mail). Antes de una presentación, entrar a la consola y verificar que el servicio diga "En ejecución".

> **Aiven usa el modo SQL `ANSI`**, más estricto que el MySQL que instalamos localmente. Lo más importante: **las comillas dobles indican nombres de columnas, no textos**. En `WHERE rol = "huesped"`, Aiven busca una *columna* llamada `huesped`, y la consulta falla, aunque en desarrollo funcione. **En SQL, los textos van siempre entre comillas simples** (`WHERE rol = 'huesped'`), o mejor, como parámetros de una sentencia preparada. Hoy ninguna consulta del proyecto usa comillas dobles: se probó el registro, el login, la sesión, "hacerme propietario", los datos fiscales y el backoffice contra Aiven.

### 2. Backend (Railway)

1. En Railway: **New Project → Deploy from GitHub repo →** `Alojamiento_temporario-Backend`. Railway detecta el `Dockerfile` de la raíz y construye la imagen.
2. En el servicio → **Variables**, cargar:

   | Variable | Valor |
   |---|---|
   | `DB_HOST` | El host de Aiven |
   | `DB_PORT` | El puerto de Aiven |
   | `DB_NAME` | `alojamiento` |
   | `DB_USER` | `avnadmin` |
   | `DB_PASS` | La contraseña de Aiven |
   | `DB_SSL_CA` | `certificados/aiven-ca.pem` |
   | `SESIONES_COOKIE_SEGURA` | `1` |
   | `APP_URL_FRONT` | La URL de Vercel (se completa en el paso 4). La usan CORS y el enlace del mail de verificación |

   `PORT` **no** se carga: la define Railway sola.
3. En **Settings → Networking → Generate Domain**, generar la URL pública (algo como `alojamiento-backend.up.railway.app`).
4. Probar: `https://<url-de-railway>/estado` tiene que responder `{"ok":"API funcionando",...}`.

### 3. Frontend (Vercel)

1. En `vercel.json`, reemplazar `REEMPLAZAR-CON-LA-URL-DE-RAILWAY.up.railway.app` por la URL del paso 2.3, y commitear.
2. En Vercel: **Add New → Project →** importar `Alojamiento_temporario-Frontend`.
   - *Framework Preset*: **Other**.
   - Sin *Build Command* ni *Output Directory*: son archivos estáticos, se sirven tal cual.
3. **Deploy**. Vercel da una URL como `alojamiento-temporario.vercel.app`.

### 4. Conectar las dos puntas

En Railway, cargar `APP_URL_FRONT` con la URL de Vercel (sin `/` al final). Railway vuelve a desplegar solo.

### 5. Verificar

1. `https://<url-de-vercel>/` → lleva a la página de inicio.
2. `https://<url-de-vercel>/api/estado` → responde el backend, a través de Vercel.
3. Registrarse, iniciar sesión, entrar al perfil y cerrar sesión. En F12 → *Application → Cookies* tiene que aparecer `PHPSESSID` **en el dominio de Vercel**, con `Secure` y `HttpOnly`.
4. En Aiven, la tabla `usuarios` tiene el usuario nuevo.

---

## Limitaciones conocidas

- **Mails de verificación:** en desarrollo los atrapa Mailpit, pero desplegado no hay servidor de mails, así que no se envían. El registro funciona igual: el error queda en los logs de Railway. Para enviarlos de verdad, hay que cargar en Railway las variables `SMTP_HOST`, `SMTP_PORT`, `SMTP_USUARIO`, `SMTP_CONTRASENIA`, `SMTP_CIFRADO` y `SMTP_REMITENTE` de un proveedor (Brevo, Mailgun, o Gmail con una contraseña de aplicación). No hace falta tocar código.
- **Sesiones:** se guardan en archivos dentro del contenedor (una sola réplica). Cada vez que Railway vuelve a desplegar, los usuarios tienen que iniciar sesión de nuevo. Para que sobrevivan, o para usar varias réplicas, se puede agregar un Redis en Railway y cargar `SESIONES_DRIVER=redis`, `REDIS_HOST` y `REDIS_PORT` (ver `escalado-horizontal.md`).
- **`php -S`** es un servidor de desarrollo, que atiende de a una petición por vez. Alcanza para la entrega; para tráfico real se usaría Nginx con PHP-FPM.
- **El plan gratis de Aiven** se apaga por inactividad, no tiene alta disponibilidad, y está pensado para pruebas.
