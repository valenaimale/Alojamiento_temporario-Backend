<?php

namespace App\Mail;

use App\Config\Config;
use App\Log\Log;

//Le avisa al huésped que le falta pagar el saldo de su reserva (RF-25).
//Lo manda scripts/enviar-recordatorios.php, con lo que le pasa App\Validacion\CobroDelSaldo.
//Regla: nunca lanza excepciones. Si el mail falla, queda en el log y el script sigue con los demás;
//el recordatorio ya quedó anotado, así que no se reintenta en la misma corrida.
//
//TODOS los recordatorios dicen hasta qué día se puede pagar y qué pasa si no se paga (decisión #23 de
//docs/pagos-decisiones.md): pasado ese plazo la reserva se cancela sola, con la retención del tramo de
//menos de 15 días. No se avisa solo en el último: el huésped tiene que poder decidir desde el primero.
class RecordatorioSaldo
{
    //Manda el recordatorio. $recordatorio es una fila de CobroDelSaldo::pendientes().
    public static function enviar(array $recordatorio): void
    {
        try {
            $huesped = $recordatorio['huesped'];
            $monto = self::pesos($recordatorio['debe']);
            $desde = self::dia($recordatorio['ingreso']);
            $hasta = self::dia($recordatorio['egreso']);
            $limite = self::dia($recordatorio['limite']);
            $venceHoy = (bool) ($recordatorio['vence_hoy'] ?? false);

            $asunto = match (true) {
                $venceHoy                => "Hoy es el último día para pagar el saldo de tu reserva en {$recordatorio['unidad']}",
                $recordatorio['ultimo']  => "Último aviso: falta pagar el saldo de tu reserva en {$recordatorio['unidad']}",
                default                  => "Te falta pagar el saldo de tu reserva en {$recordatorio['unidad']}",
            };

            $texto = "Tu estadía en {$recordatorio['unidad']}, del $desde al $hasta, tiene un saldo de $monto "
                . 'sin pagar. Podés pagarlo desde tus reservas, con la misma billetera con la que pagaste la seña.';

            //El plazo y la consecuencia, en todos los recordatorios
            $plazo = $venceHoy
                ? "Hoy es el último día para pagarlo: si no lo pagás hoy, tu reserva se cancela."
                : "Tenés tiempo hasta el $limite: si no lo pagás antes de esa fecha, tu reserva se cancela.";
            $plazo .= ' Si se cancela, te devolvemos la seña menos la retención que indica la política de'
                . ' cancelación de la unidad (el tramo de menos de 15 días, porque ya estamos en la fecha de cobro).';

            $cierre = 'Si ya lo pagaste, ignorá este mail.';

            self::avisar($huesped, $asunto, $texto, $plazo, $cierre);
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el recordatorio del saldo', $e, ['reserva_id' => $recordatorio['reserva_id'] ?? null]);
        }
    }

    //"240000.00" → "$ 240.000,00"
    private static function pesos(string $monto): string
    {
        return '$ ' . number_format((float) $monto, 2, ',', '.');
    }

    //"2026-12-05" → "05/12/2026"
    private static function dia(string $fecha): string
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha)->format('d/m/Y');
    }

    //Arma el mail (HTML y texto plano) y lo manda.
    //El nombre de la unidad y el del huésped los escribieron personas: en el HTML van escapados.
    private static function avisar(array $huesped, string $asunto, string $texto, string $plazo, string $cierre): void
    {
        $nombre = htmlspecialchars($huesped['nombre'], ENT_QUOTES, 'UTF-8');
        $textoHtml = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
        $plazoHtml = htmlspecialchars($plazo, ENT_QUOTES, 'UTF-8');
        $cierreHtml = htmlspecialchars($cierre, ENT_QUOTES, 'UTF-8');
        $enlace = Config::obtener('app.url_front') . '/vistas/reservas/mis-reservas.html';
        $boton = "<p><a href=\"{$enlace}\" style=\"display:inline-block;padding:12px 20px;border-radius:6px;background-color:#1f6f54;color:#ffffff;font-weight:bold;text-decoration:none\">Pagar el saldo</a></p>";

        $html = "<p>¡Hola, {$nombre}!</p><p>{$textoHtml}</p><p><strong>{$plazoHtml}</strong></p>{$boton}<p>{$cierreHtml}</p>";
        $textoPlano = "¡Hola, {$huesped['nombre']}!\n\n{$texto}\n\n{$plazo}\n\nTus reservas: {$enlace}\n\n{$cierre}";

        EnviadorMail::enviar($huesped['mail'], $huesped['nombre'], $asunto, $html, $textoPlano);
    }
}
