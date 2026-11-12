# API: reservas (Épica 4)

Las reservas de la plataforma. Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

**Seña:** la reserva se crea **pendiente** y guarda sus noches durante `minutos_bloqueo_reserva` (15 por defecto) mientras el huésped paga la seña ([pagos](pagos.md)). Pagada, pasa a **confirmada**. Si no paga a tiempo, deja de ocupar sus noches y se ve **expirada**. Toda reserva tiene seña: es de al menos el 1 % (decisión #52). El saldo llega con la épica L2-6.

**Noches:** una reserva del 5 al 10 ocupa las noches 5, 6, 7, 8 y 9. La fecha de egreso no es una noche: ese día puede entrar otro huésped (RN-08). Las reservas **pendientes (sin vencer), confirmadas y finalizadas** ocupan sus noches contratadas en la búsqueda, el detalle, el calendario y los bloqueos; las canceladas y expiradas, no.

## Crear una reserva

```
POST /reservas/crear
{"unidad_id": 5, "ingreso": "2026-12-05", "egreso": "2026-12-10", "personas": 2, "acepta_politica": true}
```

Reserva un **huésped** (o un **propietario**, que también es huésped) con el **mail verificado y el DNI cargado en su cuenta**. El propietario no puede reservar su propia unidad. Las cuentas de Google sin DNI deben completar `POST /completar-datos` antes: enviar un DNI en el cuerpo de la reserva no reemplaza ese paso. El DNI se consulta desde la base en cada petición protegida, incluso si se completó desde otra sesión.

Las reglas son las mismas que muestra el detalle (`GET /alojamiento`): ingreso de mañana en adelante, hasta 60 noches, todas las noches con precio y libres, y el mínimo de noches de la temporada (RN-09). Dos huéspedes que reservan las mismas fechas a la vez nunca quedan superpuestos: el segundo recibe el `409`.

Se guarda la **foto de las condiciones** que el huésped acepta: el total, el % de seña, la seña y la política de cancelación de la unidad. Si el propietario las cambia después, la reserva no cambia.

**Descuento por estadía larga (decisión #69):** `importe_sin_descuento` es la suma del precio de cada noche. `porcentaje_descuento` es el escalón de la unidad que alcanza la estadía, `"0.00"` si no alcanza ninguno. `importe_total` es lo que paga el huésped, **con el descuento**, y de ahí salen la seña y el saldo.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Reservaste Casa del Bosque: pagá la seña antes de las 17:15 para confirmarla", "reserva": {"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-15", "noches": 10, "personas": 2, "importe_sin_descuento": "700000.00", "porcentaje_descuento": "10.00", "importe_total": "630000.00", "importe_sena": "126000.00", "vence_en": "2026-11-05T20:15:00Z"}}` | Se reservó. `vence_en` (UTC) es hasta cuándo se puede pagar la seña. |
| `401` | `{"error": "No hay sesión iniciada"}` | |
| `403` | `{"error": "Tenés que verificar tu mail para hacer esto"}` o `{"error": "No tenés permiso para hacer esto"}` | Sin el mail verificado, o es administrador, operador o backoffice. |
| `404` | `{"error": "No encontramos ese alojamiento"}` | No existe o no está publicada. |
| `422` | `{"error": "Completá tu DNI para reservar"}` | La cuenta todavía no tiene DNI; no se crea la reserva ni se ocupan noches. |
| `409` | `{"error": "Hay noches ocupadas en esas fechas"}`, `{"error": "Hay noches sin precio en esas fechas"}`, `{"error": "Mínimo 7 noches en temporada alta"}` o `{"error": "Este alojamiento no se puede reservar por ahora"}` | Las fechas no se pueden reservar, o la unidad no está disponible. |
| `422` | `{"error": "Para reservar tenés que aceptar la política de cancelación"}`, `{"error": "Tiene lugar para 4 personas"}`, `{"error": "No podés reservar tu propia unidad"}` y los de las fechas y las personas (los mismos que `GET /alojamiento`) | |

## Mis reservas (el huésped)

```
GET /reservas/mias
```

Las reservas del usuario logueado (huésped o propietario), **todas** (también las canceladas y expiradas), de la más nueva a la más vieja según el ingreso. En las canceladas, `cancelacion` dice quién canceló (`huesped`, `propietario` o `plataforma`; `null` en las de antes), el `motivo` si fue el propietario y la `excepcion` que le dio el propietario, en pesos; en las demás es `null`. `puede_pagar` es `true` solo para las pendientes sin vencer; `puede_cancelar`, para las pendientes sin vencer y las confirmadas antes del día del ingreso, y `vence_en` dice hasta cuándo se paga la seña (`null` en las demás). `saldo` es lo que falta pagar (`"0.00"` cuando ya está pago), `fecha_cobro` hasta cuándo se paga, y `puede_pagar_saldo` es `true` en las confirmadas con saldo (se paga con `GET /pagos/saldo`, ver `pagos.md`). Las reservas anteriores al cobro pueden tener `saldo` y `fecha_cobro` en `null`. Una pendiente vencida aparece con `"estado": "expirada"`. La dirección exacta no se muestra todavía: se le va a dar al huésped cuando la reserva esté confirmada (pagada).

Los campos de La 25:

- `descuento`: `{"porcentaje", "importe_sin_descuento"}` si la reserva tuvo descuento por estadía larga (#69); si no, `null`.
- `extiende_reserva_id`: si la reserva es una **extensión** (#70), el id de la reserva que extiende; si no, `null`. Una extensión aparece en la lista como cualquier otra reserva, y el front la muestra dentro de la original.
- `puede_extender`: `true` si se puede pedir una extensión de esta reserva (ver "Extender la estadía").
- `no_show`: si el huésped no se presentó (#67), `{"en": "2026-12-06T17:00:00Z", "excepcion": "5000.00" | null}`, con `"estado": "no_show"`; si no, `null`.
- Una extensión paga tiene `puede_cancelar` en `false`.

| Código | Cuerpo |
|---|---|
| `200` | `{"reservas": [{"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-10", "noches": 5, "personas": 2, "importe_total": "350000.00", "importe_sena": "70000.00", "vence_en": "2026-11-05T20:15:00Z", "puede_pagar": true, "puede_cancelar": true, "puede_extender": false, "extiende_reserva_id": null, "descuento": null, "no_show": null, "saldo": "280000.00", "fecha_cobro": "2026-11-30", "puede_pagar_saldo": false, "cancelacion": null, "alojamiento": {"id": 5, "nombre": "Casa del Bosque", "localidad": "Mar de las Pampas", "provincia": "Buenos Aires", "foto_principal": "https://res.cloudinary.com/..."}}]}` (o `[]`) |
| `401` / `403` | Sin sesión / no es huésped ni propietario. |

## Cancelar una reserva (L2-3, L2-18)

Se cancela una reserva **pendiente** (sin vencer) o **confirmada**, antes del día del ingreso. Las noches quedan libres.

- **Pendiente:** todavía no pagó nada, así que no hay devolución.
- **Confirmada:** se aplica la política que el huésped aceptó al reservar. Se retiene el % **de la seña** del tramo, según cuántos días faltan hasta la `fecha_cobro`: más de 30, de 15 a 30, o menos de 15 (también si el cobro ya pasó), y se devuelve el resto de la seña. **Si ya pagó el saldo, no se devuelve nada** (decisión #62): el propietario puede darle una excepción en las 48 hs siguientes. Las cuentas se hacen sobre los pagos confirmados, en wei (`validacion/Devolucion.php`).

La plataforma manda la devolución al contrato con `devolver(reservaId, huesped, monto)` a la billetera que pagó la seña (`FirmadorPagos`: hoy `FirmadorSimulado`, que se elige con `pagos.firmador` o `PAGOS_FIRMADOR`). Queda en `pagos` con `tipo = devolucion`. El orden es este:

1. Se cancela la reserva y se anota la devolución `pendiente`.
2. Se le avisa por mail al propietario (L2-18).
3. Se manda la transacción y la devolución pasa a `confirmado`, con su `tx_hash`.
4. Se le avisa por mail al huésped.

Si la red falla en el paso 3, la reserva queda cancelada igual y la devolución queda `pendiente` sin `tx_hash`. La reintenta `php scripts/reintentar-devoluciones.php` (a mano o con un cron de Railway), que solo toma las que tienen más de 5 minutos.

### Ver cuánto le devuelven antes de confirmar

```
GET /reservas/cancelacion?reserva_id=12
```

No cambia nada.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"reserva_id": 12, "estado": "confirmada", "tramo": "mas_de_30", "dias_hasta_cobro": 45, "porcentaje_retencion": "30.00", "pagado": {"sena": "42000.00", "saldo": "0.00"}, "retencion": "12600.00", "devolucion": "29400.00", "devolucion_wei": "294000000000000"}` | `tramo`: `mas_de_30`, `de_15_a_30` o `menos_de_15`. En una pendiente, todo en 0. |
| `404` / `409` | Los mismos de cancelar. | |

### Cancelar

```
POST /reservas/cancelar
{"reserva_id": 12}
```

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Cancelaste la reserva: esas noches quedaron libres"}` | Una pendiente. |
| `200` | `{"ok": "Cancelaste la reserva: esas noches quedaron libres. Te devolvimos $29.400,00 a tu billetera", "devolucion": {…lo mismo que la vista previa…, "estado": "confirmado", "tx_hash": "0x…", "red": 84532, "simulado": false}}` | Una confirmada. `red` y `simulado` dicen dónde salió la devolución: con `84532` y `false` es real y se puede ver en BaseScan; con los pagos simulados, `31337` y `true`. Si la red falló: "La devolución de $29.400,00 está en proceso: te avisamos por mail", con `"estado": "pendiente"` y `"tx_hash": null`. Si no hay nada que devolver (la política retiene toda la seña): "Según la política de cancelación, no corresponde devolución", con `estado`, `tx_hash`, `red` y `simulado` en `null`. |
| `404` | `{"error": "No encontramos esa reserva"}` | No existe, no es suya o el id no es válido. |
| `409` | `{"error": "Esa reserva ya estaba cancelada"}`, `{"error": "Esa reserva ya venció sin pagar la seña: sus noches ya están libres"}`, `{"error": "La estadía ya empezó: no se puede cancelar desde la app"}`, `{"error": "Esa estadía ya terminó"}`, `{"error": "El huésped no se presentó: esa reserva ya está resuelta"}`, `{"error": "Una extensión paga no se puede cancelar"}` o `{"error": "La reserva cambió mientras la cancelabas: actualizá la página"}` (se pagó o canceló al mismo tiempo) | |

## Cancela el propietario (decisión #63)

```
POST /unidades/reservas/cancelar
{"reserva_id": 12, "motivo": "Se rompió el termotanque"}
```

Solo el **propietario** de la unidad (el administrador recibe `403`). Se cancela una pendiente sin vencer o una confirmada antes del día del ingreso, con un **motivo de 5 a 500 caracteres**, que le llega al huésped por mail. Se le devuelve **todo lo que pagó**, seña y saldo, a la billetera con la que pagó la seña: misma devolución, mismo reintento y mismos estados que cuando cancela el huésped.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Cancelaste la reserva: esas noches quedaron libres y le avisamos al huésped"}` | Una pendiente: no hay nada que devolver |
| `200` | `{"ok": "Cancelaste la reserva: … le devolvemos $210.000,00 al huésped (todo lo que pagó)", "devolucion": {"pagado": {"sena": "42000.00", "saldo": "168000.00"}, "devolucion": "210000.00", "estado": "confirmado", "tx_hash": "0x…", "red": 84532, "simulado": false}}` | Una confirmada |
| `422` | `{"error": "Contale al huésped por qué cancelás (de 5 a 500 caracteres)"}` | Falta el motivo |
| `404` | `{"error": "No encontramos esa reserva"}` | No existe o es de una unidad de otro |
| `409` | Los mismos de `POST /reservas/cancelar` | Ya cancelada, vencida, empezada o terminada |

## La excepción del propietario (L2-19, decisiones #64 a #66)

Cuando el **huésped** cancela (o la plataforma, por el saldo vencido) y la política retiene algo, el propietario tiene **48 hs** para devolverle al huésped lo que quiera **de su parte** de lo retenido: el administrador y la plataforma cobran siempre su comisión. Se da una sola vez. Le llega al huésped en los minutos siguientes, en la misma transacción `liquidar` que le paga al propietario lo que queda ([liquidaciones](liquidaciones.md)). Mientras corren las 48 hs, la cancelada no se liquida.

```
GET /unidades/reservas/excepcion?reserva_id=12
```

```json
{"reserva_id": 12, "retenido": "12600.00", "maximo": "11655.00", "hasta": "2026-11-14T15:00:00Z",
 "excepcion": null, "puede": true, "motivo": null}
```

`maximo` es lo que le toca al propietario de lo retenido. Si `puede` es `false`, `motivo` dice por qué ("Pasaron las 48 hs para dar una excepción", "Ya le diste una excepción a esta reserva", "Solo se puede dar una excepción cuando cancela el huésped", …).

```
POST /unidades/reservas/excepcion
{"reserva_id": 12, "monto": "5000"}
```

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Listo: le devolvemos $5.000,00 al huésped. Le llega en los próximos minutos, junto con tu liquidación"}` | |
| `422` | `{"error": "Podés devolver hasta $11.655,00: es lo que te toca a vos de lo retenido (…)"}` o el del monto inválido | |
| `409` | `{"error": "<el motivo de arriba>"}` | Ya no se puede |
| `404` | `{"error": "No encontramos esa reserva"}` | |

## Reservas de una unidad (propietario y administrador)

```
GET /unidades/reservas?id=5
```

Las reservas **en curso y próximas** de la unidad (pendientes, confirmadas y finalizadas con egreso contratado de hoy en adelante), de la más cercana a la más lejana. Las ven el propietario y el administrador asignado; para cualquier otro la unidad "no existe" (`404`). Del huésped va **solo el nombre**. `puede_cancelar` es `true` solo para el propietario (#66), y nunca en una extensión paga. `extiende_reserva_id` dice si es una extensión (#70), y de cuál reserva.

`canceladas` (solo para el propietario; para el administrador, `[]`): las que canceló el huésped o la plataforma y los **no-show** (#67) de las últimas 48 hs. Cada una tiene:
- `motivo`: `cancelacion` o `no_show`;
- `cancelada_por`: `huesped` o `plataforma`, o `null` en un no-show;
- `cancelada_en`: cuándo se canceló, o cuándo se marcó el no-show;
- `excepcion`: lo mismo que `GET /unidades/reservas/excepcion`.

| Código | Cuerpo |
|---|---|
| `200` | `{"reservas": [{"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-10", "noches": 5, "personas": 2, "importe_total": "350000.00", "importe_sena": "70000.00", "huesped": "Juan", "extiende_reserva_id": null, "puede_cancelar": true}], "canceladas": [{"id": 9, "estado": "no_show", …, "motivo": "no_show", "cancelada_por": null, "cancelada_en": "2026-12-06T17:00:00Z", "excepcion": {…}}]}` |

## Agenda del operador (RF-10)

```
GET /operador/agenda
```

Las reservas **en curso y próximas** (pendientes y confirmadas con egreso de hoy en adelante) de las unidades que tiene asignadas el operador logueado, de la más cercana a la más lejana. Con ellas el front arma los ingresos y egresos de cada día. Va la **dirección** de la unidad (el operador recibe a los huéspedes ahí) y el nombre del huésped, pero **nunca los montos** (RN-19). Solo para operadores: `403` para cualquier otro rol.

| Código | Cuerpo |
|---|---|
| `200` | `{"reservas": [{"id": 12, "estado": "confirmada", "ingreso": "2026-12-05", "egreso": "2026-12-12", "egreso_original": "2026-12-10", "extensiones": [{"id": 31, "ingreso": "2026-12-10", "egreso": "2026-12-12", "noches": 2, "estado": "confirmada"}], "limite_ingreso": "2026-12-06T17:00:00Z", "noches": 7, "personas": 2, "huesped": "Juan", "estadia": null, "saldo_pagado": true, "puede_registrar_ingreso": true, "puede_registrar_egreso": false, "unidad": {"id": 5, "nombre": "Casa del Bosque", "hora_check_in": "14:00", "direccion": "Los Alerces 450", "localidad": "Mar de las Pampas", "provincia": "Buenos Aires"}}]}` (o `[]`) |

Qué significa cada campo nuevo (La 25):

| Campo | Qué es |
|---|---|
| `egreso` | La salida **real** de la estadía: si tiene extensiones confirmadas o finalizadas, es la salida de la última. `noches` se cuenta hasta esta fecha. |
| `egreso_original` | La salida de la reserva original, sin extensiones. Si es igual a `egreso`, no se extendió. |
| `extensiones` | Las extensiones **confirmadas o finalizadas** de la reserva (decisión #70), de la más temprana a la más tardía. Las extensiones nunca aparecen como reservas sueltas en la agenda: el ingreso y el egreso se registran en la original. |
| `limite_ingreso` | Hasta cuándo se puede registrar el ingreso, en UTC (`PlazoIngreso::limiteIso`): el horario de check-in del día del ingreso, más 24 hs. Pasado ese momento la reserva pasa a no-show. Es `null` si el ingreso ya se registró. |
| `saldo_pagado` | `true` si la reserva no debe nada (`monto_faltante` en 0). Sin el saldo pago no se puede registrar el ingreso (decisión #25). |
| `puede_registrar_ingreso` | `true` si está confirmada, sin ingreso, con el saldo pago, ya es el día del ingreso y no venció `limite_ingreso`. |
| `unidad.hora_check_in` | El horario de check-in de la unidad, `"HH:MM"` en hora de Argentina. |

## No-show (decisión #67)

Cada unidad tiene un **horario de check-in** (`hora_check_in`, 14:00 por defecto, en hora de Argentina). Desde ese horario del día del ingreso hay **24 hs** para registrar el ingreso. Lo pueden registrar el operador, el administrador o el propietario.

Si nadie lo registró, `php scripts/marcar-no-shows.php` (en las tareas frecuentes, cada 10 minutos) pasa la reserva a **`no_show`**, con `no_show_en`. No hay una marca manual: el no-show es la falta del ingreso. A partir de ahí:

- Se retiene **todo lo pagado** (la seña sola o la seña y el saldo), como cuando el huésped cancela con todo pagado (#62).
- El propietario tiene **48 hs** desde `no_show_en` para dar una excepción, con `GET` y `POST /unidades/reservas/excepcion`, igual que en una cancelación.
- Las noches se liberan: un no-show no ocupa noches.
- Lo retenido se liquida a las 48 hs, o antes si el propietario da la excepción.
- `POST /operador/estadias/registrar` con `tipo: ingreso` responde `409` con `"codigo": "PLAZO_INGRESO_VENCIDO"` si ya pasó el plazo, y `409` si la reserva es un no-show (ya no está confirmada). Los detalles están en [estadías y libro de huéspedes](estadias-y-huespedes.md).

## Extender la estadía (decisión #70)

Con el **ingreso registrado y sin egreso**, el huésped puede quedarse más noches, si están libres. La extensión es una **reserva nueva** vinculada a la original (`extiende_reserva_id`), de las noches que siguen a su egreso:

- cada noche lleva su precio, con el descuento de la **estadía completa** (#69);
- no exige el mínimo de noches;
- nace **pendiente**, guarda sus noches durante el mismo plazo que una seña y se paga **entera, en un solo pago**: es la seña de esa reserva (`porcentaje_sena` 100, sin saldo), con `GET /pagos/sena?reserva_id=<id de la extensión>` y `POST /pagos/confirmar` (ver [pagos](pagos.md));
- pagada, queda **confirmada**, ocupa sus noches y **no se cancela**;
- se liquida apenas se confirma el pago (#71).

### Cotizar

```
GET /reservas/extension?reserva_id=12&noches=2
```

No guarda nada.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"reserva_id": 12, "noches": 2, "ingreso": "2026-12-10", "egreso": "2026-12-12", "importe_sin_descuento": "140000.00", "porcentaje_descuento": "10.00", "importe_total": "126000.00"}` | `ingreso` es el egreso actual de la estadía; `egreso`, la nueva salida. |
| `404` | `{"error": "No encontramos esa reserva"}` | No existe o es de otro huésped. |
| `409` | `{"error": "Solo se puede extender una estadía en curso"}`, `{"error": "Hay noches ocupadas en esas fechas"}`, `{"error": "Hay noches sin precio en esas fechas"}` o `{"error": "Ya tenés una extensión esperando el pago"}` | |
| `422` | `{"error": "Indicá cuántas noches más (de 1 a 30)"}` | |

### Extender

```
POST /reservas/extender
{"reserva_id": 12, "noches": 2}
```

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Pagá la extensión antes de las 17:15 para confirmarla", "reserva": {"id": 31, "estado": "pendiente", "extiende_reserva_id": 12, "ingreso": "2026-12-10", "egreso": "2026-12-12", "noches": 2, "importe_sin_descuento": "140000.00", "porcentaje_descuento": "10.00", "importe_total": "126000.00", "importe_sena": "126000.00", "vence_en": "2026-12-09T20:15:00Z"}}` | Se creó la extensión pendiente. Se paga como la seña de la reserva 31. |
| `404` / `409` / `422` | Los mismos de cotizar. | |

## Bloqueos sobre noches reservadas

`POST /unidades/bloqueos/crear` responde `409` si el rango pisa una reserva pendiente o confirmada: bloquear esas noches sería una cancelación del propietario, que tiene su propio trato.

```
{"error": "Esas fechas tienen una reserva, con ingreso el 05/12/2026 y egreso el 10/12/2026: no se pueden bloquear"}
```


## Ampliación: estadías y libro de huéspedes

La agenda ahora incluye hechos de ingreso/egreso, permisos de acción, estadías abiertas atrasadas y egresos registrados hoy. El contrato completo y la carga/consulta/exportación del libro están en [estadias-y-huespedes.md](estadias-y-huespedes.md). Los ejemplos de agenda anteriores muestran sus campos básicos; se agregan `estadia`, `puede_registrar_ingreso` y `puede_registrar_egreso`.

Las reservas finalizadas conservan sus noches contratadas también ante un egreso anticipado: búsqueda, calendario y creación de bloqueos mantienen esa ocupación. No se alteran precios ni se ejecutan devoluciones.
