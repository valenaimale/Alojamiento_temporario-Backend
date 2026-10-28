# API: búsqueda y detalle de alojamientos (US3.2)

Lo que usa el huésped para encontrar dónde quedarse: la búsqueda, el detalle de una unidad y su calendario. Las convenciones generales (URL base, formato de los errores) están en el `Readme.md`, sección "API: rutas y contratos".

**Las tres rutas son públicas:** no necesitan sesión (se usan también sin haber iniciado sesión).

**Fechas:** siempre `AAAA-MM-DD`. Una estadía del 05/12 al 10/12 son las noches 5, 6, 7, 8 y 9: **la fecha de egreso no es una noche**. Por eso se puede salir el mismo día en que empieza una noche ocupada. "Hoy" es la fecha de Argentina.

## Cuándo se puede reservar una estadía

Las tres rutas usan el mismo cálculo (`validacion/Estadia.php`). Una estadía se puede reservar si:
1. **Ninguna noche está ocupada.** Hoy, ocupada = bloqueada por el propietario o su administrador (el motivo nunca se muestra). En la Épica 4 se suman las reservas.
2. **Todas las noches tienen precio.**
3. La cantidad de noches llega al **mínimo de la temporada**. Si la estadía toca varias temporadas, se exige el **mayor** de sus mínimos (RN-09). Las noches sin temporada tienen mínimo 1.

Y además: el ingreso es **de mañana en adelante** (hoy ya no se reserva), el egreso es posterior al ingreso, y la estadía es de **hasta 60 noches**.

El **precio total** es la suma del precio de cada noche: es el precio final, con el IVA incluido si el propietario lo cobra (RN-13).

## Buscar

```
GET /buscar?localidad_id=1&ingreso=2026-12-05&egreso=2026-12-10&personas=4
```

| Parámetro | Regla |
|---|---|
| `ingreso`, `egreso` | Obligatorios |
| `personas` | Obligatorio, de 1 a 50 |
| `localidad_id` | **Opcional**: sin localidad, busca en todos los destinos. Si viene, tiene que ser una localidad activa (`GET /localidades`) |

Devuelve las unidades **publicadas**, **disponibles** (no fuera de servicio), de una localidad activa, con **capacidad** para todas las personas y que **se pueden reservar en esas fechas**. Ordenadas de la más barata a la más cara.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"unidades": [{"id": 1, "nombre": "Cabaña del Mar", "localidad": "Las Grutas", "provincia": "Río Negro", "capacidad": 4, "foto_principal": "https://res.cloudinary.com/...", "noches": 5, "precio_total": "350000.00"}, ...]}` (o `[]`) | |
| `422` | `{"error": "El ingreso tiene que ser de mañana en adelante"}`, `{"error": "Indicá cuántas personas van a ser (de 1 a 50)"}`, `{"error": "Elegí una localidad de la lista"}` (y uno por cada regla) | Algún parámetro no cumple las reglas. |

## Detalle de un alojamiento

```
GET /alojamiento?id=1&ingreso=2026-12-05&egreso=2026-12-10&personas=4
```

Los datos de una unidad para el huésped. **Las fechas y las personas son opcionales:** si vienen las fechas, la respuesta dice además si la estadía se puede reservar y cuánto sale. Si vienen las personas, también se controla la capacidad.

**No incluye la dirección exacta:** se le da al huésped cuando reserva (Épica 4). Así nadie puede saber dónde hay una casa vacía en ciertas fechas.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | Ver abajo | La unidad está publicada y disponible. |
| `404` | `{"error": "No encontramos ese alojamiento"}` | No existe, no está publicada, está fuera de servicio, o el `id` es inválido. |
| `422` | `{"error": "Elegí las fechas de ingreso y de egreso"}` (vino una sola), o el de la regla de las fechas o las personas | |

```json
{
  "alojamiento": {
    "id": 1, "nombre": "Cabaña del Mar", "localidad": "Las Grutas", "provincia": "Río Negro",
    "capacidad": 4, "caracteristicas": "A dos cuadras de la playa...",
    "fotos": ["https://res.cloudinary.com/.../principal.jpg", "..."],
    "deposito_garantia": "50000.00",
    "porcentaje_sena": "20.00",
    "politica": {"retencion_mas_de_30": "30.00", "retencion_de_15_a_30": "50.00", "retencion_menos_de_15": "70.00", "dias_cobro_saldo": 5}
  },
  "estadia": {"disponible": true, "noches": 5, "precio_total": "350000.00", "sena": "70000.00"}
}
```

| Campo | Qué es |
|---|---|
| `fotos` | Las URLs en orden: la primera es la principal. |
| `deposito_garantia` | Informativo (o `null`): se cobra y se devuelve **por fuera de la aplicación** (US2.6). |
| `politica` | Qué % del total retiene el propietario si el huésped cancela, según los días que falten para el **cobro del saldo**, que es `dias_cobro_saldo` días antes del ingreso. |
| `estadia` | `null` si no vinieron fechas. Si se puede reservar: `noches`, `precio_total` y `sena` (el `porcentaje_sena` del total). Si no: `{"disponible": false, "motivo": "..."}`, con el motivo para el huésped: `"Hay noches ocupadas en esas fechas"`, `"Hay noches sin precio en esas fechas"`, `"Mínimo 7 noches en temporada alta"` o `"Tiene lugar para 4 personas"`. |

## Calendario de un alojamiento

```
GET /alojamiento/calendario?id=1&mes=2026-12
```

Cada noche del mes, para que el huésped elija sus fechas tocando el calendario. **Solo las noches `libre` se pueden elegir como noches de la estadía** (la fecha de egreso, que no es una noche, puede caer en cualquiera).

| Parámetro | Regla |
|---|---|
| `mes` | `AAAA-MM`, desde este mes hasta dentro de dos años |

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"noches": [{"fecha": "2026-12-01", "estado": "libre", "precio": "70000.00", "minimo_noches": 7}, {"fecha": "2026-12-02", "estado": "ocupada", "precio": null, "minimo_noches": 7}, ...]}` | Una noche por cada día del mes. |
| `404` | `{"error": "No encontramos ese alojamiento"}` | Igual que en el detalle. |
| `422` | `{"error": "El mes tiene que tener el formato AAAA-MM"}` o `{"error": "Podés ver desde este mes hasta dentro de dos años"}` | |

| `estado` | Qué significa |
|---|---|
| `libre` | Se puede reservar. Trae su `precio`. |
| `ocupada` | Bloqueada (en la Épica 4, también reservada). No dice por qué. |
| `sin_precio` | El propietario no le cargó precio. |
| `pasada` | Ya pasó, o es hoy (hoy ya no se reserva). |

`minimo_noches` es el mínimo de la temporada de esa noche (1 si no tiene), para que la pantalla avise si la estadía elegida no llega.
