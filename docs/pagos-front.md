# Qué tiene que adaptar el front por los pagos (Rol 3)

Una guía para el front, con lo que ya está en el back (`main` + la rama `feature/pago-saldo`). El detalle de cada endpoint está en [`api/pagos.md`](api/pagos.md) y [`api/reservas.md`](api/reservas.md). Las decisiones del back y sus motivos, en [`pagos-decisiones.md`](pagos-decisiones.md).

Regla general: **los mensajes de `error` y `ok` del back se muestran tal cual.** Ya están escritos para el huésped.

## 1. Lo que ya cambió y rompe o afecta lo que hay

### `POST /reservas/crear` (`detalle-alojamiento.js`)

- La reserva nace **`pendiente`**, con `reserva.vence_en` (UTC, `"2026-11-05T20:15:00Z"`): el huésped tiene **15 minutos** para pagar la seña. Pasado ese plazo, las fechas se liberan.
- El mensaje `ok` ahora es "Reservaste Casa del Bosque: pagá la seña antes de las 17:15 para confirmarla".
- Si la unidad no pide seña (0 %), la reserva viene **`confirmada`** y `vence_en` es `null`: no hay que pagar nada para confirmarla.
- **A cambiar:** hoy, después de reservar, se va a `mis-reservas.html?nueva=1`. Tiene que ir a **`../reservas/pagar.html?reserva_id={id}&tipo=sena`**, salvo que la reserva venga `confirmada`. Es el cambio de una línea en el detalle que menciona el plan.

### `GET /reservas/mias` (`mis-reservas.js`)

Cada reserva trae campos nuevos:

| Campo | Qué es | Qué hacer |
|---|---|---|
| `estado` | Ahora puede venir **`expirada`**: una pendiente que no pagó a tiempo. El back la muestra así aunque en la base siga pendiente. | Ya está en `ESTADOS_DE_RESERVA` ("Vencida sin pagar"). |
| `vence_en` | Hasta cuándo se paga la seña (UTC). Solo en las pendientes; si no, `null`. | Mostrar "Pagá la seña antes de las HH:MM" (en hora local), o una cuenta regresiva. |
| `puede_pagar` | `true` en las pendientes sin vencer. | Botón **"Pagar la seña"** → `pagar.html?reserva_id={id}&tipo=sena`. |
| `puede_cancelar` | **Cambió:** ahora es `true` en las pendientes sin vencer **y en las confirmadas antes del día del ingreso**. | Una confirmada tiene devolución: ver la sección 3. |
| `saldo` | Lo que falta pagar, como texto (`"168000.00"`). `"0.00"` si ya está pago. `null` en reservas viejas. | Mostrarlo como "Saldo", o "Saldo pagado" si es 0. |
| `fecha_cobro` | Hasta cuándo se paga el saldo (`AAAA-MM-DD`, día de Argentina). | Mostrar "Pagá el saldo antes del dd/mm/aaaa". Si ya pasó, "Saldo vencido: pagalo antes de ingresar". |
| `puede_pagar_saldo` | `true` en las confirmadas con saldo. | Botón **"Pagar el saldo"** → `pagar.html?reserva_id={id}&tipo=saldo`. |

**Ojo con el filtro de "próximas"** (`["pendiente", "confirmada"].includes(r.estado)`): una pendiente vencida ahora llega como `expirada`, así que ya queda afuera, que es lo correcto.

## 2. La pantalla nueva: `vistas/reservas/pagar.html` + `funcionalidades/pagar.js`

Recibe `reserva_id` y `tipo` (`sena` o `saldo`) en la URL.

### Paso 1: pedir los datos

```
GET /pagos/sena?reserva_id=12      (o /pagos/saldo)
```

```json
{
  "reserva_id": 12, "tipo": "sena",
  "monto_pesos": "42000.00", "monto_wei": "420000000000000", "pesos_por_eth": "100000000",
  "red": 31337,
  "vence_en": "2026-11-05T20:15:00Z",
  "pago": {"contrato": null, "funcion": "pagarSena", "simulado": true}
}
```

- Con el saldo, en vez de `vence_en` vienen `fecha_cobro` y `vencido` (`true` si ya pasó la fecha).
- Mostrar el monto en pesos y en ETH: ETH = `monto_wei` / 10^18. Hacer la cuenta con `BigInt`, **nunca con `Number(monto_wei)`**.
- **409** si no se puede pagar: ya está pagada, vencida, cancelada o "Primero tenés que pagar la seña". Mostrar el `error` y un enlace a "Mis reservas". **404** si la reserva no existe; **401** sin sesión.

### Paso 2: pagar

**Con el verificador simulado (`pago.simulado: true`, hoy):** no hay contrato, así que no se abre MetaMask. Para poder probar toda la pantalla, el front puede mostrar un botón "Pagar (simulado)" que invente un hash de 64 hexadecimales al azar. Según cómo termine, el back hace distinto:

| El hash termina en | Resultado |
|---|---|
| cualquier otra cosa | confirmado (200) |
| `dead` | falla (402) |
| `0000` | sigue pendiente (202) |

Por ejemplo, `"0x" + 63 hex al azar + "1"` sale confirmado. Con `simulado: true` **no hay que pedirle a MetaMask que cambie de red**: el 31337 es una red de mentira.

**Con el verificador real (después, `simulado: false`):** `pago.contrato` va a traer la dirección del contrato, y `red` va a ser **84532** (Base Sepolia). Con viem:

1. Cambiar a la red `red` si la billetera está en otra.
2. `writeContract({ address: pago.contrato, abi, functionName: pago.funcion, args: [BigInt(reserva_id)], value: BigInt(monto_wei) })`. El ABI lo publica el Rol 1.
3. Con el hash que devuelve, seguir al paso 3. No hace falta esperar la confirmación en el front: el back la verifica.

Errores a mostrar en este paso, del lado del front: el huésped rechazó la firma, no tiene ETH de prueba suficiente, o está en otra red.

### Paso 3: avisarle al back

```
POST /pagos/confirmar
{"reserva_id": 12, "tx_hash": "0x…", "tipo": "sena"}
```

Mandar siempre el `tipo` de la URL: así, si la reserva cambió, el back lo rechaza en vez de cobrar otra cosa.

| Código | Qué hacer |
|---|---|
| `200` | Listo. Mostrar `ok` ("Pagaste la seña: tu reserva en … está confirmada") e ir a "Mis reservas". Al huésped le llega un mail. |
| `202` `{"pendiente": true, "mensaje": "…"}` | La transacción todavía no está en un bloque. Mostrar el `mensaje` y **volver a mandar el mismo pedido, con el mismo hash**, cada 3 o 4 segundos. Poner un tope de, por ejemplo, 2 minutos, y después ofrecer un botón "Volver a verificar". **No pagar de nuevo**: sería pagar dos veces. |
| `402` `{"error", "motivo", "vence_en" o "fecha_cobro"}` | El pago falló. Mostrar `error` (ya dice hasta cuándo puede reintentar) y un botón **"Pagar de nuevo"**, que vuelve al paso 1 con una transacción nueva. Al huésped le llega un mail con el motivo. |
| `409` | Mostrar `error`: la transacción ya se usó o ya falló, la reserva ya está pagada, se venció o está cancelada. Si se venció la seña, ofrecer volver al alojamiento para reservar otra vez. |
| `422` | Hash mal formado o `tipo` inválido: es un error del front. |

**Doble clic:** deshabilitar el botón mientras se paga y se confirma. Si el huésped recarga la página después de firmar, se pierde el hash. Conviene guardarlo en `sessionStorage` (por reserva) hasta que el back responda 200 o 402.

## 3. Cancelar con devolución (`mis-reservas.js`)

Hoy "Cancelar" manda `POST /reservas/cancelar` directamente. Con una reserva **confirmada**, antes de cancelar hay que mostrarle al huésped cuánto le devuelven (L2-3):

1. Al tocar "Cancelar", pedir **`GET /reservas/cancelacion?reserva_id={id}`**:

   ```json
   {"reserva_id": 12, "estado": "confirmada", "tramo": "mas_de_30", "dias_hasta_cobro": 45,
    "porcentaje_retencion": "30.00", "pagado": {"sena": "42000.00", "saldo": "0.00"},
    "retencion": "12600.00", "devolucion": "29400.00", "devolucion_wei": "294000000000000"}
   ```

2. Mostrar un diálogo con la cuenta. Por ejemplo: "Pagaste $42.000 de seña. Como faltan 45 días para el cobro del saldo, se retiene el 30 % de la seña ($12.600) y te devolvemos $29.400 a la billetera con la que pagaste." Si pagó el saldo, aclarar que el saldo se le devuelve entero. Los tramos: `mas_de_30` (más de 30 días), `de_15_a_30` y `menos_de_15`. En una pendiente todo viene en 0: alcanza con el diálogo de siempre.
3. Si confirma, `POST /reservas/cancelar` y mostrar el `ok` tal cual:

   | `devolucion.estado` | `ok` |
   |---|---|
   | `"confirmado"` | "… Te devolvimos $29.400,00 a tu billetera". Trae `tx_hash`, para mostrar un enlace al explorador cuando sea la red real. |
   | `"pendiente"` | "… La devolución de $29.400,00 está en proceso: te avisamos por mail" (la red falló: se reintenta sola). |
   | `null` | "… no corresponde devolución" (no había nada pagado). |

   Una pendiente sigue respondiendo solo `{"ok": "Cancelaste la reserva: esas noches quedaron libres"}`, sin `devolucion`.
4. Errores (409): "La estadía ya empezó: no se puede cancelar desde la app", "Esa estadía ya terminó", "Esa reserva ya estaba cancelada", "La reserva cambió mientras la cancelabas: actualizá la página".

`devolucion_wei` es texto, como `monto_wei`: con `BigInt`.

## 4. Tests de Node sugeridos (como los de Justino)

- Pesos y wei: `"420000000000000"` wei se muestra como 0,00042 ETH; con `BigInt`, sin perder decimales.
- Qué botón mostrar en "Mis reservas" según `puede_pagar`, `puede_pagar_saldo` y `estado`.
- El texto del plazo: a partir de `vence_en` (UTC a hora local) y de `fecha_cobro` (vigente o vencido).
- Qué hacer con cada código de `/pagos/confirmar` (200, 202 reintenta, 402 permite pagar de nuevo, 409).
- El texto del diálogo de cancelación según el `tramo` y si hay saldo pagado.

## 5. Lo que viene y va a cambiar

- **Verificador real (`Blockchain.php`):** cambia `pago` (con `contrato` y `simulado: false`) y `red` (84532). El resto de las respuestas queda igual.
- **Firmador real:** la devolución va a salir a Base Sepolia y puede quedar `"pendiente"` unos segundos más seguido. El front no tiene que hacer nada distinto.
