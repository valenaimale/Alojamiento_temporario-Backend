# API: quién gestiona una unidad (US2.2, US2.3, US2.4, US2.5)

El **administrador** de una unidad (con su comisión), su **operador** de estadías, y la **cartera** del administrador. Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

Todas las rutas necesitan sesión iniciada (el `fetch` lleva `credentials: 'include'`).

**Se asigna por mail:** no hay un buscador de usuarios, así nadie puede listar las cuentas de otros. Si el mail no es de una cuenta activa con el rol que corresponde, el mensaje es siempre el mismo (no dice si el mail existe).

| Quién | Ver la gestión | Administrador (asignar, comisión, quitar) | Operador (asignar, quitar) |
|---|---|---|---|
| Propietario de la unidad | ✅ | ✅ | ✅ |
| Administrador de la unidad | ✅ | ❌ (403) | ✅ |
| Cualquier otro | ❌ (404) | ❌ (404, o 403 si no es propietario) | ❌ (404) |

Para cualquier ruta de una unidad: `401` sin sesión, `404` `{"error": "No encontramos esa unidad"}` si la unidad no existe o el usuario no puede trabajar sobre ella, y `422` `{"error": "Unidad inválida"}` si falta el id o no es un entero positivo.

## Ver la gestión

```
GET /unidades/gestion?id=5
```

| Código | Cuerpo |
|---|---|
| `200` | `{"administrador": {"nombre": "Cacho", "mail": "cacho@ejemplo.local", "porcentaje_comision": "15.00", "desde": "2026-10-29 14:30:00"}, "operador": {"nombre": "Nadia", "mail": "nadia@ejemplo.local"}, "es_propietario": true}` |

`administrador` es `null` si la unidad es de **autogestión**; `operador`, `null` si no tiene. `es_propietario` en `false` = quien la ve es el administrador: el front no le muestra los botones del administrador.

## Administrador (US2.2, US2.3)

Una unidad tiene **como mucho un administrador**. Gestiona la unidad de un propietario a cambio de una **comisión** (RN-05): un % obligatorio, mayor que 0 y menor que 100, con hasta 2 decimales. **Solo el propietario** lo asigna, le cambia la comisión o lo quita.

**Cada cambio se le avisa al administrador por mail** (asignación, cambio de comisión, desvinculación). Si el mail no se puede enviar, el cambio se guarda igual y el error queda en el log.

### Asignar

```
POST /unidades/administrador/asignar
{"unidad_id": 5, "mail": "cacho@ejemplo.local", "porcentaje_comision": 15}
```

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Asignaste a Cacho como administrador"}` | Se asignó. |
| `409` | `{"error": "La unidad ya tiene administrador. Quitalo antes de asignar otro."}` | |
| `422` | `{"error": "No hay un administrador registrado con ese mail"}` | El mail no es de una cuenta activa con rol administrador. |
| `422` | `{"error": "La comisión tiene que ser un porcentaje mayor que 0 y menor que 100, con hasta 2 decimales"}` | |

### Cambiar la comisión

```
POST /unidades/administrador/comision
{"unidad_id": 5, "porcentaje_comision": 18}
```

Vale **para las liquidaciones futuras**: cada liquidación guarda su propio % (Épica 9).

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Cambiaste la comisión"}` o `{"ok": "La comisión ya era esa"}` (no cambia nada ni avisa) | |
| `409` | `{"error": "La unidad no tiene administrador"}` | |
| `422` | El de la comisión | |

### Quitar

```
POST /unidades/administrador/quitar
{"unidad_id": 5}
```

La unidad vuelve a ser de **autogestión**, y el administrador deja de verla.

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Quitaste a Cacho: la unidad vuelve a ser de autogestión"}` | |
| `409` | `{"error": "La unidad no tiene administrador"}` | |

## Operador (US2.4)

Recibe a los huéspedes (ingreso y egreso). Una unidad tiene **como mucho un operador**; un operador puede tener **varias unidades**. **Nunca ve montos** (RN-19). Lo asignan y lo quitan **el propietario o el administrador** de la unidad.

### Asignar

```
POST /unidades/operador/asignar
{"unidad_id": 5, "mail": "nadia@ejemplo.local"}
```

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Asignaste a Nadia como operador"}` | |
| `409` | `{"error": "La unidad ya tiene operador. Quitalo antes de asignar otro."}` | |
| `422` | `{"error": "No hay un operador registrado con ese mail"}` | El mail no es de una cuenta activa con rol operador. |

### Quitar

```
POST /unidades/operador/quitar
{"unidad_id": 5}
```

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Quitaste al operador"}` | |
| `409` | `{"error": "La unidad no tiene operador"}` | |

## Cartera del administrador (US2.5)

```
GET /administrador/unidades
```

Las unidades que administra el usuario logueado, ordenadas por nombre. Solo para administradores (`403` para cualquier otro rol). Del propietario se muestra **solo el nombre**.

| Código | Cuerpo |
|---|---|
| `200` | `{"unidades": [{"id": 3, "nombre": "Casa del Bosque", "localidad": "Mar de las Pampas", "provincia": "Buenos Aires", "propietario": "Marisa", "estado_publicacion": "publicada", "disponible": true, "porcentaje_comision": "15.00", "foto_principal": "https://res.cloudinary.com/..."}]}` (o `[]`) |
