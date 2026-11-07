# Decisiones del Rol 2: pagos y reservas

Las decisiones que tomamos al implementar la parte del Rol 2 del plan de la entrega del 80 %, y por qué. Las que dicen **a charlar** conviene confirmarlas con el equipo; las que dicen **decidido (06/11)** ya las definió el equipo y falta implementarlas donde se indica. El contrato de la API está en [`api/pagos.md`](api/pagos.md) y [`api/reservas.md`](api/reservas.md).

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
| 23 | **Decidido (06/11):** pasada la `fecha_cobro`, el huésped tiene **3 días** para pagar el saldo, o hasta el **día del ingreso** si eso llega antes (pasa cuando se reserva con menos de 3 días de anticipación); ese día lo puede pagar al llegar. Mientras tanto figura `vencido` y se puede pagar. Si vence ese plazo sin pagar, **la reserva se cancela sola** como una cancelación del huésped con el tramo de **menos de 15 días** (#30): se retiene ese % de la seña y se le devuelve el resto. **Hay que avisarlo al reservar**: en la pantalla de la reserva, en el mail de la seña confirmada y en los recordatorios del saldo. **Hecho (07/11):** `scripts/cancelar-saldos-vencidos.php` (cron diario) las cancela con `CancelacionConDevolucion`, la misma lógica que usa el huésped, y con el plazo de `CobroDelSaldo::limiteDePago` (el mismo que anuncian los mails). El mail de la seña confirmada avisa el plazo y la retención, y `GET /pagos/saldo` devuelve `limite_pago`. | Con cripto no hay "cobro automático con reintentos" (L2-8): el huésped tiene que firmar, así que sin una consecuencia el saldo podría no pagarse nunca. Una deuda (L2-9) no se puede cobrar con cripto. El plazo no pasa del ingreso porque el saldo es obligatorio para entrar (#25). |
| 24 | El saldo no se paga antes de la seña, ni en una reserva cancelada, vencida o finalizada. | |
| 25 | **Decidido (06/11):** **no se registra el ingreso sin el saldo pago** (`monto_faltante` = 0). Si falta, el back responde 409 y el huésped lo paga en el momento desde MetaMask. **Falta implementarlo** en `EstadiasController`. | El ingreso dispara la liquidación (`liquidar` del contrato): sin el saldo, en el contrato está solo la seña y el propietario cobraría de menos. |
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
| 39 | **Decidido (06/11):** para esta entrega la devolución sale **en el momento**. **Para la entrega final es obligatoria** la excepción manual del propietario (L2-19: cambiar el monto **antes** de que la devolución se efectivice). Para hacerla hay que demorar la devolución (por ejemplo, unas horas) para que el propietario pueda intervenir, porque el contrato deja devolver **una sola vez** por reserva. | Hoy lo prioritario es el circuito del dinero. Demorar la devolución también cambia lo que ve el huésped. |
| 40 | `GET /reservas/cancelacion` muestra lo mismo que se va a devolver, sin cambiar nada (L2-3). | El huésped ve el monto antes de confirmar. |
| 41 | La reserva se traba con `FOR UPDATE` al cancelar. | Una cancelación y un pago del saldo al mismo tiempo no se pisan: una de las dos ve la reserva ya cambiada (409). |

## Billeteras

| # | Decisión | Por qué |
|---|---|---|
| 42 | **Decidido (06/11):** propietarios y administradores tienen una **billetera de cobro** (columna `billetera`, migración `20261106120000`), **obligatoria** en el registro y en "hacerme propietario". Es la dirección pública (`0x` + 40 hexadecimales), se guarda en minúsculas y se carga o cambia desde el perfil (`POST /datos-fiscales/billetera`). | `liquidar` del contrato necesita a qué billetera mandarle su parte al propietario y su comisión al administrador. |
| 43 | El CBU, el alias y el titular **se mantienen**. | Sirven para cobrar en pesos; la billetera, para cobrar en ETH. |
| 44 | Los que se registraron antes tienen la billetera en `NULL` hasta que la carguen. **Mientras no la carguen, sus liquidaciones quedan pendientes**: el ETH sigue retenido en el contrato, y `liquidar` se puede hacer después. | No se les puede inventar una billetera. Mandar su parte a la plataforma mezclaría su dinero con el de la plataforma, que es lo que el escrow evita. |
| 45 | **El huésped no tiene columna de billetera.** Su billetera es la que pagó la seña (`pagos.billetera` y, en el contrato, `reservas(id).huesped`). | El contrato solo deja devolverle a esa billetera (#33), también cuando cancela el propietario. |

## Relación con Jira (proyecto L2)

Estado al 06/11/2026. Cada historia tiene un comentario con el detalle de lo hecho y lo que falta. Ninguna está en "Listo": a todas les falta el front (Rol 3), y a las de pagos, la red real (Rol 1).

| Historia | Estado | Qué se hizo en el back | PR |
|---|---|---|---|
| L2-28 Reservar y pagar la seña | En curso | Reserva pendiente con plazo, pago de la seña, vencimiento. **Diferencias:** no se tokeniza tarjeta (es ETH), y si el pago falla la reserva no se cancela: se puede reintentar hasta que vence. | #23 |
| L2-30 Notificación de pago rechazado | En curso | 402 con el motivo y mail, para la seña y el saldo. | #23, #24 |
| L2-8 Cobro automático del saldo | En curso | Pago del saldo. **Diferencia:** con cripto no puede ser automático, porque firma el huésped. Los recordatorios son del Rol 4 (RF-25). | #24 |
| L2-18 Cancelar como huésped | En curso | Vista previa de la devolución, cancelación con retención por tramo, aviso al propietario. L2-3 parece un duplicado. | #24 |
| L2-9 Deuda por saldo no cobrado | Por hacer | Se reemplaza por la cancelación automática de la decisión #23: con cripto una deuda no se puede cobrar. | — |
| L2-19 Excepción manual a la retención | Por hacer | **Obligatoria para la entrega final.** Ver la decisión #39. | — |
| Épicas L2-6 y L2-12 | En curso | Contienen L2-8 y L2-18. | — |

## Pendiente del Rol 2

- Para la entrega final: la demora de la devolución y la excepción del propietario (#39, L2-19).
- Rol 4: llamar a `Firmadores::configurado()->liquidar(...)` desde `LiquidacionesController` (el firmador ya lo tiene).
- Reservas con seña del 0 %: el contrato exige seña para `pagarSaldo` (lo detectó Justino). Hay que decidir con el Rol 1.

## Red real (07/11)

| # | Decisión | Por qué |
|---|---|---|
| 46 | `VerificadorBlockchain` (`PAGOS_VERIFICADOR=blockchain`) lee el recibo con `eth_getTransactionReceipt` y busca el log `PagoRealizado` **emitido por `CONTRATO_DIRECCION`**, de esa reserva (`topics[1]`) y de ese tipo. Sin recibo es `pendiente` (202), aunque el nodo todavía no conozca la transacción: MetaMask la manda por su propio RPC. | Lo pide el README del contrato: cualquiera puede desplegar otro contrato con un evento igual. |
| 47 | Se acepta un pago **igual o mayor** al pedido, y se guarda en `pagos.monto_wei` **lo que entró de verdad** (el monto del evento). Uno menor falla (402). | El contrato acepta una sola seña por reserva: rechazar un pago de más dejaría el ETH trabado. Las devoluciones se calculan sobre lo que entró (#32). |
| 48 | `FirmadorBlockchain` (`PAGOS_FIRMADOR=blockchain`) firma con `PLATAFORMA_CLAVE_PRIVADA` una transacción EIP-155 (`FirmaEthereum`, con `simplito/elliptic-php` y `kornrunner/keccak`; necesita la extensión **gmp**, agregada al `Dockerfile` y al CI). Antes controla que el RPC sea de la red 84532 y estima el gas: si el contrato la rechazaría, no se manda y la devolución queda pendiente. | La firma es la del ejemplo oficial de EIP-155 (test unitario). Estimar el gas evita pagar gas por una transacción que va a revertir. |
| 49 | Un candado de MySQL (`firmador_plataforma`) rodea el nonce y el envío. | Dos cancelaciones al mismo tiempo no mandan dos transacciones con el mismo nonce. |
| 50 | El firmador espera hasta ~10 s a que la transacción entre en un bloque; si no, queda `pendiente` con su `tx_hash` y la confirma `reintentar-devoluciones.php`. Si revirtió o la red no la conoce, le borra el `tx_hash` y la vuelve a mandar. | Base hace un bloque cada 2 s: casi siempre se confirma en el momento y el huésped ve "Te devolvimos". |
| 51 | La cancelación con devolución está en `CancelacionConDevolucion`, que usan el huésped (`POST /reservas/cancelar`) y el script del saldo vencido. | Las dos tienen que hacer exactamente lo mismo con la plata. |

### Variables de Railway para la red real

`PAGOS_VERIFICADOR=blockchain`, `PAGOS_FIRMADOR=blockchain`, `BLOCKCHAIN_RPC_URL`, `CONTRATO_DIRECCION` y `PLATAFORMA_CLAVE_PRIVADA` (`BLOCKCHAIN_RED` es opcional: 84532 por defecto). Con `simulado` (el valor por defecto) todo sigue funcionando sin red.

### Crons

| Script | Cada cuánto | Qué hace |
|---|---|---|
| `php scripts/cancelar-saldos-vencidos.php` | Una vez por día (por ejemplo, 03:00 UTC) | Cancela las reservas con el saldo vencido y manda la devolución (#23). |
| `php scripts/reintentar-devoluciones.php` | Cada 10 a 15 minutos | Confirma las devoluciones en camino y reintenta las que no salieron (#36, #50). |
| `php scripts/enviar-recordatorios.php` | Una vez por día (Rol 4) | Recordatorios del saldo. |
