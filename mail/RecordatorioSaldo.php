<?php

namespace App\Mail;

use App\Config\Config;
use App\Log\Log;

//Le avisa al huésped que le falta pagar el saldo de su reserva (RF-25).
//Lo manda scripts/enviar-recordatorios.php, con lo que le pasa App\Validacion\CobroDelSaldo.
//Regla: nunca lanza excepciones. Si el mail falla, queda en el log y el script sigue con los demás;
//el recordatorio ya quedó anotado, así que no se reintenta en la misma corrida.
class RecordatorioSaldo
{
    //Manda el recordatorio. $recordatorio es una fila de CobroDelSaldo::pendientes().
    //El último avisa que, si no paga, la reserva se cancela: antes no, para no asustar de entrada.
    public static function enviar(array $recordatorio): void
    {
        try {
            $huesped = $recordatorio['huesped'];
            $monto = self::pesos($recordatorio['debe']);
            $desde = self::dia($recordatorio['ingreso']);
            $hasta = self::dia($recordatorio['egreso']);

            $asunto = $recordatorio['ultimo']
                ? "Último aviso: falta pagar el saldo de tu reserva en {$recordatorio['unidad']}"
                : "Te falta pagar el saldo de tu reserva en {$recordatorio['unidad']}";

            $texto = "Tu estadía en {$recordatorio['unidad']}, del $desde al $hasta, tiene un saldo de $monto "
                . 'sin pagar. Podés pagarlo desde tus reservas, con la misma billetera con la que pagaste la seña.';
            $cierre = $recordatorio['ultimo']
                ? 'Es el último recordatorio que te mandamos: si el saldo sigue sin pagarse, la reserva se cancela.'
                : 'Si ya lo pagaste, ignorá este mail.';

            self::avisar($huesped, $asunto, $texto, $cierre);
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
    private static function avisar(array $huesped, string $asunto, string $texto, string $cierre): void
    {
        $nombre = htmlspecialchars($huesped['nombre'], ENT_QUOTES, 'UTF-8');
        $textoHtml = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
        $cierreHtml = htmlspecialchars($cierre, ENT_QUOTES, 'UTF-8');
        $enlace = Config::obtener('app.url_front') . '/vistas/reservas/mis-reservas.html';
        $boton = "<p><a href=\"{$enlace}\" style=\"display:inline-block;padding:12px 20px;border-radius:6px;background-color:#1f6f54;color:#ffffff;font-weight:bold;text-decoration:none\">Pagar el saldo</a></p>";

        $html = "<p>¡Hola, {$nombre}!</p><p>{$textoHtml}</p>{$boton}<p>{$cierreHtml}</p>";
        $textoPlano = "¡Hola, {$huesped['nombre']}!\n\n{$texto}\n\nTus reservas: {$enlace}\n\n{$cierre}";

        EnviadorMail::enviar($huesped['mail'], $huesped['nombre'], $asunto, $html, $textoPlano);
    }
}
