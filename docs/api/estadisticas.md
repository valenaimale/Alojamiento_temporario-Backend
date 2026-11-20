# API: estadísticas de una unidad (RF-12)

Qué tan bien le fue a una unidad en una temporada: **ocupación**, **tarifa promedio**, **estadía promedio**, **cancelaciones por tramo** y **huéspedes recurrentes**. Son datos **calculados** sobre las reservas: no hay tabla que los guarde (decisión 10 de `modelo-de-datos.md`). Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

La ve el **propietario** de la unidad y el **administrador asignado**, que es quien la gestiona. Necesita sesión iniciada.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión, o expiró. |
| `404` | `{"error": "No encontramos esa unidad"}` | La unidad no existe, o no es suya. |
| `404` | `{"error": "No encontramos esa temporada"}` | La temporada no existe, o es de otra unidad. |
| `422` | `{"error": "Unidad inválida"}` | Falta `id`, o no es un entero positivo. |

## Ver las estadísticas

```
GET /unidades/estadisticas?id=5&temporada_id=3
```

`temporada_id` es **opcional**: sin él se devuelve la temporada **en curso**; si ninguna lo está, la última que ya empezó, y si todas son futuras, la más próxima.

```json
{
  "unidad": {"id": 5, "nombre": "Cabaña del Lago"},
  "temporadas": [{"id": 3, "nombre": "alta", "fecha_inicio": "2027-01-01", "fecha_fin": "2027-02-19"}],
  "temporada": {"id": 3, "nombre": "alta", "fecha_inicio": "2027-01-01", "fecha_fin": "2027-02-19"},
  "estadisticas": {
    "noches_disponibles": 50,
    "noches_ocupadas": 39,
    "ocupacion": "78.0",
    "tarifa_promedio": "81400.00",
    "estadia_promedio": "4.2",
    "reservas": 10,
    "cancelaciones": {"mas_de_30": 1, "de_15_a_30": 2, "menos_de_15": 0, "otras": 0},
    "huespedes_recurrentes": 3,
    "hay_datos": true
  }
}
```

`temporadas` viene de la más nueva a la más vieja: es la lista del selector de la pantalla.

**Comparar dos unidades** (lo que pidió Marisa) es esta misma ruta pedida **dos veces**, una por unidad. No hay un endpoint aparte: así el front elige qué temporada mira en cada una.

## Los dos estados vacíos

No son el mismo caso y la pantalla no dice lo mismo en cada uno:

| Qué pasó | Respuesta | Qué muestra el front |
|---|---|---|
| La unidad **no tiene ninguna temporada cargada** | `"temporada": null` y `"estadisticas": null` | Que primero hay que cargar una temporada. |
| La temporada existe pero **no tuvo movimiento** | `"hay_datos": false`, todo en cero | *"Todavía no hay datos de esta temporada"* (el texto exacto lo pide el documento). |

## Cómo se cuenta cada número

- **Ocupación** = noches ocupadas ÷ noches de la temporada. Ocupan solo las reservas `confirmada` y `finalizada`: una `pendiente` todavía puede expirar, una `cancelada` no ocupó nada y en un `no_show` (decisión #67) el huésped nunca llegó.
- Si la temporada **está en curso**, el denominador llega **hasta anoche** y no hasta su última noche: si no, toda temporada empezada daría una ocupación falsamente baja.
- **Ocupación y tarifa promedio se cuentan noche por noche**: una reserva que empieza en temporada baja y termina en alta le aporta a cada una las noches que le tocan. La tarifa promedio es el precio **de la noche**, no el de la reserva.
- **Estadía promedio, cancelaciones y `reservas`** cuentan la reserva **entera** en la temporada donde **empieza**: son propiedades de la reserva, no de cada noche.
- Una **extensión** (`reservas.extiende_reserva_id`, decisión #70) no es una reserva aparte: sus noches alargan la estadía de la reserva que extiende.
- **Huéspedes recurrentes** es el **histórico de la unidad**, no de la temporada: un huésped es recurrente porque volvió, y bien puede haber vuelto en otra temporada.
- El **tramo de una cancelación** se mide contra `fecha_cobro`, con los mismos cortes que la devolución que se le hizo al huésped (más de 30 días, de 15 a 30, menos de 15): si se midiera contra `fecha_ingreso`, la estadística diría una cosa y la plata devuelta otra. Las cancelaciones **del propietario o de la plataforma** van aparte, en `otras`: no hubo retención por política, se devolvió todo (decisión #63).
