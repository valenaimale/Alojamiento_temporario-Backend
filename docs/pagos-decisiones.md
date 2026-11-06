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

## Pendiente del Rol 2

- `Blockchain.php`: el verificador real por RPC (`eth_getTransactionReceipt` y el evento `PagoRealizado`).
- Cancelar una reserva confirmada con devolución: la retención según el tramo y `devolver` firmado por el back con `PLATAFORMA_CLAVE_PRIVADA`.
