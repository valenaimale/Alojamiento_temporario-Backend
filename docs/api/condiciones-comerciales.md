# API: condiciones comerciales de una unidad (US2.7, US2.8, US2.9)

Bajo qué condiciones se alquila una unidad: sus **temporadas** (con el mínimo de noches), el **precio de cada noche** y la **política de cancelación** con el **% de seña**. Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

**Todas las rutas son solo del propietario de la unidad** y necesitan sesión iniciada (el `fetch` lleva `credentials: 'include'`). Para cualquier ruta de esta página:

| Código | Cuerpo | Cuándo |
|---|---|---|
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión, o expiró. |
| `403` | `{"error": "No tenés permiso para hacer esto"}` | El usuario no es propietario (por ejemplo, el administrador de la unidad). |
| `404` | `{"error": "No encontramos esa unidad"}` | La unidad no existe, o es de otro propietario. |
| `422` | `{"error": "Unidad inválida"}` | Falta el id de la unidad (`id` en los `GET`, `unidad_id` en los `POST`), o no es un entero positivo. |

**Fechas:** siempre `AAAA-MM-DD`. Son **noches**: un rango del 05/10 al 10/10 son 6 noches (del 5 al 10, las dos incluidas). "Hoy" es la fecha de Argentina.

**Montos y porcentajes:** se mandan como número o texto con punto decimal (`70000.5` o `"70000.50"`), con hasta 2 decimales. Se devuelven como texto con 2 decimales (`"70000.50"`).

## Temporadas

Una temporada es un período (alta, media o baja) con el **mínimo de noches** que se exige para reservar. **No tiene precio:** el precio se carga por noche (ver "Precios"). Son **opcionales**: las noches sin temporada tienen mínimo 1. Dos temporadas de la misma unidad **no se pueden superponer**, ni por una noche.

### Listar

```
GET /unidades/temporadas?id=5
```

Las temporadas que **todavía no terminaron** (las pasadas no se pueden editar ni afectan a estadías nuevas), ordenadas por fecha de inicio.

| Código | Cuerpo |
|---|---|
| `200` | `{"temporadas": [{"id": 3, "nombre": "alta", "fecha_inicio": "2027-01-01", "fecha_fin": "2027-02-28", "minimo_noches": 7}, ...]}` (o `[]`) |

### Crear

```
POST /unidades/temporadas/crear
{"unidad_id": 5, "nombre": "alta", "fecha_inicio": "2027-01-01", "fecha_fin": "2027-02-28", "minimo_noches": 7}
```

| Campo | Regla |
|---|---|
| `nombre` | `alta`, `media` o `baja` |
| `fecha_inicio`, `fecha_fin` | Primera y última noche. El inicio no puede ser posterior al fin; el fin tiene que ser de hoy en adelante (el inicio puede ser anterior a hoy); hasta 366 noches |
| `minimo_noches` | Entero de 1 a 60 |

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Creaste la temporada", "id": 3}` | Se creó. |
| `409` | `{"error": "Se superpone con la temporada alta del 01/01/2027 al 28/02/2027"}` | Comparte al menos una noche con otra temporada de la unidad. |
| `422` | `{"error": "Elegí si es temporada alta, media o baja"}` (y uno por cada regla) | Algún dato no cumple las reglas. |

### Editar

```
POST /unidades/temporadas/editar
{"unidad_id": 5, "temporada_id": 3, "nombre": "alta", "fecha_inicio": "2027-01-01", "fecha_fin": "2027-03-15", "minimo_noches": 5}
```

Los mismos campos y reglas que al crear. Se puede editar una temporada en curso (que empezó antes de hoy), pero **no una que ya terminó**. Al controlar la superposición, no se compara consigo misma.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Guardamos los cambios de la temporada"}` | Se guardó. |
| `404` | `{"error": "No encontramos esa temporada"}` | No existe, o es de otra unidad. |
| `409` | `{"error": "Esa temporada ya terminó: no se puede editar"}` o el de superposición | |
| `422` | `{"error": "Temporada inválida"}` o el de la regla que no se cumple | El `temporada_id` no es un entero positivo, o algún dato no cumple las reglas. |

### Borrar

```
POST /unidades/temporadas/borrar
{"unidad_id": 5, "temporada_id": 3}
```

Se borra de verdad (no hay baja lógica): nada depende de una temporada, porque cada reserva guarda su propia copia del mínimo de noches.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Borraste la temporada"}` | Se borró. |
| `404` | `{"error": "No encontramos esa temporada"}` | No existe, o es de otra unidad. |
| `422` | `{"error": "Temporada inválida"}` | El `temporada_id` no es un entero positivo. |

## Precios

El precio se carga **por noche**. Es el **precio final que ve el huésped**: si el propietario cobra IVA, ya lo incluye (RN-13).

**Historial:** un precio guardado **nunca se modifica ni se borra**. Al cambiar el precio de una noche, el anterior queda cerrado (con la fecha y hora en que dejó de valer) y se crea uno nuevo. Así se puede saber a qué precio se reservó cada noche.

**Los precios no se quitan**, solo se cambian: una noche que ya tiene precio siempre tiene uno vigente. Si el propietario no quiere que se reserve, la bloquea (US3.3).

### Ver

```
GET /unidades/precios?id=5&desde=2026-12-01&hasta=2026-12-31
```

Cada noche del rango (las dos fechas incluidas, hasta 366 noches). Se pueden pedir noches pasadas: el calendario del mes en curso las muestra.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"cobra_iva": false, "noches": [{"fecha": "2026-12-01", "precio": "70000.00", "temporada": "alta", "minimo_noches": 7}, {"fecha": "2026-12-02", "precio": null, "temporada": null, "minimo_noches": 1}, ...]}` | |
| `422` | `{"error": "Las fechas tienen que tener el formato AAAA-MM-DD"}`, `{"error": "La fecha \"desde\" no puede ser posterior a la fecha \"hasta\""}` o `{"error": "El rango puede tener hasta 366 noches"}` | |

| Campo | Qué es |
|---|---|
| `cobra_iva` | Si el propietario cobra IVA. La pantalla aclara que el precio lo incluye. |
| `precio` | El precio vigente de esa noche, o `null` si no tiene (no se puede reservar). |
| `temporada` | `alta`, `media` o `baja`, o `null` si la noche no está en ninguna temporada. |
| `minimo_noches` | El de su temporada, o 1 si no tiene. |

### Cargar

```
POST /unidades/precios/cargar
{"unidad_id": 5, "desde": "2026-12-01", "hasta": "2026-12-31", "precio": 70000}
```

El mismo precio para todas las noches del rango (las dos incluidas, hasta 366, **de hoy en adelante**). El precio tiene que ser mayor que 0, con hasta 2 decimales. Las noches que ya tenían ese mismo precio no cambian (no se agregan filas repetidas al historial).

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Guardamos el precio de 31 noches", "noches_cambiadas": 31}` o `{"ok": "Esas noches ya tenían ese precio", "noches_cambiadas": 0}` | Se guardó. |
| `409` | `{"error": "Esperá un momento y volvé a intentar"}` | Se cambió el precio de la misma noche dos veces en el mismo segundo. |
| `422` | `{"error": "Solo podés cargar precios de hoy en adelante"}`, `{"error": "El precio tiene que ser un monto mayor que 0, con hasta 2 decimales"}` o los de las fechas | |

## Política de cancelación y seña

Una sola por unidad: **no cambia por temporada**. Al crear la unidad se copian los valores por defecto de la plataforma (seña 20 %, retenciones 30 %, 50 % y 70 %).

- **Seña:** el % del total que paga el huésped al reservar.
- **Retenciones:** el % del total que se queda el propietario si el huésped cancela, según **cuántos días antes del cobro del saldo** cancela: más de 30, de 15 a 30, o menos de 15. El saldo se cobra `dias_cobro_saldo` días antes del ingreso.
- **Regla:** seña ≤ retención de más de 30 ≤ de 15 a 30 ≤ de menos de 15. Retener más al cancelar con más anticipación no tendría sentido; y como la seña nunca supera la retención más baja, al cancelar el huésped nunca recibe una devolución mayor que lo que pagó.
- Los cambios valen **para las reservas futuras**: cada reserva guarda su propia copia.

### Ver

```
GET /unidades/politica?id=5
```

| Código | Cuerpo |
|---|---|
| `200` | `{"politica": {"porcentaje_sena": "20.00", "retencion_mas_de_30": "30.00", "retencion_de_15_a_30": "50.00", "retencion_menos_de_15": "70.00", "dias_cobro_saldo": 5}}` |

### Guardar

```
POST /unidades/politica
{"unidad_id": 5, "porcentaje_sena": 25, "retencion_mas_de_30": 25, "retencion_de_15_a_30": 40, "retencion_menos_de_15": 100}
```

Los 4 porcentajes son obligatorios: cada uno de 0 a 100, con hasta 2 decimales.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Guardamos la política de cancelación y la seña", "politica": {"porcentaje_sena": "25.00", ...}}` | Se guardó. |
| `422` | `{"error": "La seña tiene que ser un porcentaje de 0 a 100, con hasta 2 decimales"}` (uno por cada campo) | Un porcentaje falta o es inválido. |
| `422` | `{"error": "La seña no puede ser mayor que la retención de más de 30 días"}` (uno por cada desigualdad) | No se cumple la regla. |
