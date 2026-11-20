# API: verificación de mail y backoffice

Rutas de la **Tarea 2**. Las convenciones generales (URL base, formato JSON, forma de los errores, cookie de sesión y `credentials: 'include'`) están en el [`Readme.md`](../../Readme.md), sección "API: rutas y contratos".

Índice:

- [Verificación de mail](#verificación-de-mail)
  - [`POST /verificar-mail`](#post-verificar-mail)
  - [`POST /reenviar-registro`](#post-reenviar-registro)
  - [`POST /reenviar-verificacion`](#post-reenviar-verificacion)
- [Backoffice: administración de usuarios](#backoffice-administración-de-usuarios)
  - [`GET /backoffice/usuarios`](#get-backofficeusuarios)
  - [`GET /backoffice/usuario`](#get-backofficeusuario)
  - [`POST /backoffice/usuario/estado`](#post-backofficeusuarioestado)
- [Mailpit: ver los mails en desarrollo](#mailpit-ver-los-mails-en-desarrollo)
- [Crear un usuario de backoffice](#crear-un-usuario-de-backoffice)

---

## Verificación de mail

### Cómo funciona

**Desde el 19/11, el mail se verifica ANTES de crear la cuenta.** Así un mail falso o ajeno nunca ocupa un mail, un DNI o un CUIT:

```
POST /registrarse → valida los datos → RegistroPendiente::guardar() → fila en registros_pendientes
                    (contraseña hasheada, HASH del token, vence en 24 h) → mail con el enlace
                    http://localhost:5500/vistas/sesion/verificar-mail.html?token=<token>  → responde 202
Usuario abre el enlace → verificar-mail.js lee el token de la URL → POST /verificar-mail {token}
Backend → RegistroPendiente::confirmar(): vuelve a controlar que el mail, el DNI y el CUIT sigan libres,
          crea la cuenta con mail_verificado_en = NOW(), borra el pendiente e inicia la sesión → responde 201
```

- De cada enlace se guarda el **hash** del token (`hash('sha256', $token)`), **nunca el token**. Si alguien leyera la tabla, no podría usar los enlaces. Es el mismo criterio que con las contraseñas.
- El token es de **un solo uso** y **vence a las 24 horas**. Los registros pendientes vencidos los borra la tarea diaria (`scripts/borrar-registros-vencidos.php`).
- **Un mail tiene a lo sumo un registro pendiente:** registrarse otra vez con el mismo mail lo reemplaza, y el enlace anterior deja de servir. Si alguien registra un mail ajeno y nunca abre el enlace, el dueño de ese mail se registra igual.
- Si el mail no se puede enviar (Mailpit apagado, SMTP o Brevo mal configurados), el registro **igual responde `202`**: el envío nunca lanza excepciones, el error queda en el log, y la persona pide otro enlace desde `post-registro.html` (`POST /reenviar-registro`).
- **Cuentas viejas sin verificar** (creadas antes del 19/11): siguen como estaban (decisión del 19/11). Pueden iniciar sesión, ven un aviso en su home con el botón "Reenviar" (`POST /reenviar-verificacion`), y para reservar necesitan el mail confirmado (`Autorizacion::requiereMailVerificado()`). Su enlace sale de la tabla `verificaciones_mail` y solo marca `mail_verificado_en`.
- **Google** no cambia: las cuentas que crea ya nacen verificadas, porque Google verificó el mail.

### `POST /verificar-mail`

**No requiere sesión**: el enlace del mail se puede abrir en otro navegador o en el celular.

**Envía:**

```json
{
    "token": "c25c67842a81c0dc649fd2f738bdd31f18e0b8469af535a4f327a23667614ab5"
}
```

| Campo | Reglas |
|---|---|
| `token` | Obligatorio. 64 caracteres hexadecimales (`/^[0-9a-f]{64}$/`). Es el que viaja en el query string del enlace del mail. |

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Tu cuenta quedó creada", "usuario": {...}}` | Era el enlace de un **registro**: se creó la cuenta (con `mail_verificado: true`) y quedó la **sesión iniciada** en ese navegador. `usuario` tiene el mismo formato que en `POST /iniciar-sesion`. |
| `200` | `{"ok": "Tu mail quedó verificado"}` | Era el enlace de una **cuenta vieja** sin verificar. Se guarda `usuarios.mail_verificado_en = NOW()` y el token queda usado. Si el usuario tenía la sesión abierta en ese navegador, además se le refresca (`mail_verificado` pasa a `true`). |
| `409` | `{"error": "Ese DNI ya tiene una cuenta. Si es tuya, iniciá sesión."}` | Mientras el registro esperaba, otra cuenta ocupó el mail, el DNI o el CUIT (cada uno tiene su mensaje). No se crea nada y el registro pendiente se borra. |
| `422` | `{"error": "El enlace no es válido"}` | Falta el token o no tiene el formato esperado. |
| `422` | `{"error": "El enlace no es válido o venció. Pedí uno nuevo desde el aviso de tu home."}` | No existe ese token, ya se usó, o pasaron más de 24 horas. |

### `POST /reenviar-registro`

**No requiere sesión**: la cuenta todavía no existe. Lo usa el botón "Reenviar el mail" de `post-registro.html`.

**Envía:** `{"mail": "ana@mail.com"}`

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Si hay un registro pendiente para ana@mail.com, te mandamos un enlace nuevo. Revisá también la carpeta de spam."}` | **Siempre el mismo mensaje**, haya o no un registro pendiente con ese mail: así no se puede averiguar quién se está registrando. Si lo hay y el último mail salió hace más de un minuto, genera un enlace nuevo (el anterior deja de servir), extiende el vencimiento 24 horas y lo manda. |
| `422` | `{"error": "El mail no es válido"}` | Falta el mail o no tiene formato válido. |

### `POST /reenviar-verificacion`

Solo para las **cuentas viejas** sin verificar (las nuevas nacen verificadas). **Requiere sesión** (cualquier rol). El id sale de la sesión, no del cuerpo: no se envía nada.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Te enviamos un nuevo mail de verificación a ana@mail.com"}` | Se generó un token nuevo y se mandó el mail. |
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión. |
| `401` | `{"error": "Tu cuenta está deshabilitada"}` | La cuenta fue deshabilitada por el backoffice. |
| `409` | `{"error": "Tu mail ya está verificado"}` | No hay nada que verificar. |
| `429` | `{"error": "Esperá un minuto antes de pedir otro mail"}` | Ya se le mandó un mail hace menos de 60 segundos. Como el registro manda uno, recién se puede reenviar un minuto después de crear la cuenta. |

---

## Backoffice: administración de usuarios

Las tres rutas **requieren el rol `backoffice`** (`Autorizacion::requiere('backoffice')`): sin sesión responden `401 {"error": "No hay sesión iniciada"}` y con otro rol `403 {"error": "No tenés permiso para hacer esto"}`.

El rol `backoffice` es la administración interna de la plataforma. **No es `administrador`**, que es el administrador de hospedajes. No se registra desde la web: se crea con el script de consola (ver más abajo).

#### El objeto `usuario` del backoffice

```json
{
    "id": 9,
    "nombre": "Ana Pérez",
    "mail": "ana@mail.com",
    "dni": "30123456",
    "rol": "propietario",
    "activo": true,
    "mail_verificado": false,
    "fecha_alta": "2026-10-09 14:20:00"
}
```

- `dni` puede ser `null` (cuentas creadas con Google o usuarios de prueba anteriores).
- `activo` y `mail_verificado` son **booleanos**. `mail_verificado` es `mail_verificado_en IS NOT NULL`.
- **Nunca** se devuelve la columna `contrasenia`.

### `GET /backoffice/usuarios`

```
GET /backoffice/usuarios?buscar=&rol=&activo=&pagina=
```

Todos los parámetros son opcionales y van en el query string.

| Parámetro | Reglas |
|---|---|
| `buscar` | Coincidencia parcial en `nombre`, `mail` o `dni`. |
| `rol` | Exacto. Uno de `huesped`, `propietario`, `administrador`, `operador`, `backoffice`. Cualquier otro valor **se ignora**. |
| `activo` | `1` (activos) o `0` (deshabilitados). Cualquier otro valor se ignora. |
| `pagina` | Entero ≥ 1. Por defecto `1`, y si llega algo que no es un número también. **20 usuarios por página.** |

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"usuarios": [...], "pagina": 1, "por_pagina": 20, "total": 57}` | Siempre que el usuario sea backoffice. `usuarios` es un array del objeto de arriba, ordenado por `id` descendente (los más nuevos primero). Si no hay coincidencias, el array viene vacío y `total` en `0`. |

`total` es la cantidad de usuarios que cumplen los filtros, **no** los de la página: el frontend calcula con eso la cantidad de páginas.

### `GET /backoffice/usuario`

```
GET /backoffice/usuario?id=9
```

| Parámetro | Reglas |
|---|---|
| `id` | Obligatorio. Entero positivo. |

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"usuario": {...}}` | Los mismos campos del listado, más `datos_fiscales`. |
| `422` | `{"error": "Usuario inválido"}` | Falta el `id` o no es un entero positivo. |
| `404` | `{"error": "El usuario no existe"}` | No hay un usuario con ese id. |

`datos_fiscales` es `null` para todos los usuarios que no son propietarios ni administradores. Para un propietario o un administrador:

```json
{
    "cobra_iva": true,
    "cuit": "20301234563",
    "razon_social": "Pérez SRL",
    "domicilio_fiscal": "Av. Siempre Viva 742",
    "cbu": "2850590940090418135201",
    "alias": "perez.cabanias",
    "titular_cuenta": "Ana Pérez",
    "billetera": "0x734779432891a4e2c9152fdf6ecffc34825b36b4"
}
```

`cuit`, `razon_social` y `domicilio_fiscal` son `null` si no cobra IVA. `alias` es `null` si no cargó uno.

### `POST /backoffice/usuario/estado`

Deshabilita o reactiva una cuenta.

**Envía:**

```json
{
    "id": 9,
    "activo": false
}
```

| Campo | Reglas |
|---|---|
| `id` | Obligatorio. Entero positivo. No puede ser el del propio backoffice logueado. |
| `activo` | Obligatorio. **Booleano** (`true` o `false`): no se aceptan `"1"`, `1` ni `"true"`. |

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Cuenta deshabilitada", "usuario": {...}}` | Se guardó el cambio. `usuario` viene con el estado ya actualizado, así el frontend no tiene que volver a pedirlo. Con `activo: true` el mensaje es `"Cuenta reactivada"`. |
| `422` | `{"error": "Usuario inválido"}` | Falta el `id` o no es un entero positivo. |
| `422` | `{"error": "El estado es inválido"}` | `activo` no es un booleano. |
| `422` | `{"error": "No podés deshabilitar tu propia cuenta"}` | El `id` es el del backoffice logueado. Así nadie se deja afuera del sistema. |
| `404` | `{"error": "El usuario no existe"}` | No hay un usuario con ese id. |

**Qué le pasa a un usuario deshabilitado:**

- No puede iniciar sesión: el login responde `403 {"error": "Tu cuenta está deshabilitada"}`.
- Si tenía la sesión abierta, la pierde en la próxima petición protegida: `Autorizacion::requiereSesion()` controla `activo` en la base en cada petición, le cierra la sesión y responde `401 {"error": "Tu cuenta está deshabilitada"}`.

---

## Mailpit: ver los mails en desarrollo

**Mailpit** es un servidor SMTP falso que atrapa todos los mails y los muestra en una página web, así no se mandan mails reales mientras se desarrolla.

```bash
docker run -d --name mailpit -p 8025:8025 -p 1025:1025 axllent/mailpit
```

- Los mails se ven en **http://localhost:8025**.
- El SMTP escucha en `127.0.0.1:1025`: son los valores que ya trae `config/configuracion.example.php`, así que no hay que configurar nada.
- Las veces siguientes alcanza con `docker start mailpit`.

En producción se cambian los datos de `smtp` en `config/configuracion.php` por los de un proveedor real (Brevo, Mailgun, Amazon SES, o Gmail con una "contraseña de aplicación"), **sin tocar código**:

```php
'smtp' => [
    'host'             => 'smtp.proveedor.com',
    'port'             => 587,
    'usuario'          => 'el-usuario',
    'contrasenia'      => 'la-contraseña',
    'cifrado'          => 'tls',
    'remitente'        => 'no-responder@tudominio.com',
    'nombre_remitente' => 'Alojamiento temporario',
],
```

---

## Crear un usuario de backoffice

Las cuentas de backoffice **no se registran desde la web**: si se pudiera, cualquiera se haría administrador de la plataforma. Se crean por consola, desde la raíz del backend:

```bash
php scripts/crear-usuario-backoffice.php "Nombre Apellido" admin@alojamiento.local
```

El script pide la contraseña dos veces por consola (así no queda en el historial de la terminal), valida los datos y crea el usuario con `rol = 'backoffice'` y el mail ya verificado. Después se entra con ese mail desde el login normal, y el sistema lleva a `index-backoffice.html`.

La carpeta `scripts/` está **fuera de `index/`**, así que el servidor web no la expone, y el script corta si no se lo ejecuta desde la línea de comandos.
