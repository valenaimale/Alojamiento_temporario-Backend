# Estadías y libro de huéspedes (RF-10 / RF-20)

Implementación del 31/10/2026. Requiere la migración `20261031190000_agregar_estadias_y_libro.php`. Se ejecuta con el mismo procedimiento de migraciones del proyecto y sobre la base elegida explícitamente. La preview usa `alojamiento_preview_frontend`; los tests, otra base con sufijo `_test`.

## Registrar ingreso y egreso

`POST /operador/estadias/registrar`, sesión de operador asignado actualmente a la unidad:

```json
{
  "reserva_id": 12,
  "tipo": "ingreso",
  "evento_id": "671da001-1234-4567-8910-aaaaaaaaaaaa",
  "ocurrido_en": "2026-10-31T18:00:00Z",
  "estado_unidad": "Unidad limpia, sin daños",
  "notas": "Llaves entregadas"
}
```

- `tipo`: `ingreso` o `egreso`. El identificador UUID se genera una sola vez al confirmar; cada reintento conserva identificador, hora y contenido.
- Solo reservas `confirmada`. Una pendiente nunca se confirma mediante este endpoint.
- Ingreso dentro del rango contratado, desde la fecha de ingreso incluida hasta antes de la fecha de egreso, con día de Argentina. Egreso requiere ingreso registrado y no puede ser anterior.
- Hora UTC ISO 8601 sin milisegundos; no admite más de cinco minutos hacia el futuro. Se guarda también hora UTC de recepción y usuario que registró cada hecho.
- Estado de unidad opcional, hasta 500 caracteres; notas opcionales, hasta 1000. No requiere seleccionar un estado comercial ni generar cargos por daños.
- Respuesta `200`: `ok` y `estadia` con `ingreso_en`, `egreso_en`, `estado_ingreso`, `notas_ingreso`, `estado_egreso`, `notas_egreso`. Cada fecha se devuelve en UTC con `Z`.
- Repetir exactamente un hecho aceptado devuelve `200`, incluso después del egreso. Otro contenido para el mismo movimiento devuelve `409`; no sobrescribe la historia.
- Egreso cambia la reserva a `finalizada`. No altera importes, condiciones ni fechas contratadas. Las noches contratadas continúan ocupadas aunque el egreso sea anticipado; no se simula cancelación parcial ni devolución.
- Sin sesión `401`; rol incorrecto `403`; reserva inexistente o unidad ajena `404`; datos inválidos `422`; transición inválida o conflicto `409`.

`GET /operador/agenda` agrega `estadia`, `puede_registrar_ingreso` y `puede_registrar_egreso`. Incluye reservas futuras, estadías abiertas aunque el egreso previsto haya pasado y egresos registrados hoy. Nunca incluye importes, DNI ni el libro de huéspedes. La agenda no supone que alguien llegó solo porque pasó la fecha prevista.

El frontend conserva borradores y movimientos sin enviar en localStorage, separados por usuario. Antes de enviarlos comprueba la misma sesión. Ante red caída o error del servidor mantiene el evento y reintenta al volver la conexión o mediante un botón. Un rechazo definitivo queda para revisión y no entra en un bucle automático. Esto permite continuar con una agenda ya abierta; no implementa la aplicación completa sin conexión ni recupera datos si se borra el almacenamiento del navegador.

## Libro por reserva

`GET /reservas/huespedes?reserva_id=12` devuelve `reserva` y `huespedes`. La reserva incluye contexto, cantidad de personas, `es_titular`, `puede_editar`, `version`, `completo` y datos del titular para precargar el primer formulario.

Lectura: titular, propietario de la unidad y administrador actualmente asignado. Operador, backoffice y usuarios ajenos no pueden consultar ni exportar (`404`). Las relaciones se comprueban en cada petición.

`POST /reservas/huespedes`:

```json
{
  "reserva_id": 12,
  "version": 0,
  "huespedes": [{
    "nombre": "Persona de prueba",
    "tipo_documento": "DNI",
    "documento": "99000091",
    "nacionalidad": "Argentina",
    "fecha_nacimiento": "1990-02-15",
    "domicilio": "Domicilio ficticio 123"
  }]
}
```

- Escribe únicamente el titular (huésped o propietario actuando como huésped), en reservas pendientes o confirmadas, antes del día de ingreso en Argentina y sin ingreso real registrado.
- Primer elemento: titular. Los demás son acompañantes; no se crean cuentas.
- Permite guardar parcialmente: al menos una persona completa, hasta el número de personas reservado, máximo 50. `completo` solo es verdadero si están todos. La pantalla distingue guardados y pendientes.
- Campos obligatorios por persona: nombre, tipo y número de documento, nacionalidad, nacimiento y domicilio. Tipos: DNI, Pasaporte, Otro. DNI de 7–8 dígitos; se normalizan espacios, puntos y guiones. Rechaza documentos duplicados y fechas inexistentes, futuras o anteriores a 1900.
- Cada escritura reemplaza el conjunto en una transacción e incrementa `version`. Una versión antigua devuelve `409` y no borra datos. El formulario conserva lo escrito; recargar requiere confirmar si hay cambios sin guardar.
- Respuesta `200`: `ok`, `version` nueva y `completo`. Datos inválidos `422`; estado/plazo/versión incompatible `409`.
- Los datos personales del libro no se guardan en localStorage. Lectura y exportación usan `Cache-Control: no-store`.

## Consulta mensual y exportación

`GET /unidades/libro?unidad_id=5&mes=2026-11`: solo propietario o administrador asignado. Incluye reservas que transcurren en ese mes, también históricas, canceladas o finalizadas. Devuelve fechas, estado, titular, personas reservadas y cantidad cargada, con acceso al libro de cada reserva.

`GET /reservas/huespedes/exportar?reserva_id=12`: CSV UTF-8 con BOM, separador punto y coma, una fila por persona y contexto de la reserva. Protege los valores que podrían interpretarse como fórmulas. Es una exportación general, no un formulario municipal certificado.

## Alcance pendiente

Pagos y confirmación por pago continúan pendientes. Para demostrar ingreso/egreso se usa una reserva confirmada sintética. La integración futura debe validar el libro antes del pago según la definición del producto. No hay edición destructiva de movimientos ya registrados, no-show, liberación anticipada, correcciones excepcionales del libro fuera de plazo ni reglas económicas por daños; requieren el flujo correspondiente y acuerdo del equipo. El cierre de RF aquí corresponde al alcance del archivo de avance: marcar llegada/salida con hora/notas y cargar quiénes se alojan.

Pruebas: `tests/Integracion/EstadiasYLibroTest.php`, `AgendaOperadorTest.php`, `RequestTest.php`; regresión de reservas, bloqueos y búsqueda. Evidencia visual y CSV sintético en la carpeta de seguimiento de Entrega 4.
