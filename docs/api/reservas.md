# API: reservas (Épica 4)

Las reservas de la plataforma. Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

**Seña:** la reserva se crea **pendiente** y guarda sus noches durante `minutos_bloqueo_reserva` (15 por defecto) mientras el huésped paga la seña ([pagos](pagos.md)). Pagada, pasa a **confirmada**. Si no paga a tiempo, deja de ocupar sus noches y se ve **expirada**. Una unidad sin seña (0 %) confirma la reserva al momento. El saldo llega con la épica L2-6.

**Noches:** una reserva del 5 al 10 ocupa las noches 5, 6, 7, 8 y 9. La fecha de egreso no es una noche: ese día puede entrar otro huésped (RN-08). Las reservas **pendientes (sin vencer), confirmadas y finalizadas** ocupan sus noches contratadas en la búsqueda, el detalle, el calendario y los bloqueos; las canceladas y expiradas, no.

## Crear una reserva

```
POST /reservas/crear
{"unidad_id": 5, "ingreso": "2026-12-05", "egreso": "2026-12-10", "personas": 2, "acepta_politica": true}
```

Reserva un **huésped** (o un **propietario**, que también es huésped) con el **mail verificado y el DNI cargado en su cuenta**. El propietario no puede reservar su propia unidad. Las cuentas de Google sin DNI deben completar `POST /completar-datos` antes: enviar un DNI en el cuerpo de la reserva no reemplaza ese paso. El DNI se consulta desde la base en cada petición protegida, incluso si se completó desde otra sesión.

Las reglas son las mismas que muestra el detalle (`GET /alojamiento`): ingreso de mañana en adelante, hasta 60 noches, todas las noches con precio y libres, y el mínimo de noches de la temporada (RN-09). Dos huéspedes que reservan las mismas fechas a la vez nunca quedan superpuestos: el segundo recibe el `409`.

Se guarda la **foto de las condiciones** que el huésped acepta: el total (la suma del precio de cada noche), el % de seña, la seña y la política de cancelación de la unidad. Si el propietario las cambia después, la reserva no cambia.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Reservaste Casa del Bosque: pagá la seña antes de las 17:15 para confirmarla", "reserva": {"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-10", "noches": 5, "personas": 2, "importe_total": "350000.00", "importe_sena": "70000.00", "vence_en": "2026-11-05T20:15:00Z"}}` | Se reservó. `vence_en` (UTC) es hasta cuándo se puede pagar la seña. Sin seña: `"estado": "confirmada"`, `"vence_en": null` y el mensaje "Reservaste Casa del Bosque: tu reserva está confirmada". |
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

Las reservas del usuario logueado (huésped o propietario), **todas** (también las canceladas y expiradas), de la más nueva a la más vieja según el ingreso. `puede_pagar` es `true` solo para las pendientes sin vencer; `puede_cancelar`, para las pendientes sin vencer y las confirmadas antes del día del ingreso, y `vence_en` dice hasta cuándo se paga la seña (`null` en las demás). `saldo` es lo que falta pagar (`"0.00"` cuando ya está pago), `fecha_cobro` hasta cuándo se paga, y `puede_pagar_saldo` es `true` en las confirmadas con saldo (se paga con `GET /pagos/saldo`, ver `pagos.md`). Las reservas anteriores al cobro pueden tener `saldo` y `fecha_cobro` en `null`. Una pendiente vencida aparece con `"estado": "expirada"`. La dirección exacta no se muestra todavía: se le va a dar al huésped cuando la reserva esté confirmada (pagada).

| Código | Cuerpo |
|---|---|
| `200` | `{"reservas": [{"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-10", "noches": 5, "personas": 2, "importe_total": "350000.00", "importe_sena": "70000.00", "vence_en": "2026-11-05T20:15:00Z", "puede_pagar": true, "puede_cancelar": true, "saldo": "280000.00", "fecha_cobro": "2026-11-30", "puede_pagar_saldo": false, "alojamiento": {"id": 5, "nombre": "Casa del Bosque", "localidad": "Mar de las Pampas", "provincia": "Buenos Aires", "foto_principal": "https://res.cloudinary.com/..."}}]}` (o `[]`) |
| `401` / `403` | Sin sesión / no es huésped ni propietario. |

## Cancelar una reserva (L2-3, L2-18)

Se cancela una reserva **pendiente** (sin vencer) o **confirmada**, antes del día del ingreso. Las noches quedan libres.

- **Pendiente:** todavía no pagó nada, así que no hay devolución.
- **Confirmada:** se aplica la política que el huésped aceptó al reservar. Se retiene el % **de la seña** del tramo, según cuántos días faltan hasta la `fecha_cobro`: más de 30, de 15 a 30, o menos de 15 (también si el cobro ya pasó). Se devuelve el resto de la seña, más **el saldo entero** si ya lo pagó: el huésped nunca pierde más que la seña. Las cuentas se hacen sobre los pagos confirmados, en wei (`validacion/Devolucion.php`).

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
| `200` | `{"ok": "Cancelaste la reserva: esas noches quedaron libres. Te devolvimos $29.400,00 a tu billetera", "devolucion": {…lo mismo que la vista previa…, "estado": "confirmado", "tx_hash": "0x…"}}` | Una confirmada. Si la red falló: "La devolución de $29.400,00 está en proceso: te avisamos por mail", con `"estado": "pendiente"` y `"tx_hash": null`. Sin nada pagado (por ejemplo, una seña del 0 %): "Según la política de cancelación, no corresponde devolución", con `"estado": null`. |
| `404` | `{"error": "No encontramos esa reserva"}` | No existe, no es suya o el id no es válido. |
| `409` | `{"error": "Esa reserva ya estaba cancelada"}`, `{"error": "Esa reserva ya venció sin pagar la seña: sus noches ya están libres"}`, `{"error": "La estadía ya empezó: no se puede cancelar desde la app"}`, `{"error": "Esa estadía ya terminó"}` o `{"error": "La reserva cambió mientras la cancelabas: actualizá la página"}` (se pagó o canceló al mismo tiempo) | |

## Reservas de una unidad (propietario y administrador)

```
GET /unidades/reservas?id=5
```

Las reservas **en curso y próximas** de la unidad (pendientes, confirmadas y finalizadas con egreso contratado de hoy en adelante), de la más cercana a la más lejana. Las ven el propietario y el administrador asignado; para cualquier otro la unidad "no existe" (`404`). Del huésped va **solo el nombre**.

| Código | Cuerpo |
|---|---|
| `200` | `{"reservas": [{"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-10", "noches": 5, "personas": 2, "importe_total": "350000.00", "importe_sena": "70000.00", "huesped": "Juan"}]}` (o `[]`) |

## Agenda del operador (RF-10)

```
GET /operador/agenda
```

Las reservas **en curso y próximas** (pendientes y confirmadas con egreso de hoy en adelante) de las unidades que tiene asignadas el operador logueado, de la más cercana a la más lejana. Con ellas el front arma los ingresos y egresos de cada día. Va la **dirección** de la unidad (el operador recibe a los huéspedes ahí) y el nombre del huésped, pero **nunca los montos** (RN-19). Solo para operadores: `403` para cualquier otro rol.

| Código | Cuerpo |
|---|---|
| `200` | `{"reservas": [{"id": 12, "estado": "pendiente", "ingreso": "2026-12-05", "egreso": "2026-12-10", "noches": 5, "personas": 2, "huesped": "Juan", "unidad": {"id": 5, "nombre": "Casa del Bosque", "direccion": "Los Alerces 450", "localidad": "Mar de las Pampas", "provincia": "Buenos Aires"}}]}` (o `[]`) |

## Bloqueos sobre noches reservadas

`POST /unidades/bloqueos/crear` responde `409` si el rango pisa una reserva pendiente o confirmada: bloquear esas noches sería una cancelación del propietario, que tiene su propio trato.

```
{"error": "Esas fechas tienen una reserva, con ingreso el 05/12/2026 y egreso el 10/12/2026: no se pueden bloquear"}
```


## Ampliación: estadías y libro de huéspedes

La agenda ahora incluye hechos de ingreso/egreso, permisos de acción, estadías abiertas atrasadas y egresos registrados hoy. El contrato completo y la carga/consulta/exportación del libro están en [estadias-y-huespedes.md](estadias-y-huespedes.md). Los ejemplos de agenda anteriores muestran sus campos básicos; se agregan `estadia`, `puede_registrar_ingreso` y `puede_registrar_egreso`.

Las reservas finalizadas conservan sus noches contratadas también ante un egreso anticipado: búsqueda, calendario y creación de bloqueos mantienen esa ocupación. No se alteran precios ni se ejecutan devoluciones.
