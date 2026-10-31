# API: alta y datos de la unidad (US2.1, US2.6)

Las rutas con las que el propietario da de alta su unidad, edita sus datos y sus fotos, y carga el depósito de garantía. Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos"; las localidades y el resumen de una unidad, en [`unidades-base.md`](unidades-base.md).

Todas necesitan sesión iniciada (el `fetch` lleva `credentials: 'include'`). El id de la unidad viaja en el **cuerpo JSON** en los `POST` (`"unidad_id": 5`) y en el **query string** en los `GET` (`?id=5`).

**Quién puede qué:**

| Ruta | Propietario de la unidad | Administrador asignado | Otro propietario o administrador | Huésped, operador, backoffice |
|---|---|---|---|---|
| `POST /unidades/crear`, `GET /unidades/mias` | sí | `403` | — | `403` |
| `GET /unidades/datos`, `POST /unidades/deposito` | sí | sí | `404` | `404` |
| `POST /unidades/editar` y las de fotos | sí | `403` | `404` | `403` |

Una unidad ajena responde 404 y no 403: así nadie puede averiguar qué unidades existen probando números. El 403 de la última fila sale de que esas rutas son solo de propietarios: se controla el rol antes de buscar la unidad.

**Errores comunes a las rutas de una unidad** (todas menos crear y "mis unidades"):

| Código | Cuerpo | Cuándo |
|---|---|---|
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión, o expiró. |
| `403` | `{"error": "No tenés permiso para hacer esto"}` | La ruta es solo del propietario y el usuario tiene otro rol. |
| `404` | `{"error": "No encontramos esa unidad"}` | La unidad no existe, o el usuario no la puede usar. |
| `422` | `{"error": "Unidad inválida"}` | Falta el id de la unidad, o no es un entero positivo. |

## Dar de alta una unidad

```
POST /unidades/crear
{"nombre": "Cabaña del Mar", "localidad_id": 1, "direccion": "Costanera 123", "capacidad": 4,
 "caracteristicas": "Dos dormitorios y parrilla", "deposito_garantia": "15000"}
```

El propietario logueado da de alta una unidad (US2.1). Queda **sin publicar** (`borrador`) y a nombre del usuario de la sesión: un `propietario_id` en el cuerpo se ignora. Se le copian de `parametros` el % de seña y la política de cancelación por defecto (seña 20 %; retiene 30 %, 50 % y 70 %): si después el backoffice cambia esos parámetros, la unidad no cambia.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Diste de alta tu unidad", "id": 7}` | Se creó la unidad. |
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión. |
| `403` | `{"error": "No tenés permiso para hacer esto"}` | El usuario no es propietario. |
| `422` | `{"error": "..."}` | Algún dato no es válido (ver la tabla de campos). |

**Campos** (los mismos para editar):

| Campo | Obligatorio | Regla | Error |
|---|---|---|---|
| `nombre` | sí | De 3 a 100 caracteres, sin contar los espacios de los extremos. | El nombre tiene que tener entre 3 y 100 caracteres |
| `localidad_id` | sí | El id de una localidad activa (`GET /localidades`). | Elegí una localidad de la lista |
| `direccion` | sí | De 5 a 200 caracteres. | La dirección tiene que tener entre 5 y 200 caracteres |
| `capacidad` | sí | Entero de 1 a 50 (personas). Acepta `4` o `"4"`. | La capacidad tiene que ser de 1 a 50 personas |
| `caracteristicas` | no | Hasta 2000 caracteres. Vacío = sin características (`null`). | Las características pueden tener hasta 2000 caracteres |
| `deposito_garantia` | no (solo al crear) | Ver [depósito de garantía](#depósito-de-garantía). | El depósito tiene que ser un monto en pesos, con hasta 2 decimales |

## Mis unidades

```
GET /unidades/mias
```

Las unidades del propietario logueado, **de la más nueva a la más vieja**, para "Mis hospedajes". No envía cuerpo.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"unidades": [...]}` (vacía si no tiene ninguna) | El usuario es propietario. |
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión. |
| `403` | `{"error": "No tenés permiso para hacer esto"}` | El usuario no es propietario. |

Cada unidad:

```json
{
  "id": 3,
  "nombre": "Casa del Bosque",
  "localidad": "Mar de las Pampas",
  "provincia": "Buenos Aires",
  "estado_publicacion": "publicada",
  "disponible": true,
  "foto_principal": "https://res.cloudinary.com/ynclnyuh/image/upload/v1/unidades/abc.jpg",
  "administrador": {"nombre": "Cacho", "porcentaje_comision": "15.00"},
  "deposito_garantia": "15000.00",
  "faltantes": []
}
```

| Campo | Qué es |
|---|---|
| `estado_publicacion` | `borrador` (sin publicar), `publicada` o `pausada`. |
| `disponible` | `false` si está fuera de servicio por un daño (RN-30). |
| `foto_principal` | La URL de la foto con `orden` 0, o `null` si no tiene fotos. |
| `administrador` | Quién la gestiona y su comisión, o `null` si es de **autogestión**. |
| `deposito_garantia` | Texto con 2 decimales, o `null` si no tiene depósito. |
| `faltantes` | Lo que le falta para poder publicarse (igual que en `GET /unidades/resumen`). Vacía = se puede publicar. |

## Datos de una unidad

```
GET /unidades/datos?id=5
```

Todo lo editable de la unidad y sus fotos, para la página `datos-unidad.html`. La ven el propietario y el administrador asignado; el administrador solo puede cambiar el depósito, por eso la respuesta trae `es_propietario`.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | Ver abajo. | El usuario es el propietario o el administrador de la unidad. |
| `401`, `404`, `422` | | Ver los errores comunes. |

```json
{"unidad": {
  "id": 1, "nombre": "Cabaña del Mar", "localidad_id": 1, "direccion": "Costanera 123", "capacidad": 4,
  "caracteristicas": "Dos dormitorios y parrilla", "deposito_garantia": "15000.00",
  "estado_publicacion": "publicada", "es_propietario": true,
  "fotos": [{"id": 1, "url": "https://res.cloudinary.com/ynclnyuh/image/upload/...", "orden": 0}]
}}
```

| Campo | Qué es |
|---|---|
| `caracteristicas` | Texto, o `null` si no tiene. |
| `deposito_garantia` | Texto con 2 decimales, o `null` si no tiene depósito. |
| `es_propietario` | `true` si quien la ve es el propietario; `false` si es el administrador (el front le muestra solo el depósito). |
| `fotos` | Ordenadas por `orden`: la primera (`orden` 0) es la principal. Vacía si no tiene. |

## Editar los datos

```
POST /unidades/editar
{"unidad_id": 5, "nombre": "Cabaña del Mar", "localidad_id": 1, "direccion": "Costanera 123", "capacidad": 4,
 "caracteristicas": "Dos dormitorios y parrilla"}
```

**Solo el propietario** cambia los datos básicos de su unidad. Los campos y sus reglas son los mismos del alta (sin el depósito). Si `caracteristicas` falta o viene vacío, queda en `null`. No cambia el estado de publicación, la disponibilidad, el operador, la seña, la política ni el depósito: cada uno tiene su propia ruta.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Guardamos los cambios"}` | Se guardaron los datos. |
| `422` | `{"error": "..."}` | Algún dato no es válido (ver la tabla de campos del alta). |
| `401`, `403`, `404` | | Ver los errores comunes. El administrador recibe 403. |

## Depósito de garantía

```
POST /unidades/deposito
{"unidad_id": 5, "deposito_garantia": "15000.50"}
```

El propietario **o el administrador** de la unidad cargan el depósito de garantía (US2.6). Es **informativo**: se cobra y se devuelve por fuera de la aplicación (RN-04).

`deposito_garantia`: `null`, `""` o ausente = **sin depósito**. Si no, un monto en pesos **mayor o igual a 0**, como número (`15000.5`) o texto (`"15000.5"`), con punto decimal y **hasta 2 decimales**, de hasta 10 cifras enteras (lo que entra en `decimal(12,2)`).

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Guardamos el depósito", "deposito_garantia": "15000.50"}` (o `null`) | Se guardó. El monto vuelve como texto con 2 decimales. |
| `422` | `{"error": "El depósito tiene que ser un monto en pesos, con hasta 2 decimales"}` | El monto es negativo, no es un número, tiene más de 2 decimales o es demasiado grande. |
| `401`, `404`, `422` | | Ver los errores comunes. |

## Fotos

Las imágenes **no pasan por el backend**: el navegador las sube directo a Cloudinary (con el *upload preset* sin firma `Alojamiento-temporario-unidades`) y después le manda al backend la URL que devolvió Cloudinary. Railway borra los archivos del disco en cada despliegue, así que el servidor no puede guardarlas.

```
navegador ──(1) el archivo──► Cloudinary ──► {"secure_url": "https://res.cloudinary.com/ynclnyuh/image/upload/v.../unidades/abc.jpg"}
navegador ──(2) {"unidad_id", "url": secure_url}──► POST /unidades/fotos/agregar
```

Reglas:

- **Como mucho 10 fotos** por unidad.
- **La URL tiene que ser de nuestra cuenta de Cloudinary** (empezar con `https://res.cloudinary.com/ynclnyuh/image/upload/`) y tener hasta 500 caracteres. Si no, cualquiera podría guardar una URL a cualquier sitio y nuestra página la mostraría.
- `orden` 0 es la **foto principal** (la de la tarjeta). Los `orden` quedan siempre seguidos (0, 1, 2...): la base lo exige con un índice único (`unidad_id`, `orden`).
- Se pueden mandar varias fotos a la vez: los pedidos de una misma unidad se atienden de a uno, así ninguna toma el `orden` de otra.
- **Quitar una foto no la borra de Cloudinary**: con el preset sin firma no se puede. Queda pendiente para cuando las subidas sean firmadas.

Las cuatro rutas son **solo del propietario** y devuelven la lista actualizada de fotos (`fotos`, ordenada por `orden`, igual que en `GET /unidades/datos`), así el front la vuelve a dibujar sin pedirla de nuevo.

### Agregar una foto

```
POST /unidades/fotos/agregar
{"unidad_id": 5, "url": "https://res.cloudinary.com/ynclnyuh/image/upload/v1/unidades/abc.jpg"}
```

La foto va al final. Si es la primera, queda como principal.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Agregamos la foto", "foto": {"id": 9, "url": "https://...", "orden": 2}, "fotos": [...]}` | Se agregó. |
| `422` | `{"error": "La foto no es válida"}` | La URL no es de nuestra cuenta de Cloudinary o tiene más de 500 caracteres. |
| `422` | `{"error": "Podés tener hasta 10 fotos por unidad"}` | La unidad ya tiene 10 fotos. |
| `401`, `403`, `404` | | Ver los errores comunes. |

### Quitar una foto

```
POST /unidades/fotos/quitar
{"unidad_id": 5, "foto_id": 9}
```

Las fotos que estaban después bajan un lugar. Si se quita la principal, la siguiente pasa a ser la principal.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Quitamos la foto", "fotos": [...]}` | Se quitó. |
| `404` | `{"error": "No encontramos esa foto"}` | La foto no existe, no es de esa unidad o `foto_id` no es un entero positivo. |
| `401`, `403`, `404` | | Ver los errores comunes. |

### Elegir la foto principal

```
POST /unidades/fotos/principal
{"unidad_id": 5, "foto_id": 9}
```

La elegida pasa a `orden` 0; las que estaban antes que ella bajan un lugar en la lista y las de después no se mueven. Si ya era la principal, no cambia nada.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Elegiste la foto principal", "fotos": [...]}` | Se eligió. |
| `404` | `{"error": "No encontramos esa foto"}` | La foto no existe, no es de esa unidad o `foto_id` no es un entero positivo. |
| `401`, `403`, `404` | | Ver los errores comunes. |

### Elegir posición y foto principal

```http
POST /unidades/fotos/mover
{"unidad_id": 5, "foto_id": 9, "posicion": 3}
```

`posicion` es un entero desde **1** hasta la cantidad actual de fotos. Elegir **1** convierte la foto en principal. La foto se inserta directamente en ese lugar y las demás conservan su orden relativo: `[A, B, C, D]`, mover D a 2, queda `[A, D, B, C]`. Enviar la posición actual no modifica nada. La API devuelve `orden` desde 0, como las rutas existentes.

| Código | Respuesta | Motivo |
| --- | --- | --- |
| `200` | `{"ok": "Guardamos el orden de las fotos", "fotos": [...]}` | Posición guardada o sin cambios. |
| `422` | `{"error": "Elegí una posición válida para la foto"}` | Posición ausente, no entera o fuera de la lista actual. |
| `404` | `{"error": "No encontramos esa foto"}` | Foto inexistente, ajena a la unidad o ID inválido. |

Requiere propietario autenticado: sin sesión responde `401`; administrador u otro rol sin permiso, `403`; unidad de otro propietario, `404`. Comparte el bloqueo de unidad con agregar, quitar y elegir principal; desplazamiento y lectura de respuesta ocurren dentro de la misma transacción. No requiere migración. La ruta anterior `/principal` continúa disponible para clientes existentes; la interfaz usa un único selector de posición.

El frontend valida el cupo de 10 fotos antes de iniciar una subida externa. El backend sigue validándolo para solicitudes directas y concurrencia. La firma de subidas y el borrado del archivo remoto continúan pendientes.
