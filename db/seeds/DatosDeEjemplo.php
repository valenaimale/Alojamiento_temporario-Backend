<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

//Datos de ejemplo para probar en desarrollo el circuito completo de una reserva, con los personajes del proyecto:
//  - Dos propietarias, porque la liquidación se calcula distinto según la condición fiscal (RN-24):
//    Marisa no discrimina IVA (las comisiones van sobre el total) y Elsa sí (van sobre el neto sin IVA).
//  - Cacho (administrador), Nadia (operadora) y dos huéspedes: Juan, que repite, y Lucía.
//    Todos con la contraseña "clave-segura-1" y el mail ya verificado.
//  - Cuatro unidades:
//      1. "Cabaña del Mar" (Marisa, Las Grutas): publicada, de autogestión, con Nadia de operadora,
//         temporada baja y alta, precios, fotos y una semana bloqueada.
//      2. "Departamento Costanera" (Marisa, Las Grutas): en borrador, sin precios (le falta para publicarse).
//      3. "Casa del Bosque" (Marisa, Mar de las Pampas): publicada y administrada por Cacho, con el 15 % de comisión.
//      4. "Loft del Centro" (Elsa, Villa Gesell): publicada y administrada por Cacho, con el 10 %.
//  - Seis reservas, una por cada estado que existe hoy: pendiente, confirmada, tres finalizadas y cancelada.
//    Las finalizadas tienen su estadía registrada, su libro de huéspedes y la seña y el saldo pagados,
//    así hay algo para liquidar (RF-09, RF-13 y RF-19); la confirmada es la que levantan los recordatorios (RF-25).
//Las fechas son relativas a hoy, así los datos sirven cualquier día que se carguen. Las temporadas y los precios
//van de 120 días atrás a 239 adelante, para que las estadías ya terminadas caigan dentro de una temporada
//con precio cargado y el importe de cada reserva salga de esos precios, como cuando reserva un huésped.
//
//Se carga con:  vendor/bin/phinx seed:run -s DatosDeEjemplo
//SOLO PARA DESARROLLO: no corre en el CI ni en producción (las contraseñas son conocidas y los pagos son inventados).
final class DatosDeEjemplo extends AbstractSeed
{
    private const CONTRASENIA = 'clave-segura-1';
    private const CBU = '2850590940090418135201';
    private const WEI_POR_ETH = '1000000000000000000';

    //Los pagos de ejemplo se guardan con el chain id de Anvil (la red local de Foundry), nunca con el de
    //Base Sepolia (84532): así un pago inventado nunca se confunde con uno real. Es el mismo criterio
    //de validacion/VerificadorSimulado.php.
    private const RED = 31337;

    private string $pesosPorEth = '100000000';   //se lee del parámetro pesos_por_eth al cargar los datos
    private int $diasCobroSaldo = 5;             //se lee del parámetro dias_cobro_saldo al cargar los datos
    private int $pagosCreados = 0;               //para que cada pago tenga un hash de transacción distinto

    //Carga todos los datos. Si ya estaban cargados (existe Marisa), no hace nada.
    public function run(): void
    {
        if ($this->fetchRow("SELECT 1 FROM usuarios WHERE mail = 'marisa@ejemplo.local'")) {
            $this->getOutput()->writeln('Los datos de ejemplo ya estaban cargados: no se cambió nada.');
            return;
        }
        $this->pesosPorEth = (string) $this->fetchRow("SELECT valor FROM parametros WHERE clave = 'pesos_por_eth'")['valor'];
        $this->diasCobroSaldo = (int) $this->fetchRow("SELECT valor FROM parametros WHERE clave = 'dias_cobro_saldo'")['valor'];

        $marisa = $this->crearUsuario('Marisa Gómez', 'marisa@ejemplo.local', '30111222', 'propietario');
        $elsa = $this->crearUsuario('Elsa Quiroga', 'elsa@ejemplo.local', '27234567', 'propietario');
        $cacho = $this->crearUsuario('Cacho Fernández', 'cacho@ejemplo.local', '28111333', 'administrador');
        $nadia = $this->crearUsuario('Nadia Ruiz', 'nadia@ejemplo.local', '35111444', 'operador');
        $juan = $this->crearUsuario('Juan Pérez', 'juan@ejemplo.local', '40111555', 'huesped');
        $lucia = $this->crearUsuario('Lucía Sosa', 'lucia@ejemplo.local', '38111666', 'huesped');

        //Las billeteras de cobro son las cuentas 1, 2 y 3 de Anvil (la red local de Foundry): públicas y sin valor
        //Marisa es monotributista: no discrimina IVA, así que las comisiones se calculan sobre el total (RN-24)
        $this->table('propietarios')->insert([
            'usuario_id' => $marisa, 'cobra_iva' => 0, 'cbu' => self::CBU, 'titular_cuenta' => 'Marisa Gómez',
            'billetera' => '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
        ])->saveData();
        //Elsa es responsable inscripta: discrimina IVA, así que las comisiones se calculan sobre el neto sin IVA
        $this->table('propietarios')->insert([
            'usuario_id' => $elsa, 'cobra_iva' => 1, 'cuit' => '27234567891', 'razon_social' => 'Elsa Quiroga',
            'domicilio_fiscal' => 'Av. 3 n.º 1250, Villa Gesell', 'cbu' => self::CBU, 'alias' => 'elsa.gesell',
            'titular_cuenta' => 'Elsa Quiroga', 'billetera' => '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
        ])->saveData();
        $this->table('administradores')->insert([
            'usuario_id' => $cacho, 'cobra_iva' => 1, 'cuit' => '20301234563', 'razon_social' => 'Cacho Administraciones',
            'domicilio_fiscal' => 'Av. Costanera 450, Las Grutas', 'cbu' => self::CBU, 'alias' => 'cacho.admin', 'titular_cuenta' => 'Cacho Fernández',
            'billetera' => '0x90f79bf6eb2c4f870365e785982e1f101e93b906',
        ])->saveData();

        //1. Publicada, de autogestión: temporada baja (mínimo 2 noches) y alta (mínimo 7), con precios y una semana bloqueada
        $cabania = $this->crearUnidad($marisa, 'Las Grutas', [
            'nombre' => 'Cabaña del Mar', 'direccion' => 'Calle 5 n.º 123', 'capacidad' => 4,
            'caracteristicas' => 'A dos cuadras de la playa. Dos dormitorios, parrilla y cochera.',
            'deposito_garantia' => 50000, 'estado_publicacion' => 'publicada', 'operador_id' => $nadia,
        ]);
        $this->crearTemporada($cabania, 'baja', -120, 59, 2);
        $this->crearTemporada($cabania, 'alta', 60, 239, 7);
        $this->cargarPrecios($cabania, -120, 59, 60000, $marisa);
        $this->cargarPrecios($cabania, 60, 239, 90000, $marisa);   //8 meses para adelante: para publicar hacen falta 6
        $this->table('bloqueos_calendario')->insert([
            'unidad_id' => $cabania, 'fecha_desde' => $this->fecha(20), 'fecha_hasta' => $this->fecha(26),
            'motivo' => 'reserva_externa', 'detalle' => 'Reservada por Airbnb', 'creado_por' => $marisa,
        ])->saveData();
        $this->table('fotos_unidad')->insert([
            ['unidad_id' => $cabania, 'url' => 'https://res.cloudinary.com/ynclnyuh/image/upload/samples/landscapes/beach-boat.jpg', 'orden' => 0],
            ['unidad_id' => $cabania, 'url' => 'https://res.cloudinary.com/ynclnyuh/image/upload/samples/landscapes/nature-mountains.jpg', 'orden' => 1],
        ])->saveData();

        //2. En borrador y sin precios: "sin publicar" porque le falta el precio
        $this->crearUnidad($marisa, 'Las Grutas', [
            'nombre' => 'Departamento Costanera', 'direccion' => 'Av. Costanera 80, piso 2', 'capacidad' => 2,
        ]);

        //3. Publicada y administrada por Cacho, con una sola temporada media (mínimo 3 noches)
        $casa = $this->crearUnidad($marisa, 'Mar de las Pampas', [
            'nombre' => 'Casa del Bosque', 'direccion' => 'Santiago Ramón y Cajal 300', 'capacidad' => 6,
            'caracteristicas' => 'Entre los pinos, a 400 metros del mar. Tres dormitorios.',
            'deposito_garantia' => 80000, 'estado_publicacion' => 'publicada', 'operador_id' => $nadia,
        ]);
        $this->crearTemporada($casa, 'media', -120, 239, 3);
        $this->cargarPrecios($casa, -120, 239, 80000, $marisa);
        $this->administrar($casa, $cacho, 15);
        $this->table('fotos_unidad')->insert([   //para publicarse necesita al menos una foto
            'unidad_id' => $casa, 'url' => 'https://res.cloudinary.com/ynclnyuh/image/upload/samples/landscapes/landscape-panorama.jpg', 'orden' => 0,
        ])->saveData();

        //4. La unidad de Elsa, la propietaria que discrimina IVA, también administrada por Cacho
        $loft = $this->crearUnidad($elsa, 'Villa Gesell', [
            'nombre' => 'Loft del Centro', 'direccion' => 'Av. 3 n.º 1250', 'capacidad' => 4,
            'caracteristicas' => 'A una cuadra de la peatonal. Monoambiente amplio con balcón.',
            'deposito_garantia' => 60000, 'estado_publicacion' => 'publicada', 'operador_id' => $nadia,
        ]);
        $this->crearTemporada($loft, 'media', -120, 239, 2);
        $this->cargarPrecios($loft, -120, 239, 85000, $elsa);
        $this->administrar($loft, $cacho, 10);
        $this->table('fotos_unidad')->insert([
            'unidad_id' => $loft, 'url' => 'https://res.cloudinary.com/ynclnyuh/image/upload/samples/landscapes/girl-urban-view.jpg', 'orden' => 0,
        ])->saveData();

        $this->cargarReservas($cabania, $casa, $loft, $juan, $lucia, $nadia);

        $this->getOutput()->writeln(
            'Datos de ejemplo cargados: marisa, elsa, cacho, nadia, juan y lucia @ejemplo.local '
            . '(contraseña: ' . self::CONTRASENIA . '). 6 reservas: 1 pendiente, 1 confirmada, 3 finalizadas y 1 cancelada.'
        );
    }

    //Las seis reservas, una por cada estado. Las tres finalizadas quedan listas para liquidar: con la seña y el
    //saldo pagados, la estadía registrada por la operadora y el libro de huéspedes completo.
    private function cargarReservas(int $cabania, int $casa, int $loft, int $juan, int $lucia, int $nadia): void
    {
        //Pendiente: reservó y todavía no pagó la seña, así que esas noches siguen ocupadas mientras paga
        $this->crearReserva($cabania, $juan, 'pendiente', 40, 45, 3);

        //Confirmada: pagó la seña y le queda el saldo. Ingresa en 3 días, así que ya pasó la fecha de cobro
        //(dias_cobro_saldo son 5): es la que levantan los recordatorios del saldo (RF-25) apenas se corre el script
        $proxima = $this->crearReserva($casa, $lucia, 'confirmada', 3, 8, 4);
        $this->pagar($proxima, $lucia, 'sena', $proxima['sena'], $proxima['reservada_en']);

        //Finalizada de una unidad de autogestión: la liquidación no tiene comisión de administrador
        $terminada = $this->crearReserva($cabania, $juan, 'finalizada', -12, -7, 4);
        $this->pagarTodo($terminada, $juan);
        $this->registrarEstadia($terminada, $nadia, 'La cabaña estaba impecable.', 'Entregó las llaves en horario.');
        $this->cargarLibro($terminada['id'], [
            ['nombre' => 'Juan Pérez', 'documento' => '40111555', 'nacimiento' => '1995-03-14', 'domicilio' => 'Rivadavia 1200, Luján'],
            ['nombre' => 'Carla Medina', 'documento' => '41222666', 'nacimiento' => '1996-11-02', 'domicilio' => 'Rivadavia 1200, Luján'],
        ]);

        //Finalizada de una unidad administrada por Cacho, de una propietaria que no discrimina IVA
        $conAdministrador = $this->crearReserva($casa, $juan, 'finalizada', -20, -15, 5);
        $this->pagarTodo($conAdministrador, $juan);
        $this->registrarEstadia($conAdministrador, $nadia, 'Sin novedades.', 'Dejó la casa ordenada.');
        $this->cargarLibro($conAdministrador['id'], [
            ['nombre' => 'Juan Pérez', 'documento' => '40111555', 'nacimiento' => '1995-03-14', 'domicilio' => 'Rivadavia 1200, Luján'],
            ['nombre' => 'Ramiro Pérez', 'documento' => '12333777', 'nacimiento' => '1962-07-21', 'domicilio' => 'Rivadavia 1200, Luján'],
            ['nombre' => 'Silvia Arce', 'documento' => '13444888', 'nacimiento' => '1964-01-09', 'domicilio' => 'Rivadavia 1200, Luján'],
        ]);

        //Finalizada de la propietaria que discrimina IVA: la liquidación lleva IVA y comisión de administrador
        $conIva = $this->crearReserva($loft, $lucia, 'finalizada', -9, -4, 2);
        $this->pagarTodo($conIva, $lucia);
        $this->registrarEstadia($conIva, $nadia, 'Faltaba una toalla, se reemplazó.', 'Salida anticipada, sin inconvenientes.');
        $this->cargarLibro($conIva['id'], [
            ['nombre' => 'Lucía Sosa', 'documento' => '38111666', 'nacimiento' => '1993-06-30', 'domicilio' => 'San Martín 450, Mercedes'],
            ['nombre' => 'Diego Vera', 'documento' => '37555999', 'nacimiento' => '1992-09-17', 'domicilio' => 'San Martín 450, Mercedes'],
        ]);

        //Cancelada: canceló antes de pagar la seña, así que no hay nada que devolver y las noches quedaron libres
        $this->crearReserva($cabania, $lucia, 'cancelada', 60, 67, 2);
    }

    //Crea un usuario con el mail ya verificado y devuelve su id
    private function crearUsuario(string $nombre, string $mail, string $dni, string $rol): int
    {
        $this->table('usuarios')->insert([
            'nombre' => $nombre, 'mail' => $mail, 'dni' => $dni, 'rol' => $rol,
            'contrasenia' => password_hash(self::CONTRASENIA, PASSWORD_DEFAULT),
            'mail_verificado_en' => date('Y-m-d H:i:s'),
        ])->saveData();
        return $this->ultimoId();
    }

    //Crea una unidad de la propietaria en esa localidad, con la seña y la política por defecto, y devuelve su id
    private function crearUnidad(int $propietarioId, string $localidad, array $datos): int
    {
        $localidadId = (int) $this->fetchRow("SELECT id FROM localidades WHERE nombre = '$localidad'")['id'];
        $this->table('unidades')->insert(array_merge([
            'propietario_id' => $propietarioId, 'localidad_id' => $localidadId,
            'porcentaje_sena' => 20, 'retencion_mas_de_30' => 30, 'retencion_de_15_a_30' => 50, 'retencion_menos_de_15' => 70,
        ], $datos))->saveData();
        return $this->ultimoId();
    }

    //Le pone administrador a la unidad, con su % de comisión (RN-05)
    private function administrar(int $unidadId, int $administradorId, int $comision): void
    {
        $this->table('administraciones')->insert([
            'unidad_id' => $unidadId, 'administrador_id' => $administradorId, 'porcentaje_comision' => $comision,
        ])->saveData();
    }

    //Una temporada desde dentro de $desde días hasta dentro de $hasta días (noches incluidas)
    private function crearTemporada(int $unidadId, string $nombre, int $desde, int $hasta, int $minimoNoches): void
    {
        $this->table('temporadas')->insert([
            'unidad_id' => $unidadId, 'nombre' => $nombre, 'fecha_inicio' => $this->fecha($desde),
            'fecha_fin' => $this->fecha($hasta), 'minimo_noches' => $minimoNoches,
        ])->saveData();
    }

    //El mismo precio para cada noche entre dentro de $desde días y dentro de $hasta días, vigente desde ahora
    private function cargarPrecios(int $unidadId, int $desde, int $hasta, float $precio, int $usuarioId): void
    {
        $ahora = date('Y-m-d H:i:s');
        $filas = [];
        for ($dia = $desde; $dia <= $hasta; $dia++) {
            $filas[] = ['unidad_id' => $unidadId, 'fecha' => $this->fecha($dia), 'vigente_desde' => $ahora, 'precio' => $precio, 'creado_por' => $usuarioId];
        }
        $this->table('precio_noche')->insert($filas)->saveData();
    }

    //Crea una reserva con la foto de las condiciones de la unidad, igual que ReservasController al reservar:
    //el importe sale de los precios cargados de esas noches, y la seña y la política se copian de la unidad.
    //$desde es la primera noche y $hasta el día de egreso (que no es una noche), los dos en días desde hoy.
    //Devuelve los datos que necesitan los pagos y la estadía.
    private function crearReserva(int $unidadId, int $huespedId, string $estado, int $desde, int $hasta, int $personas): array
    {
        $unidad = $this->fetchRow(
            "SELECT porcentaje_sena, retencion_mas_de_30, retencion_de_15_a_30, retencion_menos_de_15
             FROM unidades WHERE id = $unidadId"
        );
        $ingreso = $this->fecha($desde);
        $egreso = $this->fecha($hasta);
        $total = (string) $this->fetchRow(
            "SELECT COALESCE(SUM(precio), 0) AS total FROM precio_noche
             WHERE unidad_id = $unidadId AND fecha >= '$ingreso' AND fecha < '$egreso'"
        )['total'];
        $sena = $this->importe((float) $total * (float) $unidad['porcentaje_sena'] / 100);
        //reservó un mes antes del ingreso, o ayer si falta menos de un mes: nadie reserva en el futuro
        $reservadaEn = $this->momento(min($desde - 30, -1), '19:40:00');

        //Las dos columnas del cobro del saldo, igual que las completa el Rol 2 al reservar:
        //lo que falta pagar después de la seña, y el día en que se le empieza a pedir (dias_cobro_saldo antes
        //del ingreso, o el día de la reserva si reservó con menos anticipación). Sin ellas, los recordatorios
        //del saldo y los tramos de la política de cancelación no tienen de dónde leer las fechas.
        $saldo = $this->importe((float) $total - (float) $sena);
        $fechaCobro = max($this->fecha($desde - $this->diasCobroSaldo), substr($reservadaEn, 0, 10));

        $this->table('reservas')->insert([
            'unidad_id' => $unidadId, 'huesped_id' => $huespedId, 'estado' => $estado,
            'fecha_ingreso' => $ingreso, 'fecha_egreso' => $egreso, 'cantidad_personas' => $personas,
            'importe_total' => $total, 'porcentaje_sena' => $unidad['porcentaje_sena'], 'importe_sena' => $sena,
            'monto_faltante' => $saldo, 'fecha_cobro' => $fechaCobro,
            'retencion_mas_de_30' => $unidad['retencion_mas_de_30'],
            'retencion_de_15_a_30' => $unidad['retencion_de_15_a_30'],
            'retencion_menos_de_15' => $unidad['retencion_menos_de_15'],
            'politica_aceptada_en' => $reservadaEn, 'creado_en' => $reservadaEn,
        ])->saveData();

        return [
            'id' => $this->ultimoId(), 'desde' => $desde, 'hasta' => $hasta,
            'total' => $total, 'sena' => $sena, 'saldo' => $saldo,
            'reservada_en' => $reservadaEn,
        ];
    }

    //La seña al reservar y el saldo cinco días antes del ingreso (el parámetro dias_cobro_saldo)
    private function pagarTodo(array $reserva, int $huespedId): void
    {
        $this->pagar($reserva, $huespedId, 'sena', $reserva['sena'], $reserva['reservada_en']);
        $this->pagar($reserva, $huespedId, 'saldo', $reserva['saldo'], $this->momento(min($reserva['desde'] - 5, -1), '11:15:00'));
    }

    //Guarda un pago ya confirmado, como lo dejaría PagosController después de verificar la transacción en la red.
    //La billetera y el hash son inventados, pero con el formato que exigen los CHECK de la tabla.
    private function pagar(array $reserva, int $huespedId, string $tipo, string $pesos, string $momento): void
    {
        $this->pagosCreados++;
        $this->table('pagos')->insert([
            'reserva_id' => $reserva['id'], 'tipo' => $tipo,
            'monto_pesos' => $pesos, 'monto_wei' => $this->aWei($pesos), 'pesos_por_eth' => $this->pesosPorEth,
            'billetera' => $this->billetera($huespedId), 'red' => self::RED,
            'tx_hash' => '0x' . sprintf('%064x', $this->pagosCreados),
            'estado' => 'confirmado', 'creado_en' => $momento, 'confirmado_en' => $momento,
        ])->saveData();

        //Pagado el saldo, no queda nada por pagar: es lo que hace el back al confirmar el pago (decisión #20
        //de docs/pagos-decisiones.md), y de ahí leen el saldo impago los recordatorios y las liquidaciones.
        if ($tipo === 'saldo') {
            $this->execute("UPDATE reservas SET monto_faltante = 0 WHERE id = {$reserva['id']}");
        }
    }

    //Registra el ingreso y el egreso de una estadía que ya pasó, como los habría cargado la operadora
    //desde su agenda. Las horas son las de Argentina; en la base se guardan en UTC.
    private function registrarEstadia(array $reserva, int $operadorId, string $estadoUnidad, string $notas): void
    {
        $this->table('estadias')->insert([
            'reserva_id' => $reserva['id'],
            'ingreso_en' => $this->momento($reserva['desde'], '15:00:00'),
            'ingreso_registrado_en' => $this->momento($reserva['desde'], '15:10:00'),
            'ingreso_operador_id' => $operadorId,
            'ingreso_evento_id' => $this->identificadorDeEvento('ingreso-' . $reserva['id']),
            'estado_ingreso' => $estadoUnidad,
            'notas_ingreso' => 'Entregamos las llaves y el instructivo de la unidad.',
            'egreso_en' => $this->momento($reserva['hasta'], '10:00:00'),
            'egreso_registrado_en' => $this->momento($reserva['hasta'], '10:10:00'),
            'egreso_operador_id' => $operadorId,
            'egreso_evento_id' => $this->identificadorDeEvento('egreso-' . $reserva['id']),
            'estado_egreso' => $estadoUnidad,
            'notas_egreso' => $notas,
        ])->saveData();
    }

    //Carga el libro de huéspedes de la reserva: el titular primero (orden 0) y después los acompañantes
    private function cargarLibro(int $reservaId, array $personas): void
    {
        $filas = [];
        foreach ($personas as $orden => $persona) {
            $filas[] = [
                'reserva_id' => $reservaId, 'orden' => $orden, 'es_titular' => $orden === 0 ? 1 : 0,
                'nombre' => $persona['nombre'], 'tipo_documento' => 'DNI', 'documento' => $persona['documento'],
                'nacionalidad' => 'Argentina', 'fecha_nacimiento' => $persona['nacimiento'], 'domicilio' => $persona['domicilio'],
            ];
        }
        $this->table('huespedes_reserva')->insert($filas)->saveData();
        //el libro se guardó una vez: la próxima edición desde el front tiene que mandar la versión 1
        $this->execute("UPDATE reservas SET version_huespedes = 1 WHERE id = $reservaId");
    }

    //La billetera de prueba de un usuario: inventada pero siempre la misma, con el formato 0x + 40 hexadecimales
    private function billetera(int $usuarioId): string
    {
        return '0x' . substr(hash('sha256', "billetera-de-ejemplo-$usuarioId"), 0, 40);
    }

    //Un identificador de movimiento con la forma de un UUID, el formato que valida EstadiasController
    private function identificadorDeEvento(string $semilla): string
    {
        $hex = substr(hash('sha256', "evento-de-ejemplo-$semilla"), 0, 32);
        return implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }

    //Pesos a wei, la misma cuenta que validacion/TipoDeCambio.php: con bcmath, porque un monto en wei
    //no entra en un entero de PHP. 1 ETH = 10^18 wei.
    private function aWei(string $pesos): string
    {
        return bcdiv(bcmul($pesos, self::WEI_POR_ETH, 2), $this->pesosPorEth, 0);
    }

    //Un importe con dos decimales y punto, como lo guarda la base ("300000.00")
    private function importe(float $monto): string
    {
        return number_format(round($monto, 2), 2, '.', '');
    }

    //La fecha de dentro de $dias días (de Argentina), como "AAAA-MM-DD"
    private function fecha(int $dias): string
    {
        return (new \DateTimeImmutable("today +$dias days", new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
    }

    //Ese día a esa hora de Argentina, pasado a UTC ("AAAA-MM-DD HH:MM:SS"), como se guardan los momentos en la base
    private function momento(int $dias, string $hora): string
    {
        return (new \DateTimeImmutable($this->fecha($dias) . " $hora", new \DateTimeZone('America/Argentina/Buenos_Aires')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    //El id que MySQL le asignó a la última fila insertada
    private function ultimoId(): int
    {
        return (int) $this->getAdapter()->getConnection()->lastInsertId();
    }
}
