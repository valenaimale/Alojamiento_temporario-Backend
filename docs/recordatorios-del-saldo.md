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
| El huésped **todavía no ingresó** (no tiene fila en `estadias`) | Si ya está adentro, el saldo se resuelve en el mostrador, no por mail |
| El ingreso es de hoy en adelante | Una estadía que ya pasó no se arregla con un mail |
| Lo cobrado (pagos `confirmado` de tipo `sena` y `saldo`) es menor que el total | Si ya pagó todo, no hay nada que recordarle |
| Ya llegó la fecha de cobro | `dias_cobro_saldo` antes del ingreso, o el día en que reservó si reservó con menos anticipación |
| Se le mandaron menos de `max_recordatorios_cobro` | Después del último deja de ser un olvido y pasa a ser una deuda (RN-16) |
| Pasaron `horas_entre_recordatorios` desde el anterior | Para no atosigarlo |

Los tres parámetros están en la tabla `parametros` y los administra el backoffice:

| Parámetro | Por defecto |
|---|---|
| `dias_cobro_saldo` | 5 |
| `max_recordatorios_cobro` | 3 |
| `horas_entre_recordatorios` | 24 |

El **último** recordatorio avisa que, si el saldo sigue sin pagarse, la reserva se cancela. Los anteriores no: no hace falta asustar de entrada.

## Cómo se corre

Desde la raíz del backend:

```bash
php scripts/enviar-recordatorios.php            # muestra a quién le escribiría, sin mandar nada
php scripts/enviar-recordatorios.php --enviar   # manda los mails
```

Pensado para un **cron diario** de Railway. Correrlo dos veces el mismo día no manda nada dos veces: cada recordatorio se **anota antes de mandarse**, y el índice único de `recordatorios_saldo` (`reserva_id`, `numero`) no deja anotar el mismo dos veces, ni siquiera si dos procesos corren a la vez.

Se anota antes y se manda después a propósito: si el mail falla (el servidor de correo caído, por ejemplo), queda en el log y no se reintenta en la misma corrida, así el huésped no recibe tres mails iguales por un error de red. El recordatorio siguiente le llega cuando pasen las horas.

## Qué le llega al huésped

Un mail con el nombre del alojamiento, las fechas, cuánto debe y un botón a **Mis reservas**, donde paga con la misma billetera con la que pagó la seña. Lo arma `mail/RecordatorioSaldo.php`, que como todos los mails del proyecto **nunca lanza excepciones**: si falla, lo deja en el log y el script sigue con los demás.

## Tabla

`recordatorios_saldo` (`20261105180000_crear_tabla_recordatorios_saldo.php`): una fila por recordatorio enviado, con la reserva, el número (1, 2, 3…) y cuándo se mandó, en UTC. No se borran: son el historial de lo que se le avisó al huésped, que es lo que respalda una cancelación por falta de pago.
