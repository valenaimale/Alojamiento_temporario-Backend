# Recuperación de contraseña

Implementada el 31/10/2026. Reutiliza SMTP/Brevo; no requiere una cuenta ni sesión abiertas. Aplica a los cinco roles con contraseña local.

## Preparación

Aplicar la migración `20261031150000_agregar_recuperacion_contrasenia.php` antes de servir el código nuevo. Agrega `usuarios.version_sesion`, `recuperaciones_contrasenia` y `limites_recuperacion`. Configurar `APP_URL_FRONT` y el transporte de correo existente. En producción, el front y la API deben usar HTTPS.

En la preview, la migración se aplica a `alojamiento_preview_frontend`. Las pruebas usan otra base terminada en `_test`; nunca ejecutar su preparación sobre una base real.

## Solicitar el enlace

`POST /recuperar-contrasenia`

```json
{"mail":"persona@ejemplo.com"}
```

- Mail como string válido, hasta 254 bytes; se normaliza con trim y minúsculas.
- Respuesta 200 idéntica para cuentas existentes, inexistentes, inactivas, exclusivamente Google y solicitudes limitadas:

```json
{"ok":"Si hay una cuenta con ese mail y contraseña, te vamos a enviar un enlace para recuperar el acceso."}
```

- Formato incorrecto: 422 con `error`.
- Máximo un envío por mail por minuto y 20 solicitudes válidas por IP cada 15 minutos. Los contadores se guardan en MySQL y sirven para varias réplicas. No bloquean login ni deshabilitan cuentas.
- La IP sale de `REMOTE_ADDR`; no se confía en `X-Forwarded-For` enviado por el cliente. Detrás de un proxy el límite puede compartirse: configurar un proxy de confianza antes de adoptar su IP original.
- La respuesta HTTP se completa antes de consultar la cuenta y enviar el mail, mediante `fastcgi_finish_request()` o Content-Length + flush en el servidor PHP actual. El envío ocupa el worker hasta terminar, con timeout SMTP/Brevo de 10 segundos. No es una cola durable: si falla o se reinicia el proceso, se registra un error sin el contenido del mail y se puede pedir otro enlace al minuto. Verificar buffering del proxy al desplegar; no asumir tiempo idéntico a través de cualquier infraestructura.

El enlace apunta a `APP_URL_FRONT/vistas/sesion/restablecer-contrasenia.html#token=...`. El host nunca se toma de la petición. El fragmento no se envía al servidor web ni en Referer; el front lo retira del historial y lo conserva solo en memoria. Recargar la página requiere abrir otra vez el enlace del correo.

## Elegir la nueva contraseña

`POST /restablecer-contrasenia`

```json
{
  "token":"64 caracteres hexadecimales recibidos por correo",
  "contrasenia":"una nueva contraseña",
  "confirmar_contrasenia":"una nueva contraseña"
}
```

- Token de 32 bytes aleatorios, representado como 64 hexadecimales. La base guarda solamente SHA-256.
- Vigencia de 30 minutos calculada en UTC por MySQL. Solo sirve el último enlace emitido por cuenta.
- La contraseña respeta la política del registro: 8–72 bytes, sin recortar espacios; confirmación exacta. El front exige al menos 8 caracteres y también controla el máximo de bytes.
- Cuenta activa y contraseña local obligatorias. Una cuenta exclusivamente Google no recibe una contraseña local por este flujo.
- Transacción y bloqueos de filas consumen el token y actualizan contraseña + versión de sesión juntos. Un enlace usado, vencido, alterado o reemplazado devuelve 422:

```json
{"error":"El enlace venció o ya no es válido. Pedí uno nuevo.","codigo":"enlace_invalido"}
```

- Campos inválidos o confirmación distinta: 422 con `error`; no consumen el enlace.
- Éxito: 200 con `ok`. Envía aviso de cambio sin contraseña ni token. No inicia sesión automáticamente y no cambia rol, DNI ni verificación de mail.
- Las sesiones anteriores reciben 401 en su próxima petición protegida. Funciona con sesiones en archivos y Redis; la versión queda en metadatos privados y no altera el JSON público de usuario.
- Un login simultáneo captura la versión leída junto con la contraseña verificada, para no rehabilitar una credencial anterior.
- Ambas rutas responden con `Cache-Control: no-store`. No registrar cuerpos de estas peticiones en proxies, analítica o logs.

## Frontend

- `vistas/sesion/inicio-sesion.html`: «Olvidé mi contraseña».
- `vistas/sesion/recuperar-contrasenia.html`: mail, confirmación genérica, espera de 60 segundos para reenviar y opción de corregir mail.
- `vistas/sesion/restablecer-contrasenia.html`: dos contraseñas, controles Mostrar/Ocultar existentes, errores recuperables y confirmación con acceso al login.
- Mismo estilo de cuentas, HTML/CSS/JS sin dependencias nuevas. Navegación con teclado, labels, errores anunciados y foco en las confirmaciones.

## Pruebas

`vendor/bin/phpunit --filter RecuperacionContraseniaTest`

Comprueba roles, cierre de dos sesiones, login con contraseña nueva/anterior, hash persistido, token único/vencido/reemplazado, cuentas deshabilitadas/Google, tipos y límites de contraseña, confirmación, limitación por mail/IP y Host malicioso. Con Mailpit recorre el correo real y comprueba el aviso de cambio.

Referencia de seguridad: [OWASP Forgot Password Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).
