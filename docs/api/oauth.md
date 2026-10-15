# API: OAuth con Google

Inicio de sesión y registro con Google (Tarea 4). Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

## Qué es OAuth

**OAuth 2.0** es un estándar para que nuestra aplicación pueda **identificar a un usuario a través de Google sin recibir nunca su contraseña de Google**. Es el botón "Continuar con Google". Sobre OAuth, **OpenID Connect** agrega la parte de identidad: quién es el usuario (su ID en Google, su mail, si ese mail está verificado y su nombre).

Para el usuario es más rápido (no inventa otra contraseña) y, para nosotros, el mail ya llega **verificado por Google**.

**Alcance:** OAuth es solo para cuentas con rol `huesped` o `propietario`. Administradores de hospedajes, operadores y backoffice siguen entrando con mail y contraseña.

### El flujo ("Authorization Code")

```
 Navegador                         Backend (localhost:8000)                 Google
    |                                       |                                  |
    | 1. clic en "Continuar con Google"     |                                  |
    |-------------------------------------->| GET /oauth/google                |
    |                                       | 2. genera el state y lo guarda   |
    |<--------- 302 a Google ---------------|    en $_SESSION                  |
    |----------------------------------------------------------------------- ->|
    |                                       |        3. el usuario elige su    |
    |                                       |           cuenta y acepta        |
    |<------------------ 302 a /oauth/google/callback?code=XXX&state=YYY ------|
    |-------------------------------------->| 4. GET /oauth/google/callback    |
    |                                       | 5. controla el state (CSRF)      |
    |                                       | 6. cambia el code por un token ->|
    |                                       | 7. pide el perfil (sub, mail) -->|
    |                                       | 8. busca o crea el usuario e     |
    |                                       |    inicia la sesión              |
    |<-- 302 a oauth-retorno.html (front) --|                                  |
```

Los pasos 1, 2, 4 y 8 son **navegaciones completas del navegador**, no `fetch`: por eso en el front el botón es un `<a href>` y el backend responde con redirecciones. El `client_secret` vive solo en el backend.

El usuario se identifica por el **`sub`** (ID único y fijo en Google), no por el mail, porque una persona puede cambiar el mail de su cuenta de Google. La relación se guarda en la tabla `identidades_oauth` (`usuario_id`, `proveedor`, `sujeto`).

## Cómo configurar Google (una sola vez)

1. Entrar a https://console.cloud.google.com/ y **crear un proyecto** ("Alojamiento temporario").
2. Ir a **"Google Auth Platform"** (o **"APIs y servicios → Pantalla de consentimiento de OAuth"**):
   - Tipo de usuarios: **Externo**.
   - Nombre de la aplicación: "Alojamiento temporario", con el mail de soporte.
   - Permisos (scopes): `openid`, `email` y `profile`.
   - En **"Usuarios de prueba"**, agregar los Gmail de los integrantes y del profesor. Mientras la app esté "en prueba", solo esos usuarios pueden entrar.
3. **Crear el cliente** ("Clientes" o "Credenciales → Crear credenciales → ID de cliente de OAuth"):
   - Tipo: **Aplicación web**.
   - **URI de redireccionamiento autorizado:** `http://localhost:8000/oauth/google/callback`, exacto: sin barra al final, con `localhost` y no `127.0.0.1`.
4. Copiar el **ID de cliente** y el **secreto** en `config/configuracion.php`:

   ```php
   'google' => [
       'client_id'     => '....apps.googleusercontent.com',
       'client_secret' => '....',
       'redirect_uri'  => 'http://localhost:8000/oauth/google/callback',
   ],
   ```

   **Nunca en el repo** (`config/configuracion.php` está en el `.gitignore`). El secreto se pasa al resto del equipo por privado.

5. Correr la migración: `vendor/bin/phinx migrate` (crea la tabla `identidades_oauth`).

## Iniciar sesión con Google

```
GET /oauth/google
```

Se abre como navegación (un enlace), no con `fetch`. Genera un `state` aleatorio, lo guarda en la sesión y responde `302` a la página de Google, pidiendo los permisos `openid`, `email` y `profile`. Siempre deja elegir la cuenta de Google.

## Callback de Google

```
GET /oauth/google/callback?code=XXX&state=YYY
```

Google redirige acá cuando el usuario acepta (o cancela). No lo llama el front. Siempre responde con un `302`:

- **Si sale bien:** inicia la sesión y redirige a `<url_front>/vistas/sesion/oauth-retorno.html`.
- **Si falla:** redirige a `<url_front>/vistas/sesion/inicio-sesion.html?error=<codigo>`.

Qué hace, en orden:

1. Controla el `state` contra el guardado en la sesión.
2. Cambia el `code` por un token con Google y pide el perfil: `sub`, mail, mail verificado y nombre.
3. Si ya hay una identidad de Google con ese `sub`, ese es el usuario.
4. Si no, y ya existe un usuario con ese mail: si es huésped o propietario, **vincula** su cuenta con Google (y le marca el mail como verificado si no lo estaba). Es la misma cuenta, con el mismo `id`.
5. Si no existe ni la identidad ni el mail, **crea una cuenta de huésped** sin contraseña (`contrasenia` en `NULL`), sin DNI y con el mail verificado. Esa cuenta no puede entrar con contraseña.
6. Si la cuenta está deshabilitada, no inicia la sesión.

| `error=` | Cuándo | Mensaje que muestra el front |
|---|---|---|
| `cancelado` | El usuario canceló en la página de Google. | Cancelaste el inicio de sesión con Google. |
| `invalido` | Falta el `state` o no coincide con el guardado en la sesión. | No pudimos validar el inicio de sesión con Google. Intentá de nuevo. |
| `mail_no_verificado` | La cuenta de Google no tiene el mail verificado. | Tu cuenta de Google no tiene el mail verificado. |
| `no_permitido` | El mail pertenece a una cuenta que no es huésped ni propietario. | Esa cuenta no puede entrar con Google. Iniciá sesión con tu mail y contraseña. |
| `deshabilitada` | La cuenta fue deshabilitada por un administrador. | Tu cuenta está deshabilitada. |
| `error` | Cualquier otro problema (Google no respondió, el `code` no sirve, falla la base). El detalle queda en la terminal del servidor. | Hubo un problema al iniciar sesión con Google. Intentá de nuevo en unos minutos. |

El front usa el código solo como clave para elegir el mensaje: nunca muestra el texto de la URL tal cual.

## Completar datos

```
POST /completar-datos
```

Carga el DNI de las cuentas creadas con Google, o de usuarios viejos que no lo tienen. Necesita sesión iniciada (el `fetch` lleva `credentials: 'include'`). El usuario es siempre el de la sesión.

**Envía:**

```json
{
    "dni": "30.123.456"
}
```

| Campo | Reglas |
|---|---|
| `dni` | Obligatorio. 7 u 8 números. Se puede mandar con puntos: se guarda solo con números. No se puede repetir. |

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Datos guardados", "usuario": {"id": 9, "nombre": "Ana Pérez", "mail": "ana@gmail.com", "dni": "30123456", "rol": "huesped", "mail_verificado": true}}` | Se guardó el DNI. La sesión ya tiene el DNI nuevo. |
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión. |
| `401` | `{"error": "Tu cuenta está deshabilitada"}` | La cuenta fue deshabilitada mientras tenía la sesión abierta. |
| `409` | `{"error": "Tu DNI ya está cargado"}` | El usuario ya tiene DNI. |
| `422` | `{"error": "El DNI tiene que tener 7 u 8 números"}` | El DNI tiene letras, o no tiene 7 u 8 números. |
| `422` | `{"error": "El DNI ya está registrado"}` | Otra cuenta ya tiene ese DNI. |

## Cómo probarlo

1. Con `config/configuracion.php` completo, `vendor/bin/phinx migrate` y el backend levantado, abrir `http://localhost:5500/vistas/sesion/inicio-sesion.html` y hacer clic en "Continuar con Google".
2. **Cuenta nueva** (un Gmail de prueba que no esté registrado) → página de Google → vuelve → pide el DNI → home de huésped. En MySQL: el usuario tiene `contrasenia` en `NULL`, `mail_verificado_en` con fecha y una fila en `identidades_oauth`.
3. Cerrar sesión y volver a entrar con Google → va **directo al home**, sin pedir el DNI de nuevo.
4. **Vinculación:** registrarse primero con mail y contraseña usando un Gmail, y después entrar con Google con ese mismo Gmail → entra a **la misma cuenta**: el mismo `id`, y una fila nueva en `identidades_oauth`.
5. Cancelar en la página de Google → vuelve al login con "Cancelaste el inicio de sesión con Google."
6. Cambiar a mano el `state` en la URL del callback → "No pudimos validar…".
7. Un usuario `operador` con un Gmail que intenta entrar con Google → "Esa cuenta no puede entrar con Google…".
8. Intentar el login con contraseña para una cuenta creada solo con Google → "Mail o contraseña incorrectos".

`POST /completar-datos` también se puede probar con curl, usando la cookie de una sesión iniciada:

```bash
curl -i -b cookies.txt -X POST http://localhost:8000/completar-datos \
     -H "Content-Type: application/json" -d '{"dni": "30.123.456"}'
```
