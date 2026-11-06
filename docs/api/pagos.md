# API: pago de la seña y del saldo (L2-28, L2-30, L2-6, RF-26)

El huésped paga la seña de una reserva **pendiente** desde su billetera, con `pagarSena(reservaId)` del contrato, y después el saldo de la reserva **confirmada**, con `pagarSaldo(reservaId)`: el ETH queda retenido en el contrato (*escrow*). Después de cada pago el front le pasa al back el hash de la transacción, y el back la verifica y la guarda en `pagos`. La seña confirma la reserva; el saldo la deja sin nada que pagar (`monto_faltante` = 0). Es lo que le toca al Rol 2 en el plan de la entrega del 80 %. Las convenciones generales están en el `Readme.md`.

## Montos: pesos y wei

Los precios están en pesos y se cobran en ETH de prueba con un tipo de cambio fijo: el parámetro `pesos_por_eth`, que vale **1 ETH = $ 100.000.000** (`validacion/TipoDeCambio.php`). 1 ETH son 10^18 wei, así que una seña de $ 42.000 son 0,00042 ETH = `420000000000000` wei. Las cuentas se hacen con **bcmath** (por eso está en el `Dockerfile`). `monto_wei` viaja como **texto**, porque no entra en un número de JavaScript sin perder precisión.

## El verificador (hoy simulado)

`PagosController` no sabe nada de la red: le pregunta a un `VerificadorPagos` (`validacion/VerificadorPagos.php`) si la transacción pagó lo que tenía que pagar. La configuración `pagos.verificador` (variable `PAGOS_VERIFICADOR`) elige cuál usar:

| Valor | Qué hace |
|---|---|
| `simulado` (por defecto) | `VerificadorSimulado` no consulta ninguna red. Guarda los pagos con `red` = **31337** (el chain id de Anvil, la red local de Foundry), para que nunca se confundan con uno real de Base Sepolia (84532). Siempre paga la billetera de prueba `0x…beef`. Acepta cualquier hash con formato válido y decide por cómo termina: **`dead`** → falla ("La transacción falló en la red"); **`0000`** → todavía pendiente; **cualquier otro** → confirmado. Ejemplo confirmado: `0x` + 63 ceros + `1`. |
| *(pendiente)* `blockchain` | `Blockchain.php`: `eth_getTransactionReceipt` por RPC y el evento `PagoRealizado(reservaId, pagador, monto, tipo)` en Base Sepolia. Implementa la misma interfaz y se agrega en `validacion/Verificadores.php`. El controlador y la base no cambian. |

## Plazo para pagar

Al reservar se anota `reservas.bloqueo_expira_en` = ahora + `minutos_bloqueo_reserva` (tabla `parametros`, 15 por defecto). Hasta ese momento la reserva pendiente ocupa sus noches. Después **deja de ocuparlas**: la búsqueda, el detalle, el calendario, los bloqueos y la agenda ya no la cuentan, y la API la muestra como `expirada`. En la base se anota `expirada` recién cuando alguien la toca (pagar o cancelar), así que no hace falta un cron. Las pendientes creadas antes de la migración tienen `bloqueo_expira_en` en `NULL` y no vencen.

Al reservar también se anotan el saldo (`monto_faltante` = total − seña) y su `fecha_cobro`: `dias_cobro_saldo` días antes del ingreso, o el día de la reserva si se reservó con menos anticipación. Los usa el cobro del saldo (L2-6). Una unidad sin seña (0 %) confirma la reserva al momento, y todo el total queda como saldo.

El saldo se paga con la reserva confirmada, desde que se confirma hasta la `fecha_cobro`. Pasada esa fecha **se puede seguir pagando**, pero figura `vencido` (RF-26). No se paga antes de la seña, ni en una reserva cancelada o finalizada.

## Datos para pagar

```
GET /pagos/sena?reserva_id=12
```

Solo el huésped dueño de la reserva.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"reserva_id": 12, "tipo": "sena", "monto_pesos": "42000.00", "monto_wei": "420000000000000", "pesos_por_eth": "100000000", "red": 31337, "vence_en": "2026-11-05T20:15:00Z", "pago": {"contrato": null, "funcion": "pagarSena", "simulado": true}}` | `red` es el chain id. `pago` trae lo que el front necesita para armar la transacción; con el verificador real va a traer la dirección del contrato. |
| `404` | `{"error": "No encontramos esa reserva"}` | No existe, es de otro huésped o el id no es válido. |
| `409` | `{"error": "Se venció el plazo para pagar la seña y las fechas se liberaron. Si siguen libres, podés volver a reservar"}`, `{"error": "La seña de esa reserva ya está pagada"}` o `{"error": "Esa reserva está cancelada"}` | |
| `401` / `403` | Sin sesión / no es huésped ni propietario. | |

```
GET /pagos/saldo?reserva_id=12
```

Igual que el de la seña, con la fecha de cobro en vez del vencimiento:

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"reserva_id": 12, "tipo": "saldo", "monto_pesos": "168000.00", "monto_wei": "1680000000000000", "pesos_por_eth": "100000000", "red": 31337, "fecha_cobro": "2026-11-30", "vencido": false, "pago": {"contrato": null, "funcion": "pagarSaldo", "simulado": true}}` | `vencido`: ya pasó la `fecha_cobro`. |
| `409` | `{"error": "Primero tenés que pagar la seña"}`, `{"error": "El saldo de esa reserva ya está pagado"}`, `{"error": "Esa estadía ya terminó"}`, `{"error": "Esa reserva está cancelada"}` o el de la vencida | |
| `404` / `401` / `403` | Como en la seña. | |

## Confirmar el pago

```
POST /pagos/confirmar
{"reserva_id": 12, "tx_hash": "0x…(64 hexadecimales)", "tipo": "saldo"}
```

`tipo` (`sena` o `saldo`) es opcional: si no viene, una reserva pendiente paga la seña y una confirmada el saldo. Las respuestas `200` y `402` traen el `tipo` que se pagó.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Pagaste la seña: tu reserva en Casa del Bosque está confirmada", "tipo": "sena", "reserva": {"id": 12, "estado": "confirmada"}, "pago": {"monto_pesos": "42000.00", "monto_wei": "420000000000000", "tx_hash": "0x…", "red": 31337}}` | Se guardó el pago `confirmado` y la reserva pasó a `confirmada`. Le llega un mail al huésped. Con el saldo: `"ok": "Pagaste el saldo: tu reserva en Casa del Bosque está paga"` y `monto_faltante` queda en 0. |
| `202` | `{"pendiente": true, "mensaje": "La transacción todavía no se confirmó en la red. Esperá unos segundos y volvé a intentar"}` | La transacción todavía no está en un bloque. No se guarda nada: el front vuelve a llamar con el mismo hash. |
| `402` | `{"error": "No pudimos confirmar el pago de la seña: La transacción falló en la red. Podés pagar de nuevo hasta las 17:15", "motivo": "La transacción falló en la red", "vence_en": "2026-11-05T20:15:00Z"}` | **L2-30:** la transacción falló. El intento se guarda como `fallido` (si se sabe qué billetera la mandó) y al huésped le llega un mail con el motivo. El motivo **no se guarda**, porque `pagos` no tiene esa columna. La reserva sigue pendiente: puede pagar con otra transacción hasta `vence_en`. |
| `402` | `{"error": "No pudimos confirmar el pago del saldo: La transacción falló en la red. Tenés tiempo hasta el 30/11/2026.", "tipo": "saldo", "motivo": "…", "fecha_cobro": "2026-11-30"}` | **RF-26:** lo mismo con el saldo. Si ya pasó la fecha de cobro: "El plazo venció el 30/11/2026: pagalo antes de ingresar." La reserva sigue confirmada con su saldo. |
| `422` | `{"error": "El tipo de pago tiene que ser sena o saldo"}` | |
| `409` | `{"error": "Esa transacción ya se usó para otro pago"}` o `{"error": "Esa transacción ya falló: tenés que pagar de nuevo"}` | El mismo hash no paga dos cosas, sin importar mayúsculas o minúsculas. La base lo asegura por billetera (`uq_pagos_tx_billetera`) y el controlador para cualquier billetera. |
| `409` | Los mismos de `GET /pagos/sena` (vencida, ya pagada, cancelada) | Si se venció, la reserva queda anotada como `expirada`. |
| `404` | `{"error": "No encontramos esa reserva"}` | |
| `422` | `{"error": "El hash de la transacción no es válido"}` | No es `0x` seguido de 64 hexadecimales. |

Dos confirmaciones al mismo tiempo no pagan dos veces: la reserva se traba con `FOR UPDATE`, así que la segunda espera y la encuentra ya confirmada (o con el saldo ya pagado).

## Tablas

- **`pagos`**: la creó Valentino (`20261105120000_crear_tabla_pagos.php`), con el parámetro `pesos_por_eth`. Este módulo la usa tal cual: `monto_pesos`, `monto_wei`, `pesos_por_eth`, `billetera`, `red` (chain id), `tx_hash`, `estado` y `confirmado_en`.
- **`reservas`**: `20261105140000_agregar_cobro_a_reservas.php` le agrega `bloqueo_expira_en`, `monto_faltante` y `fecha_cobro`, y completa las dos últimas en las reservas que ya existían.
