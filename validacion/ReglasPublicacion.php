<?php

namespace App\Validacion;

use App\Database\Conexion;

//Qué le falta a una unidad para poder publicarse (US3.1).
//La usan "Mis unidades" (para mostrar "sin publicar y por qué"), la página central de cada unidad
//y la acción de publicar: así la regla está escrita una sola vez.
//Las reglas se exigen al publicar: una unidad ya publicada no se despublica sola si deja de cumplirlas
//(por ejemplo, porque con el paso del tiempo le quedan menos meses con precio). Para eso, la página de la unidad
//muestra hasta cuándo tiene precios (preciosHasta).
class ReglasPublicacion
{
    private const MAXIMO_NOCHES_A_MIRAR = 730;   //preciosHasta mira como mucho dos años hacia adelante

    //Devuelve la lista de lo que falta, con un mensaje para el propietario por cada cosa. Vacía = se puede publicar.
    //  - Precios (decidido el 29/10, reemplaza a RN-01): cada noche desde mañana hasta dentro de N meses
    //    (parámetro meses_precios_para_publicar) tiene precio vigente o está bloqueada. Una noche bloqueada
    //    no necesita precio: el propietario no la va a alquilar.
    //  - Al menos una foto (decidido el 28/10): en la búsqueda, una unidad sin foto genera desconfianza.
    public static function faltantes(int $unidadId): array
    {
        $faltantes = [];

        [$desde, $hasta] = self::periodoExigido();
        $cubiertas = self::nochesCubiertas($unidadId, $desde, $hasta);
        $sinPrecio = ValidadorFechas::noches($desde, $hasta) + 1 - count($cubiertas);//+1: las dos fechas son noches
        if ($sinPrecio > 0) {
            $faltantes[] = sprintf(
                'Cargá el precio de todas las noches hasta el %s (%s)',
                $hasta->format('d/m/Y'),
                $sinPrecio === 1 ? 'te falta 1' : "te faltan $sinPrecio"
            );
        }

        $consulta = Conexion::obtener()->prepare('SELECT 1 FROM fotos_unidad WHERE unidad_id = ? LIMIT 1');
        $consulta->execute([$unidadId]);
        if (!$consulta->fetchColumn()) {
            $faltantes[] = 'Subí al menos una foto';
        }

        return $faltantes;
    }

    //Hasta qué noche ("AAAA-MM-DD") la unidad tiene precio (o bloqueo) sin cortes desde mañana.
    //null si la noche de mañana no tiene precio ni está bloqueada. Lo muestra la página de la unidad:
    //"Tenés precios cargados hasta el 15/03/2027".
    public static function preciosHasta(int $unidadId): ?string
    {
        $manana = ValidadorFechas::hoy()->modify('+1 day');
        $cubiertas = self::nochesCubiertas($unidadId, $manana, $manana->modify('+' . (self::MAXIMO_NOCHES_A_MIRAR - 1) . ' days'));

        $ultima = null;
        for ($noche = $manana; isset($cubiertas[$noche->format('Y-m-d')]); $noche = $noche->modify('+1 day')) {
            $ultima = $noche->format('Y-m-d');
        }
        return $ultima;
    }

    //Las noches que se exigen para publicar: [mañana, dentro de N meses], las dos incluidas.
    //Desde mañana porque hoy ya no se puede reservar.
    private static function periodoExigido(): array
    {
        $consulta = Conexion::obtener()->prepare("SELECT valor FROM parametros WHERE clave = 'meses_precios_para_publicar'");
        $consulta->execute();
        $meses = (int) $consulta->fetchColumn();

        $hoy = ValidadorFechas::hoy();//la fecha de hoy de Argentina, no la de MySQL
        return [$hoy->modify('+1 day'), $hoy->modify("+$meses months")];
    }

    //Las noches del rango que tienen precio vigente o están bloqueadas, como ["AAAA-MM-DD" => true, ...]
    private static function nochesCubiertas(int $unidadId, \DateTimeImmutable $desde, \DateTimeImmutable $hasta): array
    {
        $pdo = Conexion::obtener();
        $rango = [$unidadId, $desde->format('Y-m-d'), $hasta->format('Y-m-d')];

        $consulta = $pdo->prepare(
            'SELECT fecha FROM precio_noche WHERE unidad_id = ? AND fecha BETWEEN ? AND ? AND vigente_hasta IS NULL'
        );
        $consulta->execute($rango);
        $cubiertas = array_fill_keys($consulta->fetchAll(\PDO::FETCH_COLUMN), true);

        $consulta = $pdo->prepare(
            'SELECT fecha_desde, fecha_hasta FROM bloqueos_calendario WHERE unidad_id = ? AND fecha_hasta >= ? AND fecha_desde <= ?'
        );
        $consulta->execute([$unidadId, $desde->format('Y-m-d'), $hasta->format('Y-m-d')]);
        foreach ($consulta->fetchAll() as $bloqueo) {
            $inicio = max($desde, ValidadorFechas::fecha($bloqueo['fecha_desde']));
            $fin = min($hasta, ValidadorFechas::fecha($bloqueo['fecha_hasta']));
            for ($noche = $inicio; $noche <= $fin; $noche = $noche->modify('+1 day')) {
                $cubiertas[$noche->format('Y-m-d')] = true;
            }
        }
        return $cubiertas;
    }
}
