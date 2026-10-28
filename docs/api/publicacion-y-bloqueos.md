# API: publicación y fechas bloqueadas de una unidad (US3.1, US3.3)

Publicar una unidad (que aparezca en la búsqueda), pausarla, y bloquear las fechas que no se pueden reservar en la plataforma. Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

Todas las rutas necesitan sesión iniciada (el `fetch` lleva `credentials: 'include'`). Para cualquier ruta de esta página:

| Código | Cuerpo | Cuándo |
|---|---|---|
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión, o expiró. |
| `403` | `{"error": "No tenés permiso para hacer esto"}` | Publicar y pausar: el usuario no es propietario (por ejemplo, el administrador de la unidad). |
| `404` | `{"error": "No encontramos esa unidad"}` | La unidad no existe, o el usuario no puede trabajar sobre ella. |
| `422` | `{"error": "Unidad inválida"}` | Falta el id de la unidad (`id` en los `GET`, `unidad_id` en los `POST`), o no es un entero positivo. |

**Fechas:** siempre `AAAA-MM-DD`. Son **noches**: un bloqueo del 05/10 al 10/10 son 6 noches (del 5 al 10, las dos incluidas). "Hoy" es la fecha de Argentina.

## Publicación

Una unidad nace en **borrador** (no aparece en la búsqueda). El propietario la **publica** cuando cumple las reglas de publicación, y la puede **pausar** (sale de la búsqueda pero conserva sus reservas) y volver a publicar.

Las reglas de publicación son las mismas que muestra el resumen de la unidad (`faltantes` en `GET /unidades/resumen`):
- al menos una noche con precio vigente, de hoy en adelante (RN-01);
- al menos una foto.

### Publicar

```
POST /unidades/publicar
{"unidad_id": 5}
```

Solo el propietario.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Publicaste la unidad: ya aparece en la búsqueda", "estado_publicacion": "publicada"}` | Estaba en borrador o pausada y cumple las reglas. |
| `200` | `{"ok": "La unidad ya está publicada", "estado_publicacion": "publicada"}` | Ya estaba publicada: no cambia nada. |
| `409` | `{"error": "No se puede publicar: la unidad está fuera de servicio"}` | La unidad no está disponible por un daño (Épica 8). |
| `422` | `{"error": "Para publicarla te falta completar algunas cosas", "faltantes": ["Cargá el precio de al menos una noche, de hoy en adelante", "Subí al menos una foto"]}` | No cumple alguna regla. `faltantes` dice cuáles. |

### Pausar

```
POST /unidades/pausar
{"unidad_id": 5}
```

Solo el propietario.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Pausaste la unidad: ya no aparece en la búsqueda. Sus reservas siguen en pie", "estado_publicacion": "pausada"}` | Estaba publicada. |
| `200` | `{"ok": "La unidad ya está pausada", "estado_publicacion": "pausada"}` | Ya estaba pausada: no cambia nada. |
| `409` | `{"error": "La unidad no está publicada"}` | Está en borrador: no se pausa algo que nunca se publicó. |

## Fechas bloqueadas

Noches que **no se pueden reservar** en la plataforma: se reservaron por otro medio, hay mantenimiento, daños, el propietario no quiere recibir reservas, etc. Las manejan **el propietario y el administrador asignado**.

- **El motivo es solo para ellos** (para recordar por qué se bloqueó). **El huésped nunca lo ve:** para él, la noche está "ocupada".
- **Las noches pasadas no se bloquean ni se liberan.**
- Dos bloqueos de la misma unidad no comparten ninguna noche.
- **Pendiente (Épica 4):** un bloqueo no va a poder pisar noches con reservas de la plataforma (sería una cancelación del propietario, que tiene su propio trato).

| `motivo` | Qué significa |
|---|---|
| `reserva_externa` | Reserva por otro medio (Airbnb, Booking…) |
| `mantenimiento` | Mantenimiento o arreglos |
| `danio` | Daños en la unidad |
| `uso_personal` | El propietario no quiere recibir reservas en esas fechas |
| `otro` | Otro motivo: lo explica `detalle`, que en este caso es obligatorio |

### Listar

```
GET /unidades/bloqueos?id=5
```

Los bloqueos que **todavía no terminaron** (incluye los que están en curso), ordenados por la primera noche.

| Código | Cuerpo |
|---|---|
| `200` | `{"bloqueos": [{"id": 8, "fecha_desde": "2026-11-20", "fecha_hasta": "2026-11-26", "motivo": "reserva_externa", "detalle": "Reservada por Airbnb", "creado_por": "Marisa"}, ...]}` (o `[]`) |

`creado_por` es el nombre de quien lo cargó (el propietario o el administrador). `detalle` puede ser `null`.

### Bloquear

```
POST /unidades/bloqueos/crear
{"unidad_id": 5, "fecha_desde": "2026-11-20", "fecha_hasta": "2026-11-26", "motivo": "reserva_externa", "detalle": "Reservada por Airbnb"}
```

| Campo | Regla |
|---|---|
| `fecha_desde`, `fecha_hasta` | Primera y última noche. De hoy en adelante, la primera no posterior a la última, hasta 366 noches |
| `motivo` | Uno de la tabla de arriba |
| `detalle` | Opcional, hasta 255 caracteres. **Obligatorio si el motivo es `otro`** |

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Bloqueaste esas fechas", "id": 8}` | Se bloqueó. |
| `409` | `{"error": "Se superpone con otro bloqueo, del 20/11/2026 al 26/11/2026"}` | Comparte al menos una noche con otro bloqueo. |
| `422` | `{"error": "Solo podés bloquear noches de hoy en adelante"}`, `{"error": "Elegí el motivo del bloqueo"}`, `{"error": "Contanos el motivo del bloqueo"}` (y uno por cada regla) | Algún dato no cumple las reglas. |

### Liberar

```
POST /unidades/bloqueos/liberar
{"unidad_id": 5, "bloqueo_id": 8}
```

Esas noches se vuelven a poder reservar. Si el bloqueo **todavía no empezó**, se borra. Si **está en curso**, se recorta hasta ayer: las noches pasadas quedan bloqueadas (historial) y se liberan las de hoy en adelante.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Liberaste esas fechas"}` | Todavía no había empezado: se borró. |
| `200` | `{"ok": "Liberamos esas fechas de hoy en adelante"}` | Estaba en curso: se recortó hasta ayer. |
| `404` | `{"error": "No encontramos ese bloqueo"}` | No existe, o es de otra unidad. |
| `409` | `{"error": "Ese bloqueo ya terminó: las noches pasadas no se liberan"}` | Todas sus noches ya pasaron. |
| `422` | `{"error": "Bloqueo inválido"}` | El `bloqueo_id` no es un entero positivo. |
