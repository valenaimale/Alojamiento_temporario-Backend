# API: base de las unidades (Épicas 2 y 3)

Rutas compartidas por las pantallas de las unidades. Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

## Localidades

```
GET /localidades
```

La lista cerrada de destinos donde opera la plataforma (US3.2): la usan la búsqueda y el alta de una unidad. **Es pública:** no necesita sesión, porque la búsqueda también se usa sin sesión. No envía cuerpo.

Devuelve solo las localidades activas, **ordenadas por provincia y, dentro de cada provincia, por nombre** (así el front las puede agrupar con `<optgroup>`).

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"localidades": [{"id": 4, "nombre": "Mar de Ajó", "provincia": "Buenos Aires"}, ...]}` | Siempre. |

## Resumen de una unidad

```
GET /unidades/resumen?id=5
```

Los datos de la página central de una unidad: su nombre, su estado y qué le falta para publicarse. Desde esa página se entra a cada sección de la unidad. Necesita sesión iniciada (el `fetch` lleva `credentials: 'include'`).

**La pueden ver** el propietario de la unidad y el administrador asignado a ella. Para cualquier otro usuario, la unidad "no existe" (404): así nadie puede averiguar qué unidades hay probando números.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"unidad": {"id": 5, "nombre": "Cabaña del Mar", "localidad": "Las Grutas", "provincia": "Río Negro", "estado_publicacion": "borrador", "disponible": true, "motivo_no_disponible": null, "es_propietario": true, "faltantes": ["Cargá el precio de al menos una noche, de hoy en adelante"]}}` | El usuario es el propietario o el administrador de la unidad. |
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión, o expiró. |
| `404` | `{"error": "No encontramos esa unidad"}` | La unidad no existe, o el usuario no es su propietario ni su administrador. |
| `422` | `{"error": "Unidad inválida"}` | Falta el `id`, o no es un entero positivo. |

| Campo | Qué es |
|---|---|
| `estado_publicacion` | `borrador` (recién creada, no aparece en la búsqueda), `publicada` o `pausada` (el propietario la sacó de la búsqueda). |
| `disponible` | `false` si la unidad está fuera de servicio por un daño (RN-30); el motivo, en `motivo_no_disponible`. |
| `es_propietario` | `true` si quien la ve es el propietario; `false` si es el administrador. El front oculta lo que solo puede hacer el propietario. |
| `faltantes` | Lo que le falta para poder publicarse, con un mensaje para el propietario por cada cosa. Vacía = se puede publicar. Hoy: al menos una noche con precio vigente, de hoy en adelante (RN-01). |

"Hoy" es la fecha de Argentina, no la del servidor (que trabaja en UTC).
