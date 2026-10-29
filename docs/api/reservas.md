# API: reservas (Épica 4)

Las reservas de la plataforma. Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

**Entrega 4, sin pagos:** la reserva se crea **pendiente** y ocupa sus noches hasta que se cancele. Los pagos (seña y saldo) llegan en la entrega final, con la pasarela de pago por blockchain. Ahí la reserva va a pasar a **confirmada** al pagarse la seña, y las pendientes sin pagar van a vencer (**expirada**).

**Noches:** una reserva del 5 al 10 ocupa las noches 5, 6, 7, 8 y 9. La fecha de egreso no es una noche: ese día puede entrar otro huésped (RN-08). Las reservas **pendientes y confirmadas** ocupan sus noches en la búsqueda, el detalle, el calendario y los bloqueos; las canceladas y expiradas, no.

## Crear una reserva

```
POST /reservas/crear
{"unidad_id": 5, "ingreso": "2026-12-05", "egreso": "2026-12-10", "personas": 2, "acepta_politica": true}
```

Reserva un **huésped** (o un **propietario**, que también es huésped) con el **mail verificado**. El propietario no puede reservar su propia unidad.

Las reglas son las mismas que muestra el detalle (`GET /alojamiento`): ingreso de mañana en adelante, hasta 60 noches, todas las noches con precio y libres, y el mínimo de noches de la temporada (RN-09). Dos huéspedes que reservan las mismas fechas a la vez nunca quedan superpuestos: el segundo recibe el `409`.

Se guarda la **foto de las condiciones** que el huésped acepta: el total (la suma del precio de cada noche), el % de seña, la seña y la política de cancelación de la unidad. Si el propietario las cambia después, la reserva no cambia.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Reservaste Casa del Bosque", "reserva": {"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-10", "noches": 5, "personas": 2, "importe_total": "350000.00", "importe_sena": "70000.00"}}` | Se reservó. |
| `401` | `{"error": "No hay sesión iniciada"}` | |
| `403` | `{"error": "Tenés que verificar tu mail para hacer esto"}` o `{"error": "No tenés permiso para hacer esto"}` | Sin el mail verificado, o es administrador, operador o backoffice. |
| `404` | `{"error": "No encontramos ese alojamiento"}` | No existe o no está publicada. |
| `409` | `{"error": "Hay noches ocupadas en esas fechas"}`, `{"error": "Hay noches sin precio en esas fechas"}`, `{"error": "Mínimo 7 noches en temporada alta"}` o `{"error": "Este alojamiento no se puede reservar por ahora"}` | Las fechas no se pueden reservar, o la unidad no está disponible. |
| `422` | `{"error": "Para reservar tenés que aceptar la política de cancelación"}`, `{"error": "Tiene lugar para 4 personas"}`, `{"error": "No podés reservar tu propia unidad"}` y los de las fechas y las personas (los mismos que `GET /alojamiento`) | |

## Mis reservas (el huésped)

```
GET /reservas/mias
```

Las reservas del usuario logueado (huésped o propietario), **todas** (también las canceladas), de la más nueva a la más vieja según el ingreso. `puede_cancelar` es `true` solo para las pendientes. La dirección exacta no se muestra todavía: se le va a dar al huésped cuando la reserva esté confirmada (pagada).

| Código | Cuerpo |
|---|---|
| `200` | `{"reservas": [{"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-10", "noches": 5, "personas": 2, "importe_total": "350000.00", "importe_sena": "70000.00", "puede_cancelar": true, "alojamiento": {"id": 5, "nombre": "Casa del Bosque", "localidad": "Mar de las Pampas", "provincia": "Buenos Aires", "foto_principal": "https://res.cloudinary.com/..."}}]}` (o `[]`) |
| `401` / `403` | Sin sesión / no es huésped ni propietario. |

## Cancelar una reserva

```
POST /reservas/cancelar
{"reserva_id": 12}
```

El huésped cancela **su reserva pendiente** y las noches quedan libres. Como todavía no pagó nada, no hay devolución. Cancelar una reserva confirmada, con su política de cancelación, llega con los pagos.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Cancelaste la reserva: esas noches quedaron libres"}` | |
| `404` | `{"error": "No encontramos esa reserva"}` | No existe, no es suya o el id no es válido. |
| `409` | `{"error": "Esa reserva ya estaba cancelada"}` o `{"error": "Por ahora solo se puede cancelar una reserva pendiente de pago"}` | |

## Reservas de una unidad (propietario y administrador)

```
GET /unidades/reservas?id=5
```

Las reservas **en curso y próximas** de la unidad (pendientes y confirmadas con egreso de hoy en adelante), de la más cercana a la más lejana. Las ven el propietario y el administrador asignado; para cualquier otro la unidad "no existe" (`404`). Del huésped va **solo el nombre**.

| Código | Cuerpo |
|---|---|
| `200` | `{"reservas": [{"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-10", "noches": 5, "personas": 2, "importe_total": "350000.00", "importe_sena": "70000.00", "huesped": "Juan"}]}` (o `[]`) |

## Bloqueos sobre noches reservadas

`POST /unidades/bloqueos/crear` responde `409` si el rango pisa una reserva pendiente o confirmada: bloquear esas noches sería una cancelación del propietario, que tiene su propio trato.

```
{"error": "Esas fechas tienen una reserva, con ingreso el 05/12/2026 y egreso el 10/12/2026: no se pueden bloquear"}
```
