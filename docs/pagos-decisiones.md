# Decisiones del Rol 2: pagos y reservas

Las decisiones que tomamos al implementar la parte del Rol 2 del plan de la entrega del 80 %, y por qué. Las que dicen **a charlar** conviene confirmarlas con el equipo. El contrato de la API está en [`api/pagos.md`](api/pagos.md) y [`api/reservas.md`](api/reservas.md).

## Verificación de los pagos

| # | Decisión | Por qué |
|---|---|---|
| 1 | El controlador no habla con la red: usa una interfaz, `VerificadorPagos`. Hoy hay un solo verificador, `VerificadorSimulado`, que se elige con `pagos.verificador` (variable `PAGOS_VERIFICADOR`). `Blockchain.php` va a ser otro verificador con la misma interfaz. | Así avanzamos sin el contrato del Rol 1. Cuando esté, cambia una variable y no cambia ni el controlador ni la base. |
| 2 | El simulado decide por cómo termina el hash: **`dead`** falla, **`0000`** queda pendiente y **cualquier otro** se confirma. | Se prueban los tres casos sin MetaMask, en los tests y desde el front. |
| 3 | El simulado guarda los pagos con `red` = **31337** (Anvil) y la billetera `0x…beef`. | Para que un pago de mentira nunca se confunda con uno real de Base Sepolia (84532). |
| 4 | Una transacción todavía pendiente (202) **no guarda nada**: el front vuelve a preguntar con el mismo hash. | Evita filas a medias. El estado `pendiente` de `pagos` queda sin usar por ahora. |
| 5 | Un intento fallido se guarda como `fallido` **solo si se sabe qué billetera lo mandó**. | `pagos.billetera` es obligatoria en la tabla de Valentino. |
| 6 | **El motivo del fallo no se guarda.** Va en la respuesta (402) y en el mail. | La tabla `pagos` es la de Valentino, definitiva, y no tiene esa columna. Si se quiere guardar, habría que pedirle una migración. |
| 7 | Un mismo `tx_hash` no paga dos cosas, **cualquiera sea la billetera**. Se compara en minúsculas. | La base lo asegura solo por billetera (`uq_pagos_tx_billetera`); el controlador lo hace más estricto. |
| 8 | La reserva se traba con `FOR UPDATE` mientras se confirma el pago. | Dos confirmaciones al mismo tiempo no generan dos pagos. Con el verificador real hay que ponerle un timeout corto al RPC, porque la reserva queda trabada mientras tanto. |
| 9 | La reserva de otro huésped responde **404**, no 403. | Nadie averigua qué reservas existen probando números. |

## Montos

| # | Decisión | Por qué |
|---|---|---|
| 10 | Pesos a wei: `pesos × 10^18 ÷ pesos_por_eth`, con **bcmath**, **truncando** los decimales de wei. | Los floats pierden precisión con números de 18 cifras. Por eso agregamos `bcmath` al `Dockerfile`. |
| 11 | `monto_wei` viaja en el JSON como **texto**. | No entra en un `Number` de JavaScript sin perder precisión: el front lo pasa a `BigInt`. |
| 12 | El tipo de cambio se toma del parámetro `pesos_por_eth` en el momento de pagar, y se guarda una foto en cada pago. | Lo pide la tabla de Valentino. Si cambia el parámetro, los pagos viejos conservan el suyo. |

## Seña y vencimiento de la reserva pendiente

| # | Decisión | Por qué |
|---|---|---|
| 13 | El plazo para pagar la seña es `minutos_bloqueo_reserva` (tabla `parametros`, **15** por defecto), y se guarda en `reservas.bloqueo_expira_en` en UTC. | Configurable sin tocar código. UTC para no depender de la zona horaria del servidor. |
| 14 | **Vencimiento sin cron:** una pendiente vencida deja de ocupar noches al instante (todas las consultas lo miran), se muestra como `expirada`, y en la base se anota `expirada` recién cuando alguien la toca (pagar o cancelar). | No hace falta un cron en Railway y nunca hay una ventana en la que una reserva vencida bloquee fechas. |
| 15 | Las pendientes creadas antes de la migración (`bloqueo_expira_en` en NULL) **no vencen**. | No liberamos de golpe fechas de reservas que ya existían. |
| 16 | Para que la vencida no ocupe, tocamos también `BloqueosController`, `AgendaOperadorController` y `HuespedesReservaController`, con cambios de una o dos líneas. | Si no, el propietario no podía bloquear esas fechas y el operador las veía en la agenda. Son archivos de otros roles: **avisar a sus dueños**. |
| 17 | Una unidad con **0 % de seña** crea la reserva directamente `confirmada`, sin plazo, y todo el total queda como saldo. | No hay nada que pagar para confirmarla. |
| 18 | Cancelar una pendiente es un `UPDATE` condicional: solo cambia si sigue pendiente y sin vencer. | Si se paga y se cancela al mismo tiempo, gana uno solo (409 "La reserva cambió mientras la cancelabas"). |

## Saldo

| # | Decisión | Por qué |
|---|---|---|
| 19 | Al reservar se guardan `monto_faltante` (total − seña) y `fecha_cobro`: `dias_cobro_saldo` días antes del ingreso (5 por defecto), o hoy si se reserva con menos anticipación. La migración completa estas dos columnas en las reservas que ya existían. | Lo pide el plan. Los usan el saldo, los recordatorios (Rol 4) y los tramos de la política. |
| 20 | Pagar el saldo deja `monto_faltante` en **0**; no agregamos una columna "saldo pagado". | El dato ya está: lo que falta es 0, y el pago queda en `pagos` con `tipo = 'saldo'`. |
| 21 | `POST /pagos/confirmar` acepta un `tipo` **opcional**. Si no viene: una pendiente paga la seña y una confirmada el saldo. | Respeta la firma del plan (`{reserva_id, tx_hash}`) y deja al front ser explícito. |
| 22 | El saldo se puede pagar **antes** de la `fecha_cobro`. | No hay motivo para impedirlo; el ETH queda en el contrato igual. |
| 23 | **A charlar:** pasada la `fecha_cobro`, el saldo **se puede seguir pagando**. Figura `vencido` y la reserva **sigue `confirmada`** (no hay un estado nuevo). Se considera vencido desde el día siguiente a la `fecha_cobro`, en días de Argentina. | Con cripto no hay "cobro automático con reintentos" (L2-8): el huésped tiene que firmar. Cancelar sola la reserva o registrar una deuda (L2-9) es una regla de negocio que no definimos. |
| 24 | El saldo no se paga antes de la seña, ni en una reserva cancelada, vencida o finalizada. | |
| 25 | **A charlar:** registrar el ingreso **no exige** el saldo pago. | `EstadiasController` no es del Rol 2. Si se decide exigirlo, es un cambio de una línea allá. |
| 26 | En las reservas viejas sin `monto_faltante`, `puede_pagar_saldo` es `false`. | No inventamos un saldo que no está guardado. |

## Avisos

| # | Decisión | Por qué |
|---|---|---|
| 27 | Mails de seña confirmada, seña fallida, saldo confirmado y saldo fallido (`mail/AvisoPagoMail.php`). **Si el mail falla, no falla el pago:** queda en el log. | El pago ya quedó registrado; la respuesta no puede fallar por el mail. |
| 28 | Los horarios en los mensajes van en hora de Argentina; en el JSON, en UTC (`…Z`). | El huésped lee el mensaje tal cual; el front convierte el UTC a su zona. |

## Cancelar con devolución

| # | Decisión | Por qué |
|---|---|---|
| 29 | Se cancela una reserva **pendiente** (sin vencer) o **confirmada**, solo **antes del día del ingreso**. Una estadía empezada o finalizada no se cancela desde la app. | Una vez que el huésped entró, el caso deja de ser una cancelación: es otra cosa, como irse antes o un problema en la unidad. |
| 30 | La retención es el % **de la seña** del tramo, según los días que faltan hasta la `fecha_cobro`: más de 30, de 15 a 30 (con los dos bordes incluidos) o menos de 15. Si la fecha de cobro ya pasó, cuenta como menos de 15. | Es la política tal como quedó con la corrección del profesor. |
| 31 | **Decidido:** si el saldo ya está pago, **se devuelve entero**. El tramo se sigue calculando por la fecha: el que pagó antes de tiempo no queda penalizado. | El huésped nunca pierde más que la seña. Si pagó en término, ya está a menos de 15 días del cobro. |
| 32 | Las cuentas se hacen sobre **los pagos confirmados, en wei**, y no sobre los montos de la reserva. La retención en pesos se redondea a centavos; en wei se trunca. | Así nunca se pide devolver más ETH del que entró al contrato para esa reserva, aunque cambie `pesos_por_eth`. |
| 33 | Se devuelve a la **billetera que pagó la seña**, aunque el saldo lo haya pagado otra. | El contrato solo deja devolverle a quien pagó la reserva, y tiene que ser una sola dirección. |
| 34 | **Decidido:** el firmador es intercambiable (`FirmadorPagos`, con `pagos.firmador` o `PAGOS_FIRMADOR`). Hoy está `FirmadorSimulado`: red 31337, da la transacción por confirmada con un hash al azar, y a una billetera que termina en `dead` no le puede mandar nada, para probar los fallos. | Igual que el verificador: la cancelación queda completa y probada sin el contrato. El firmador real, con `PLATAFORMA_CLAVE_PRIVADA` y una librería de Composer, se agrega sin tocar el controlador. |
| 35 | El orden es: se cancela la reserva y se anota la devolución `pendiente` (en una transacción de la base), después se manda la transacción a la red, y recién ahí se anota el `tx_hash`. | La red nunca deja una reserva a medio cancelar. Si falla, la reserva queda cancelada y la devolución pendiente. |
| 36 | Las devoluciones que fallaron las reintenta `scripts/reintentar-devoluciones.php`, a mano o con un cron. Toma solo las de más de 5 minutos y usa un candado de MySQL para no correr dos veces al mismo tiempo. | Para no mandar dos veces la misma devolución. Igual, el contrato no deja devolver más de lo que entró. |
| 37 | La devolución se guarda en `pagos` con `tipo = 'devolucion'`. La retención no se guarda aparte: es lo pagado menos lo devuelto. | No hace falta tocar la tabla de Valentino. Las liquidaciones (Rol 4) la pueden calcular. |
| 38 | Se le avisa por mail al propietario **antes** de mandar la devolución (L2-18), y al huésped después, con lo retenido y lo devuelto. | Lo pide L2-18. |
| 39 | **A charlar:** la devolución sale **en el momento**. La excepción manual del propietario (L2-19: cambiar el monto **antes** de que la devolución se efectivice) es de la fase 3. Para hacerla, habría que demorar la devolución, por ejemplo unas horas, para que el propietario pueda intervenir. | Hoy lo prioritario es el circuito del dinero. Demorar la devolución también cambia lo que ve el huésped. |
| 40 | `GET /reservas/cancelacion` muestra lo mismo que se va a devolver, sin cambiar nada (L2-3). | El huésped ve el monto antes de confirmar. |
| 41 | La reserva se traba con `FOR UPDATE` al cancelar. | Una cancelación y un pago del saldo al mismo tiempo no se pisan: una de las dos ve la reserva ya cambiada (409). |

## Relación con Jira (proyecto L2)

Estado al 06/11/2026. Cada historia tiene un comentario con el detalle de lo hecho y lo que falta. Ninguna está en "Listo": a todas les falta el front (Rol 3), y a las de pagos, la red real (Rol 1).

| Historia | Estado | Qué se hizo en el back | PR |
|---|---|---|---|
| L2-28 Reservar y pagar la seña | En curso | Reserva pendiente con plazo, pago de la seña, vencimiento. **Diferencias:** no se tokeniza tarjeta (es ETH), y si el pago falla la reserva no se cancela: se puede reintentar hasta que vence. | #23 |
| L2-30 Notificación de pago rechazado | En curso | 402 con el motivo y mail, para la seña y el saldo. | #23, #24 |
| L2-8 Cobro automático del saldo | En curso | Pago del saldo. **Diferencia:** con cripto no puede ser automático, porque firma el huésped. Los recordatorios son del Rol 4 (RF-25). | #24 |
| L2-18 Cancelar como huésped | En curso | Vista previa de la devolución, cancelación con retención por tramo, aviso al propietario. L2-3 parece un duplicado. | #24 |
| L2-9 Deuda por saldo no cobrado | Por hacer | Depende de la decisión #23. | — |
| L2-19 Excepción manual a la retención | Por hacer | Fase 3. Ver la decisión #39. | — |
| Épicas L2-6 y L2-12 | En curso | Contienen L2-8 y L2-18. | — |

## Pendiente del Rol 2

- `Blockchain.php`: el verificador real por RPC (`eth_getTransactionReceipt` y el evento `PagoRealizado`).
- El firmador real de `devolver`, con `PLATAFORMA_CLAVE_PRIVADA`. Sirve también para `liquidar` (Rol 4).
- Qué pasa con la reserva si el saldo vence sin pagarse (#23).
