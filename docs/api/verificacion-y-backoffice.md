# API: verificación de mail y backoffice

Rutas de la **Tarea 2**. Las convenciones generales (URL base, formato JSON, forma de los errores, cookie de sesión y `credentials: 'include'`) están en el [`Readme.md`](../../Readme.md), sección "API: rutas y contratos".

Índice:

- [Verificación de mail](#verificación-de-mail)
  - [`POST /verificar-mail`](#post-verificar-mail)
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

```
Registro → VerificacionMail::enviar(id) → genera un token aleatorio, guarda su HASH con vencimiento
           de 24 h, y manda un mail con el enlace
           http://localhost:5500/vistas/sesion/verificar-mail.html?token=<token>
Usuario abre el enlace → verificar-mail.js lee el token de la URL → POST /verificar-mail {token}
Backend busca el hash, controla que no esté usado ni vencido → usuarios.mail_verificado_en = NOW()
```

- En la tabla `verificaciones_mail` se guarda el **hash** del token (`hash('sha256', $token)`), **nunca el token**. Si alguien leyera la tabla, no podría usar los enlaces. Es el mismo criterio que con las contraseñas.
- El token es de **un solo uso** (`usado_en`) y **vence a las 24 horas** (`expira_en`).
- Cada vez que se manda un mail nuevo, se borran los tokens anteriores de ese usuario que no se hayan usado: **vale solo el último enlace**.
- Si el mail no se puede enviar (Mailpit apagado, SMTP mal configurado), el registro **igual responde `201`**: `VerificacionMail::enviar()` nunca lanza excepciones, y el error queda en la terminal de `php -S`.
- **Política:** un usuario sin verificar **puede iniciar sesión** y usar la plataforma, pero ve un aviso en su home. Para las acciones que a futuro exijan el mail confirmado (reservar, publicar) está `Autorizacion::requiereMailVerificado()`.

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
| `200` | `{"ok": "Tu mail quedó verificado"}` | El token era válido. Se guarda `usuarios.mail_verificado_en = NOW()` y el token queda usado. Si el usuario tenía la sesión abierta en ese navegador, además se le refresca (`mail_verificado` pasa a `true`). |
| `422` | `{"error": "El enlace no es válido"}` | Falta el token o no tiene el formato esperado. |
| `422` | `{"error": "El enlace no es válido o venció. Pedí uno nuevo desde el aviso de tu home."}` | No existe ese token, ya se usó, o pasaron más de 24 horas. |

### `POST /reenviar-verificacion`

**Requiere sesión** (cualquier rol). El id sale de la sesión, no del cuerpo: no se envía nada.

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

`datos_fiscales` es `null` para todos los usuarios que no son propietarios. Para un propietario:

```json
{
    "cobra_iva": true,
    "cuit": "20301234563",
    "razon_social": "Pérez SRL",
    "domicilio_fiscal": "Av. Siempre Viva 742"
}
```

`cuit`, `razon_social` y `domicilio_fiscal` son `null` si el propietario no cobra IVA.

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
