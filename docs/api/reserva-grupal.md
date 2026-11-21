# API: reserva grupal (US4.4 y US8.5)

El huésped reserva varias unidades de una [unidad grupal](unidades-grupales.md) en una sola vez. Paga la seña de todas juntas y después el saldo de todas juntas, y puede cancelar cada unidad por separado. Las decisiones son las #76 a #80 de [`docs/pagos-decisiones.md`](../pagos-decisiones.md).

**La idea:** cada unidad es una **reserva común**, con su propietario, su seña, su política de cancelación y su liquidación. Lo único que cambia es que el grupo las junta (`reservas.grupo_id`) para:

- **pagarlas en una transacción** del contrato: `pagarSenaGrupal` y `pagarSaldoGrupal`, que anotan cada reserva por separado;
- **mostrarlas juntas** en Mis reservas.

| Qué | Cómo |
|---|---|
| Quién paga | **Un solo huésped**, y es a quien se le devuelve si cancela una unidad |
| Plazo de la seña | **10 minutos**, como una reserva común (#74). Si vence, vencen todas |
| Saldo | **Junto**, con las unidades que siguen vigentes. Vence en la fecha de cobro **más temprana** del grupo, y si no se paga se **cancelan todas** |
| Cancelar | **Cada unidad por separado**, con su política, con `POST /reservas/cancelar` como cualquier reserva |
| Ingreso, liquidación, extensión y cambio de fechas | **Por unidad**, igual que una reserva común |
| Recordatorios del saldo | **Uno por grupo**, con el total |

## Reservar

```
POST /reservas/grupal/crear
{"grupal_id": 3, "ingreso": "2027-01-02", "egreso": "2027-01-09", "personas": 10,
 "unidad_ids": [4, 5, 6], "acepta_politica": true}
```

Solo un huésped con el mail verificado y el DNI cargado, como en una reserva común. Las `unidad_ids` son las que eligió de las que ofrece `GET /grupal` para esas fechas y personas. Tienen que cumplir:

- **al menos 2** unidades (con una sola, se reserva desde la búsqueda);
- **lugar para todas las personas**;
- **al menos una persona por unidad**.

Las personas se reparten así: una en cada unidad, y el resto llenando de la primera a la última.

Crea el grupo y una reserva **pendiente** por unidad, todas con el mismo plazo para pagar la seña. Las unidades se traban en orden de id mientras se crean: si otro huésped reserva una de ellas al mismo tiempo, el segundo espera y ve las noches ocupadas.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | Ver abajo | |
| `404` | `No encontramos esa reserva grupal` | `grupal_id` inválido |
| `409` | `Esta reserva grupal ya no está disponible para esas fechas: alguna unidad se reservó mientras elegías` | |
| `409` | `Alguna de las unidades que elegiste ya no está disponible en esas fechas` | Eligió una que la grupal no ofrece |
| `422` | `Elegí al menos 2 unidades…`, `Las unidades que elegiste tienen lugar para 8 personas: sumá otra`, `Elegiste más unidades que personas`, `No podés reservar tu propia unidad`, `Para reservar tenés que aceptar la política de cancelación de cada unidad` y los de las fechas y las personas | |

```json
{
  "ok": "Reservaste 3 unidades: pagá la seña antes de las 15:42 para confirmarlas",
  "grupo": {
    "id": 7, "ingreso": "2027-01-02", "egreso": "2027-01-09", "personas": 10,
    "importe_total": "1470000.00", "importe_sena": "294000.00", "vence_en": "2026-11-20T18:42:00Z",
    "reservas": [
      {"id": 41, "unidad_id": 4, "nombre": "Cabaña 4", "personas": 4, "precio_total": "490000.00", "sena": "98000.00"}
    ]
  }
}
```

## Pagar la seña y el saldo

```
GET /pagos/grupo/sena?grupo_id=7
GET /pagos/grupo/saldo?grupo_id=7
```

Responden lo que necesita el front para llamar al contrato:

```json
{
  "grupo_id": 7, "tipo": "sena", "monto_pesos": "294000.00", "monto_wei": "2940000000000000",
  "pesos_por_eth": "100000000", "red": 84532, "vence_en": "2026-11-20T18:42:00Z",
  "reservas": [
    {"reserva_id": 41, "unidad": "Cabaña 4", "monto_pesos": "98000.00", "monto_wei": "980000000000000"}
  ],
  "pago": {"contrato": "0x…", "funcion": "pagarSenaGrupal", "simulado": false}
}
```

El front llama a `pago.funcion` con los `reserva_id` y los `monto_wei` de `reservas`, **en ese orden**, mandando `monto_wei` en total. El total es la suma de los montos de cada reserva, que es lo que exige el contrato.

En el saldo no viene `vence_en`; vienen `fecha_cobro` (la más temprana del grupo) y `limite_pago` (el último día para pagarlo: la misma `fecha_cobro`, sin días de gracia, decisión #75; pasado ese día responde 409). Una unidad cancelada no se incluye. Si el grupo se reservó con 5 días o menos, la seña de cada unidad ya es su total y no hay saldo (#75).

| Error | Cuándo |
|---|---|
| `404` `No encontramos esa reserva grupal` | No existe o es de otro huésped |
| `409` `Se venció el plazo para pagar la seña…` | |
| `409` `La seña de esa reserva grupal ya está pagada` / `El saldo de esa reserva grupal ya está pagado` | |
| `409` `Primero tenés que pagar la seña` | Pidió el saldo antes de la seña |

```
POST /pagos/grupo/confirmar
{"grupo_id": 7, "tx_hash": "0x…", "tipo": "sena"}
```

Verifica la transacción **reserva por reserva**, con el mismo verificador de un pago común: el contrato emite un `PagoRealizado` por reserva. Como el contrato es todo o nada, o se confirman todas o ninguna.

| Código | Cuándo |
|---|---|
| `200` | Confirmado: se guarda un pago por reserva, todos con el mismo `tx_hash`. La seña deja las reservas **confirmadas**; el saldo las deja sin nada que pagar. Le llega un solo mail al huésped |
| `202` | Todavía no está en un bloque: el front vuelve a preguntar |
| `402` | Falló: se guarda el intento de cada reserva y se le avisa al huésped. Puede volver a pagar mientras no venza el plazo |
| `409` | `Esa transacción ya se usó para otro pago` |

**Una unidad del grupo no se paga sola:** `GET /pagos/sena`, `GET /pagos/saldo` y `POST /pagos/confirmar` responden `409` con `Esta reserva es parte de una reserva grupal: se paga junto con las demás unidades`.

## Cómo se ve

- **`GET /reservas/mias`**: cada reserva trae `grupo`, que es `null` en una reserva común o `{"id": 7, "titulo": "Cabañas 4, 5 y 6"}`. El front junta por `grupo.id` las reservas del mismo grupo.
- **`GET /unidades/reservas`**: cada reserva trae `grupal` (`true`/`false`). El **administrador** recibe además `grupo_unidades`, con los nombres de las otras unidades del grupo. El **propietario** recibe `null`, porque esas unidades pueden ser de otros dueños.

## Tareas programadas

- **`cancelar-saldos-vencidos.php`**: en un grupo, el plazo del saldo se cuenta desde la fecha de cobro más temprana de las unidades que todavía deben (`ReservaGrupal::limiteDelSaldo`). Los plazos de todas se calculan antes de cancelar ninguna, así se cancelan **todas juntas**.
- **`enviar-recordatorios.php`**: las unidades de un grupo salen en **un** recordatorio con el total (`CobroDelSaldo::juntarGrupos`), anotado en todas.

## Tablas

`20261120130000_crear_grupos_reserva.php`:

- **`grupos_reserva`**: `huesped_id`, `grupal_id` (queda en `NULL` si el administrador borra la unidad grupal) y `creado_en`.
- **`reservas.grupo_id`**.
- **`pagos`**: el índice único pasa de `(tx_hash, billetera)` a `(tx_hash, billetera, reserva_id)`. Un pago grupal es una transacción con un pago por reserva, todos desde la misma billetera.

La lógica está en `validacion/ReservaGrupal.php`, y lo que se copia de la unidad en `validacion/NuevaReserva.php`, que comparte con la reserva común. Los tests están en `tests/Integracion/ReservaGrupalTest.php`.
