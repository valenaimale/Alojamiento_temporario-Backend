# API: cuentas y roles

Contratos del registro completo, de "hacerme propietario" y de los datos fiscales y bancarios. Las convenciones generales (URL base, formato de los errores, sesiones) están en el `Readme.md`, sección "API: rutas y contratos".

**Propietarios y administradores tienen los mismos datos fiscales y bancarios:** si cobran IVA, el CUIT, la razón social y el domicilio fiscal; y siempre, la cuenta bancaria donde cobran (CBU, alias opcional y titular). Se guardan en `propietarios` o en `administradores`, según el rol.

## Registro de usuario

```
POST /registrarse
```

**Envía:**

```json
{
    "nombre": "Ana Pérez",
    "dni": "30.123.456",
    "mail": "ana@mail.com",
    "mail_confirmacion": "ana@mail.com",
    "contrasenia": "12345678",
    "contrasenia_confirmacion": "12345678",
    "rol": "propietario",
    "cobra_iva": "1",
    "cuit": "20-30123456-3",
    "razon_social": "Ana Pérez Alojamientos",
    "domicilio_fiscal": "Av. Siempreviva 742, Luján, Buenos Aires",
    "cbu": "2850590940090418135201",
    "alias": "ana.alojamientos",
    "titular_cuenta": "Ana Pérez"
}
```

| Campo | Reglas |
|---|---|
| `nombre` | Obligatorio. Entre 2 y 70 caracteres. |
| `dni` | Obligatorio. 7 u 8 números. Se puede mandar con puntos: se guarda solo con números. No se puede repetir. |
| `mail` | Obligatorio. Formato de mail válido. Se guarda en minúsculas. No se puede repetir. |
| `mail_confirmacion` | Obligatorio. Tiene que ser igual a `mail`. |
| `contrasenia` | Obligatorio. Entre 8 y 72 caracteres. Se guarda hasheada. |
| `contrasenia_confirmacion` | Obligatorio. Tiene que ser igual a `contrasenia`. |
| `rol` | Obligatorio. Uno de: `huesped`, `propietario`, `administrador`, `operador`. |
| `cobra_iva` | Solo si `rol` es `propietario` o `administrador`: `"1"` o `"0"`. |
| `cuit` | Solo si `cobra_iva` es `"1"`. CUIT válido (11 números y dígito verificador correcto). Se puede mandar con guiones: se guarda solo con números. No se puede repetir, ni entre propietarios ni entre administradores. |
| `razon_social` | Solo si `cobra_iva` es `"1"`. Entre 2 y 150 caracteres. |
| `domicilio_fiscal` | Solo si `cobra_iva` es `"1"`. Entre 5 y 200 caracteres. |
| `cbu` | Solo si `rol` es `propietario` o `administrador`, y ahí es obligatorio. 22 números con sus dos dígitos verificadores correctos. Se puede mandar con espacios o guiones: se guarda solo con números. |
| `alias` | Opcional (propietario o administrador). De 6 a 20 caracteres: letras sin tilde ni ñ, números, puntos y guiones. Se guarda en minúsculas. Vacío = sin alias. |
| `titular_cuenta` | Solo si `rol` es `propietario` o `administrador`, y ahí es obligatorio. Entre 2 y 150 caracteres. |

Si el `rol` es `huesped` u `operador`, los datos fiscales y bancarios se ignoran. Si cobra IVA es `"0"`, el CUIT, la razón social y el domicilio fiscal se ignoran y quedan vacíos.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `201` | `{"ok": "Cuenta creada exitosamente", "usuario": {"id": 8, "nombre": "Ana Pérez", "mail": "ana@mail.com", "dni": "30123456", "rol": "propietario", "mail_verificado": false}}` | El usuario se registró. Queda con la sesión iniciada. Si es propietario o administrador, también se creó su fila en `propietarios` o en `administradores`. |
| `422` | `{"error": "Todos los campos son obligatorios"}` | Falta `nombre`, `dni`, `mail`, `mail_confirmacion`, `contrasenia`, `contrasenia_confirmacion` o `rol`. |
| `422` | `{"error": "El rol no existe"}` | El rol no es uno de los permitidos. |
| `422` | `{"error": "El nombre debe tener entre 2 y 70 caracteres"}` | Nombre demasiado corto o largo. |
| `422` | `{"error": "El DNI tiene que tener 7 u 8 números"}` | El DNI tiene letras, o no tiene 7 u 8 números. |
| `422` | `{"error": "El mail no es válido"}` | El mail no tiene formato válido. |
| `422` | `{"error": "Los mails no coinciden"}` | `mail` y `mail_confirmacion` son distintos. |
| `422` | `{"error": "La contraseña debe tener entre 8 y 72 caracteres"}` | Contraseña demasiado corta o larga. |
| `422` | `{"error": "Las contraseñas no coinciden"}` | `contrasenia` y `contrasenia_confirmacion` son distintas. |
| `422` | `{"error": "Indicá si cobrás IVA"}` | El rol es `propietario` o `administrador` y `cobra_iva` no es `"0"` ni `"1"`. |
| `422` | `{"error": "El CUIT no es válido"}` | Cobra IVA y el CUIT no tiene 11 números o el dígito verificador está mal. |
| `422` | `{"error": "La razón social debe tener entre 2 y 150 caracteres"}` | Cobra IVA y la razón social es demasiado corta o larga. |
| `422` | `{"error": "El domicilio fiscal debe tener entre 5 y 200 caracteres"}` | Cobra IVA y el domicilio fiscal es demasiado corto o largo. |
| `422` | `{"error": "El CBU tiene que tener 22 números"}` | Falta el CBU, o no tiene 22 números. |
| `422` | `{"error": "El CBU no es válido: revisá que esté bien escrito"}` | El CBU tiene 22 números pero algún dígito verificador está mal (un número mal escrito). |
| `422` | `{"error": "El alias debe tener entre 6 y 20 caracteres: letras sin tilde, números, puntos o guiones"}` | Se mandó un alias con otro largo o con otros caracteres. |
| `422` | `{"error": "El titular de la cuenta debe tener entre 2 y 150 caracteres"}` | Falta el titular, o es demasiado corto o largo. |
| `422` | `{"error": "El mail ya está registrado"}` | Ya existe una cuenta con ese mail. |
| `422` | `{"error": "El DNI ya está registrado"}` | Ya existe una cuenta con ese DNI. |
| `422` | `{"error": "El CUIT ya está registrado"}` | Ya existe un propietario o un administrador con ese CUIT. |
| `422` | `{"error": "El mail, el DNI o el CUIT ya están registrados"}` | Dos personas se registraron al mismo tiempo con el mismo mail, DNI o CUIT. |

Las validaciones se hacen en el orden de la tabla: se responde el primer error que se encuentra.

## Hacerse propietario

```
POST /hacerse-propietario
```

Convierte al huésped logueado en propietario. Es la misma cuenta, con el mismo mail y el mismo DNI: se cambia su `rol` a `propietario` y se crea su fila en `propietarios`, con sus datos fiscales y bancarios. Necesita sesión iniciada (el `fetch` lleva `credentials: 'include'`).

**Envía:**

```json
{
    "cobra_iva": "1",
    "cuit": "20-30123456-3",
    "razon_social": "Ana Pérez Alojamientos",
    "domicilio_fiscal": "Av. Siempreviva 742, Luján, Buenos Aires",
    "cbu": "2850590940090418135201",
    "alias": "ana.alojamientos",
    "titular_cuenta": "Ana Pérez"
}
```

Si no cobra IVA, alcanza con `cobra_iva` en `"0"` y la cuenta bancaria. Las reglas de cada campo son las mismas que en el registro.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"ok": "Ya sos propietario", "usuario": {"id": 8, "nombre": "Ana Pérez", "mail": "ana@mail.com", "dni": "30123456", "rol": "propietario", "mail_verificado": false}}` | El usuario pasó a ser propietario. La sesión ya tiene el rol nuevo. |
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión, o expiró. |
| `403` | `{"error": "No tenés permiso para hacer esto"}` | El usuario es administrador, operador o backoffice. |
| `409` | `{"error": "Ya sos propietario"}` | El usuario ya es propietario. |
| `422` | `{"error": "Completá tu DNI antes de hacerte propietario"}` | La cuenta no tiene DNI (cuentas creadas con Google). |
| `422` | Los mismos errores de los datos fiscales y bancarios del registro | `cobra_iva`, CUIT, razón social, domicilio fiscal, CBU, alias o titular inválidos. |
| `422` | `{"error": "El CUIT ya está registrado"}` | Ya existe un propietario o un administrador con ese CUIT. |

## Datos fiscales y bancarios

```
GET /datos-fiscales
```

Devuelve los datos fiscales y bancarios del propietario o administrador logueado, para mostrarlos en el perfil. No envía cuerpo.

**Responde:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{"datos_fiscales": {"cobra_iva": true, "cuit": "20301234563", "razon_social": "Ana Pérez Alojamientos", "domicilio_fiscal": "Av. Siempreviva 742, Luján, Buenos Aires", "cbu": "2850590940090418135201", "alias": "ana.alojamientos", "titular_cuenta": "Ana Pérez"}}` | Cobra IVA. El CUIT y el CBU vienen solo con números. |
| `200` | `{"datos_fiscales": {"cobra_iva": false, "cuit": null, "razon_social": null, "domicilio_fiscal": null, "cbu": "2850590940090418135201", "alias": null, "titular_cuenta": "Ana Pérez"}}` | No cobra IVA y no cargó alias. |
| `401` | `{"error": "No hay sesión iniciada"}` | No hay sesión, o expiró. |
| `403` | `{"error": "No tenés permiso para hacer esto"}` | El usuario no es propietario ni administrador. |

El detalle de un usuario en el backoffice (`GET /backoffice/usuario`) devuelve estos mismos datos en `datos_fiscales`, para propietarios y administradores (`null` para el resto).
