# Recordatorios del saldo (RF-25)

Cuando el huésped paga la seña, la reserva queda confirmada y le queda el **saldo**. Unos días antes del ingreso hay que cobrárselo, y si no paga hay que recordárselo. Esto es lo que hace `scripts/enviar-recordatorios.php`.

## Por qué es un recordatorio y no un cobro

En el diseño original (DER v2) la plataforma guardaba la tarjeta del huésped en Mercado Pago y **cobraba el saldo sola**: lo que había que anotar eran los *reintentos* del cobro automático, en la tabla `pagos`.

Con la billetera no se puede: una transferencia la tiene que **firmar el huésped** desde MetaMask. La plataforma no puede sacarle la plata. Entonces lo único que puede hacer es avisarle, y lo que hay que anotar es **cuántas veces se le avisó y cuándo**, para no mandarle el mismo mail cada vez que corre el cron y para dejar de insistir en algún momento. Por eso existe la tabla `recordatorios_saldo`, que no estaba en el DER.

## Quién recibe un recordatorio

`validacion/CobroDelSaldo.php` elige, y esa decisión se prueba sola en `tests/Integracion/RecordatoriosSaldoTest.php`. Recibe un recordatorio la reserva que cumple **todo** esto:

| Condición | Por qué |
|---|---|
| Está `confirmada` | Pagó la seña. Una `pendiente` no debe el saldo: lo que le vence es el bloqueo de las fechas |
| El huésped **todavía no ingresó** (no tiene fila en `estadias`) | Si ya está adentro, el saldo ya está pagado: sin el saldo no se registra el ingreso (#25) |
| El ingreso es de hoy en adelante | Una estadía que ya pasó no se arregla con un mail |
| `monto_faltante` es mayor que 0 | Es lo que falta pagar. Lo deja en 0 el Rol 2 cuando se paga el saldo (decisión #20 de [`pagos-decisiones.md`](pagos-decisiones.md)). Si una reserva vieja no lo tiene, se calcula como antes: el total menos lo cobrado (#26) |
| Ya llegó la fecha de cobro | `reservas.fecha_cobro`, que guarda el Rol 2 al reservar (#19). Si una reserva vieja no la tiene, se calcula: `dias_cobro_saldo` antes del ingreso, o el día en que reservó si reservó con menos anticipación |
| **Todavía no se le pasó el plazo para pagar** | Pasado el plazo, lo que corresponde es cancelar la reserva (#23), no volver a pedirle la plata |
| Se le mandaron menos de `max_recordatorios_cobro` | Después del último deja de ser un olvido y pasa a ser una deuda (RN-16) |
| Pasaron `horas_entre_recordatorios` desde el anterior | Para no atosigarlo |

Las **canceladas**, las **expiradas** y las **finalizadas** quedan afuera por el estado, que es lo que corresponde: a una reserva que ya no sigue en pie no se le pide plata. Y como la cancelación automática del saldo vencido deja la reserva `cancelada`, una vez cancelada no recibe nada más.

Los parámetros están en la tabla `parametros` y los administra el backoffice:

| Parámetro | Por defecto | Para qué |
|---|---|---|
| `dias_cobro_saldo` | 5 | Cuántos días antes del ingreso se empieza a pedir el saldo |
| `max_recordatorios_cobro` | 3 | Cuántos recordatorios se mandan como máximo |
| `horas_entre_recordatorios` | 24 | Cada cuánto |
| `dias_vencimiento_saldo` | 3 | Cuántos días hay para pagar desde la fecha de cobro (ver abajo) |

`dias_vencimiento_saldo` **todavía no está en la tabla**: lo tiene que agregar la migración de la cancelación automática (es del Rol 2). Hasta entonces, `CobroDelSaldo` usa 3, que es el valor de la decisión #23.

## Hasta cuándo se puede pagar

Por la decisión **#23** de [`pagos-decisiones.md`](pagos-decisiones.md), el saldo impago tiene una consecuencia: pasado el plazo, **la reserva se cancela sola** como una cancelación del huésped con el tramo de **menos de 15 días** (se le retiene ese % de la seña y se le devuelve el resto).

El plazo lo calcula `CobroDelSaldo::limiteDePago()`:

```
límite = mínimo(fecha_cobro + dias_vencimiento_saldo, fecha_ingreso)
```

El tope en el día del ingreso es porque **sin el saldo pago no se registra el ingreso** (#25): ese día el huésped lo paga al llegar, pero no puede estirarlo más. Pasa cuando se reservó con menos de 3 días de anticipación.

`limiteDePago()` es **pública a propósito**: el script con cron que cancela las vencidas (pendiente del Rol 2) tiene que usar **el mismo** plazo que anuncian los mails. Si cada uno lo calculara por su cuenta, el mail diría una fecha y la reserva se cancelaría en otra.

**Todos** los recordatorios dicen hasta qué día se puede pagar y qué pasa si no se paga, no solo el último: el huésped tiene que poder decidir desde el primero. El del último día del plazo lo dice en el asunto («Hoy es el último día…»).

## Cómo se corre

Desde la raíz del backend:

```bash
php scripts/enviar-recordatorios.php            # muestra a quién le escribiría, sin mandar nada
php scripts/enviar-recordatorios.php --enviar   # manda los mails
```

Pensado para un **cron diario** de Railway. Correrlo dos veces el mismo día no manda nada dos veces: cada recordatorio se **anota antes de mandarse**, y el índice único de `recordatorios_saldo` (`reserva_id`, `numero`) no deja anotar el mismo dos veces, ni siquiera si dos procesos corren a la vez.

Se anota antes y se manda después a propósito: si el mail falla (el servidor de correo caído, por ejemplo), queda en el log y no se reintenta en la misma corrida, así el huésped no recibe tres mails iguales por un error de red. El recordatorio siguiente le llega cuando pasen las horas.

## Qué le llega al huésped

Un mail con el nombre del alojamiento, las fechas, cuánto debe, **hasta qué día puede pagarlo y qué pasa si no lo paga**, y un botón a **Mis reservas**, donde paga con la misma billetera con la que pagó la seña. Lo arma `mail/RecordatorioSaldo.php`, que como todos los mails del proyecto **nunca lanza excepciones**: si falla, lo deja en el log y el script sigue con los demás.

Sobre la cancelación, el mail dice que se le devuelve la seña **menos la retención que indica la política de la unidad**, sin poner el número: el porcentaje y los importes exactos se los muestra la pantalla de cancelación (`GET /reservas/cancelacion`, del Rol 2), que es la que hace la cuenta sobre los pagos confirmados en wei (#32).

## Tabla

`recordatorios_saldo` (`20261105180000_crear_tabla_recordatorios_saldo.php`): una fila por recordatorio enviado, con la reserva, el número (1, 2, 3…) y cuándo se mandó, en UTC. No se borran: son el historial de lo que se le avisó al huésped, que es lo que respalda una cancelación por falta de pago.
