# API: unidades grupales (US4.4)

Una **unidad grupal** junta varias unidades para reservarlas juntas, con una sola seña y un solo pago del saldo. Viene del mensaje 5 de la nota de relevamiento: Cacho alquila en enero varias cabañas a familias grandes, y las cabañas pueden ser de dueños distintos.

**Cómo funciona**, según lo que se decidió el 20/11:

- **La arma el administrador**, con 2 a 10 unidades que administra. Todas tienen que ser de la misma localidad.
- **Solo lleva un título**, sin descripción, porque en el detalle se muestra cada unidad completa.
- **No es una unidad nueva:** el huésped la encuentra en la búsqueda armada en el momento para sus fechas, **solo con las unidades libres**, y elige cuáles reservar.
- **Para los propietarios es transparente:** cada unidad sigue siendo una reserva común, con su precio, su seña, su política de cancelación y su liquidación.

La reserva y el pago del grupo están en [reserva-grupal.md](reserva-grupal.md). Las convenciones generales están en el `Readme.md`.

## Cuándo aparece en la búsqueda

Para unas fechas y una cantidad de personas, la grupal se arma con sus unidades que:

1. siguen administradas por el administrador de la grupal (si deja de administrar una, **sale sola** del grupo);
2. están publicadas y disponibles;
3. **se pueden reservar en esas fechas**: sin noches ocupadas, con precio en todas y con el mínimo de noches de su temporada. Una unidad ocupada o que no llega a su mínimo **queda afuera**, y el resto se sigue ofreciendo.

Y aparece solo si se cumplen las tres condiciones:

| Condición | Por qué |
|---|---|
| Quedan **al menos 2 unidades** | Con una sola no es grupal: esa unidad ya aparece suelta |
| Entre todas **alcanzan para las personas** | Si no hay lugar para todos, no sirve |
| **Ninguna unidad alcanza sola** | Si una cabaña sola alcanza, le sirve la unidad suelta, que ya aparece en la búsqueda |

**Ejemplo** con 3 cabañas de 4 personas (12 lugares en total):

| Búsqueda | Resultado |
|---|---|
| 10 personas, las 3 libres | La grupal con las 3 (12 lugares) y ninguna suelta, porque ninguna tiene lugar para 10 |
| 8 personas, una ocupada | La grupal con las otras 2 (8 lugares) |
| 10 personas, una ocupada | Nada: las 2 libres suman 8 |
| 3 personas | Las 3 sueltas, y no la grupal: una cabaña alcanza |

## Buscar

`GET /buscar` (ver [busqueda-y-detalle.md](busqueda-y-detalle.md)) suma la lista `grupales`, ordenada de la más barata a la más cara:

```json
{
  "unidades": [],
  "grupales": [
    {
      "id": 3, "titulo": "Cabañas 4, 5 y 6", "localidad": "Las Grutas", "provincia": "Río Negro",
      "capacidad": 12, "noches": 7, "importe_sin_descuento": "1470000.00", "precio_total": "1470000.00",
      "unidades": [
        {"id": 4, "nombre": "Cabaña 4", "capacidad": 4, "foto_principal": "https://res.cloudinary.com/..."},
        {"id": 5, "nombre": "Cabaña 5", "capacidad": 4, "foto_principal": "..."},
        {"id": 6, "nombre": "Cabaña 6", "capacidad": 4, "foto_principal": "..."}
      ]
    }
  ]
}
```

`capacidad` y `precio_total` son la suma de las unidades que ofrece. El precio de cada una ya incluye su descuento por estadía larga.

## Detalle

```
GET /grupal?id=3&ingreso=2027-01-02&egreso=2027-01-09&personas=10
```

Es público, igual que el detalle de una unidad. Las fechas y las personas son **obligatorias**, porque las unidades que ofrece dependen de ellas. Trae **solo las unidades libres**, cada una completa, para que el huésped elija cuáles reservar.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | Ver abajo | |
| `404` | `{"error": "No encontramos esa reserva grupal"}` | No existe, está desactivada o su localidad no está activa |
| `409` | `{"error": "Esta reserva grupal no está disponible para esas fechas y esa cantidad de personas"}` | No cumple las condiciones de la búsqueda |
| `422` | El error de las fechas o de las personas | |

```json
{
  "grupal": {
    "id": 3, "titulo": "Cabañas 4, 5 y 6", "localidad": "Las Grutas", "provincia": "Río Negro",
    "capacidad": 12, "noches": 7, "importe_sin_descuento": "1470000.00", "precio_total": "1470000.00",
    "unidades": [
      {
        "id": 4, "nombre": "Cabaña 4", "capacidad": 4, "caracteristicas": "...", "fotos": ["https://..."],
        "deposito_garantia": "50000.00", "porcentaje_sena": "20.00",
        "politica": {"retencion_mas_de_30": "30.00", "retencion_de_15_a_30": "50.00", "retencion_menos_de_15": "70.00"},
        "estadia": {"noches": 7, "importe_sin_descuento": "490000.00", "porcentaje_descuento": "0.00",
                    "precio_total": "490000.00", "sena": "98000.00", "pago_total": false}
      }
    ]
  }
}
```

Cada unidad tiene **su propia seña y su política**, porque pueden ser de propietarios distintos. Al cancelar una unidad se aplica la suya.

## Lo que maneja el administrador

Todas estas rutas son **solo del administrador** (`401` sin sesión, `403` con otro rol). La grupal de otro administrador "no existe" (`404`).

### `GET /administrador/grupales`

Sus grupales, cada una con sus unidades. `vigente: false` marca una unidad que ya no administra: no cuenta para el grupo. `incompleta: true` indica que tiene menos de 2 unidades vigentes y, aunque esté activa, no aparece en la búsqueda. `capacidad` es la suma de las vigentes.

```json
{"grupales": [{"id": 3, "titulo": "Cabañas 4, 5 y 6", "activa": true, "localidad": "Las Grutas", "provincia": "Río Negro",
               "capacidad": 12, "incompleta": false,
               "unidades": [{"id": 4, "nombre": "Cabaña 4", "capacidad": 4, "propietario": "Marta",
                             "estado_publicacion": "publicada", "vigente": true}]}]}
```

### `POST /administrador/grupales/crear` y `POST /administrador/grupales/editar`

```json
{"titulo": "Cabañas 4, 5 y 6", "unidad_ids": [4, 5, 6]}
```

Editar lleva además el `id`. Crear responde `201` con el `id`, y la grupal nace activa. Editar reemplaza el título y las unidades; **las reservas ya hechas no cambian**.

| Error `422` | Cuándo |
|---|---|
| `El título tiene que tener entre 3 y 100 caracteres` | |
| `Elegí las unidades de la reserva grupal` | `unidad_ids` no es una lista de números |
| `Una reserva grupal tiene de 2 a 10 unidades` | Sin contar las repetidas. El tope es el del contrato (`MAXIMO_GRUPO`) |
| `Solo podés agrupar unidades que administrás` | |
| `Todas las unidades de una reserva grupal tienen que ser de la misma localidad` | |

### `POST /administrador/grupales/estado`

```json
{"id": 3, "activa": false}
```

Una desactivada no aparece en la búsqueda. Para **activarla** tiene que tener al menos 2 unidades que siga administrando; si no, responde `409`.

## Tablas

`20261120120000_crear_unidades_grupales.php`:

- **`unidades_grupales`**: `administrador_id`, `localidad_id`, `titulo`, `activa` y `creado_en`.
- **`unidades_grupales_unidades`**: la relación con las unidades (`grupal_id`, `unidad_id`). Una unidad puede estar en más de una grupal. Si se borra la unidad o la grupal, la fila se borra sola.

La lógica está en `validacion/UnidadGrupal.php`. Los tests están en `tests/Integracion/UnidadesGrupalesTest.php`.
