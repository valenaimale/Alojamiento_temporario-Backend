# API: liquidaciones y comisiones (RF-09, RF-13, RF-19)

El reparto del dinero de cada estadía: lo que le queda al propietario, la comisión de la plataforma y la del administrador, con el **comprobante sin valor fiscal** de cada uno (RN-25). Es lo que le toca al Rol 4 en el plan de la entrega del 80 %. Las convenciones generales están en el `Readme.md`.

## La cuenta

Todo está en `validacion/Liquidacion.php`, en un solo método que no toca la base (`Liquidacion::calcular`), así que se puede probar sin servidor: `tests/Unitarios/LiquidacionTest.php`.

La regla es **RN-24**, que vino del contador: si el propietario **discrimina IVA**, las comisiones se calculan sobre el **neto sin IVA** (el 21 % viene incluido en el precio que pagó el huésped); si **no** lo discrimina, se calculan sobre el **total**. El IVA se lo queda el propietario, que es quien lo tiene que pagar.

Con $425.000 cobrados, el 7,5 % de la plataforma y el 10 % del administrador:

| Concepto | Discrimina IVA | No discrimina |
|---|---|---|
| Importe cobrado al huésped | $425.000,00 | $425.000,00 |
| IVA incluido (21 %) | $73.760,33 | — |
| Base de comisiones | $351.239,67 | $425.000,00 |
| Comisión de la plataforma (7,5 %) | −$26.342,98 | −$31.875,00 |
| Comisión del administrador (10 %) | −$35.123,97 | −$42.500,00 |
| **Importe para el propietario** | **$363.533,05** | **$350.625,00** |

Los importes se calculan **en centavos enteros**, nunca en coma flotante, porque tienen que cerrar al centavo (RNF-22). Los porcentajes se tratan igual, en centésimas de punto: el 7,5 % es 750. Los dos invariantes los garantiza además la base con un `CHECK`:

- `base_comisiones + importe_iva = importe_cobrado`
- `importe_propietario = importe_cobrado - comision_plataforma - comision_administrador`

El IVA y la comisión de la plataforma salen de `parametros` (`iva` = 21, `comision_plataforma` = 7.5); el porcentaje del administrador, de `administraciones`. Los tres se **copian** a la liquidación: si Cacho renegocia su comisión el mes que viene, o si cambia la tasa de IVA, las liquidaciones ya hechas no se mueven, igual que las reservas copian el precio y la política. `porcentaje_iva` queda en `0` si el propietario no discrimina IVA: no hay tasa que mostrar en el comprobante.

## Cuándo se liquida

Cuando el **huésped ingresa**, no cuando se va: es el momento en que el contrato libera la plata que tenía retenida (plan de la entrega del 80 %, paso 0, punto 3). En la práctica: toda reserva `confirmada` o `finalizada` que tenga su fila en `estadias`.

El importe cobrado es la suma de los pagos **confirmados** de tipo `sena` y `saldo` de esa reserva. Si es menor que el total de la reserva, la liquidación se calcula igual pero queda en **`retenida_por_deuda`** y la respuesta trae `faltante` con lo que falta cobrar: es **RN-16**, *"no se liquida lo que todavía no se cobró"*.

| Estado | Qué significa |
|---|---|
| `pendiente` | Calculada, lista para transferirle al propietario |
| `retenida_por_deuda` | Falta cobrar parte de la estadía (RN-16). No se puede pagar |
| `pagada` | Ya se le transfirió. Tiene `liquidada_en` |

## Las liquidaciones del propietario (RF-13)

```
GET /liquidaciones/mias
```

Solo el propietario, y solo las de sus unidades. De la más nueva a la más vieja.

```json
{"liquidaciones": [{
  "id": 3, "estado": "pendiente", "creado_en": "2026-11-05T18:20:00Z", "liquidada_en": null,
  "reserva": {"id": 5, "ingreso": "2026-10-27", "egreso": "2026-11-01", "noches": 5, "personas": 2,
              "importe_total": "425000.00", "huesped": "Lucía Sosa",
              "unidad": {"id": 4, "nombre": "Loft del Centro", "localidad": "Villa Gesell", "provincia": "Buenos Aires"}},
  "cobra_iva": true,
  "importe_cobrado": "425000.00", "porcentaje_iva": "21.00", "importe_iva": "73760.33", "base_comisiones": "351239.67",
  "porcentaje_comision_plataforma": "7.50", "comision_plataforma": "26342.98",
  "administrador": {"nombre": "Cacho Fernández", "cuit": "20301234563", "cobra_iva": true, "porcentaje": "10.00"},
  "comision_administrador": "35123.97",
  "importe_propietario": "363533.05",
  "faltante": null
}]}
```

`administrador` es `null` si la unidad es de autogestión, y entonces `comision_administrador` es `"0.00"`. `faltante` solo trae un importe cuando el estado es `retenida_por_deuda`.

| Código | Cuándo |
|---|---|
| `200` | Siempre que sea propietario; sin liquidaciones devuelve la lista vacía |
| `401` / `403` | Sin sesión / no es propietario |

## Las comisiones del administrador (RF-09)

```
GET /administrador/comisiones
```

Solo el administrador, y solo las de las unidades que administra. **No** trae los datos fiscales ni el importe final del propietario: factura su comisión aparte y no necesita el resto.

```json
{"comisiones": [{
  "id": 3, "estado": "pendiente", "creado_en": "2026-11-05T18:20:00Z", "liquidada_en": null,
  "reserva": {"id": 5, "ingreso": "2026-10-27", "egreso": "2026-11-01", "noches": 5, "...": "..."},
  "propietario": "Elsa Quiroga",
  "base_comisiones": "351239.67", "porcentaje": "10.00", "comision": "35123.97",
  "faltante": null
}]}
```

## El comprobante sin valor fiscal (RF-13, RF-19, RN-25)

```
GET /liquidaciones/comprobante?id=3
```

Lo pueden pedir el propietario de la unidad, el administrador de esa liquidación y el backoffice. Según quién lo pida devuelve uno u otro, y lo dice en `para`:

| `para` | Quién lo pide | Qué trae |
|---|---|---|
| `propietario` | El propietario (o el backoffice) | El reparto completo: su condición fiscal y su CUIT o DNI, el importe cobrado, el IVA, la base, las dos comisiones (y el nombre y CUIT del administrador, porque su comisión sale de esta liquidación) y el importe final |
| `administrador` | El administrador | Solo su comisión, con **su** condición fiscal y su CUIT, para facturarla aparte (RF-19). Del propietario, nada más que el nombre |

Los dos traen `"sin_valor_fiscal": true`: el front tiene que mostrarlo al pie.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"comprobante": {...}}` | |
| `404` | `{"error": "No encontramos ese comprobante"}` | No existe, no es suya o el id no es válido. La liquidación de otro "no existe", así nadie averigua importes ajenos probando números |
| `401` / `403` | | Sin sesión / es huésped u operador |

## Generar y pagar (la plataforma)

Liquidar es tarea de la plataforma, así que estas dos rutas son **solo del backoffice**: el propietario no genera sus propias liquidaciones.

```
POST /liquidaciones/generar
```

Calcula y guarda las liquidaciones de las estadías que todavía no se liquidaron. Es **idempotente**: una estadía se liquida una sola vez, lo garantiza el índice único de `reserva_id`, así que pedirla dos veces no duplica nada. Responde `{"ok": "Se liquidaron 3 estadías", "generadas": 3}`.

Lo mismo se puede correr por consola, para dejarlo en un cron:

```bash
php scripts/generar-liquidaciones.php             # muestra qué liquidaría, sin guardar nada
php scripts/generar-liquidaciones.php --guardar   # las calcula y las guarda
```

```
POST /liquidaciones/pagar
{"liquidacion_id": 3}
```

Registra que ya se le transfirió al propietario y al administrador: deja la liquidación en `pagada` con su `liquidada_en`.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Liquidación pagada"}` | |
| `409` | `{"error": "Esa liquidación ya estaba pagada"}` | Dos pedidos juntos: solo el primero la paga |
| `409` | `{"error": "Esa liquidación está retenida: todavía no se cobró toda la estadía"}` | RN-16 |
| `404` | `{"error": "No encontramos esa liquidación"}` | |

> **Pendiente:** hoy `pagar` solo registra el cambio de estado. Cuando esté la billetera de la plataforma y la firma de transacciones (Rol 2), acá va la llamada a `liquidar(reservaId, destinatarios[], montos[])` del contrato y los pagos de tipo `liquidacion` en la tabla `pagos`, uno por destinatario, con el mismo `tx_hash`. Para eso hace falta además guardar la billetera del propietario y del administrador, que todavía no tienen columna.

## Tablas

- **`liquidaciones`**: `20261105160000_crear_tabla_liquidaciones.php`, con la forma del DER v2. Una fila por reserva (índice único), con la copia de la condición fiscal y de los dos porcentajes, y los cuatro `CHECK` que no dejan guardar una liquidación que no cierre.
- Usa `reservas`, `estadias`, `pagos`, `unidades`, `administraciones`, `propietarios`, `administradores` y `parametros`, sin modificar ninguna.
