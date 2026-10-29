<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

//Datos de ejemplo para probar las Épicas 2 y 3 en desarrollo, con los personajes del proyecto:
//  - Marisa (propietaria), Cacho (administrador), Nadia (operadora) y Juan (huésped),
//    todos con la contraseña "clave-segura-1" y el mail ya verificado.
//  - Tres unidades de Marisa:
//      1. "Cabaña del Mar" (Las Grutas): publicada, de autogestión, con Nadia de operadora, temporadas, precios y un bloqueo.
//      2. "Departamento Costanera" (Las Grutas): en borrador, sin precios (le falta para publicarse).
//      3. "Casa del Bosque" (Mar de las Pampas): publicada y administrada por Cacho, con el 15 % de comisión.
//Las fechas son relativas a hoy, así los datos sirven cualquier día que se carguen.
//
//Se carga con:  vendor/bin/phinx seed:run -s DatosDeEjemplo
//SOLO PARA DESARROLLO: no corre en el CI ni en producción (las contraseñas son conocidas).
final class DatosDeEjemplo extends AbstractSeed
{
    private const CONTRASENIA = 'clave-segura-1';
    private const CBU = '2850590940090418135201';

    //Carga todos los datos. Si ya estaban cargados (existe Marisa), no hace nada.
    public function run(): void
    {
        if ($this->fetchRow("SELECT 1 FROM usuarios WHERE mail = 'marisa@ejemplo.local'")) {
            $this->getOutput()->writeln('Los datos de ejemplo ya estaban cargados: no se cambió nada.');
            return;
        }

        $marisa = $this->crearUsuario('Marisa Gómez', 'marisa@ejemplo.local', '30111222', 'propietario');
        $cacho = $this->crearUsuario('Cacho Fernández', 'cacho@ejemplo.local', '28111333', 'administrador');
        $nadia = $this->crearUsuario('Nadia Ruiz', 'nadia@ejemplo.local', '35111444', 'operador');
        $this->crearUsuario('Juan Pérez', 'juan@ejemplo.local', '40111555', 'huesped');

        $this->table('propietarios')->insert([
            'usuario_id' => $marisa, 'cobra_iva' => 0, 'cbu' => self::CBU, 'titular_cuenta' => 'Marisa Gómez',
        ])->saveData();
        $this->table('administradores')->insert([
            'usuario_id' => $cacho, 'cobra_iva' => 1, 'cuit' => '20301234563', 'razon_social' => 'Cacho Administraciones',
            'domicilio_fiscal' => 'Av. Costanera 450, Las Grutas', 'cbu' => self::CBU, 'alias' => 'cacho.admin', 'titular_cuenta' => 'Cacho Fernández',
        ])->saveData();

        //1. Publicada, de autogestión: temporada baja (mínimo 2 noches) y alta (mínimo 7), con precios y una semana bloqueada
        $cabania = $this->crearUnidad($marisa, 'Las Grutas', [
            'nombre' => 'Cabaña del Mar', 'direccion' => 'Calle 5 n.º 123', 'capacidad' => 4,
            'caracteristicas' => 'A dos cuadras de la playa. Dos dormitorios, parrilla y cochera.',
            'deposito_garantia' => 50000, 'estado_publicacion' => 'publicada', 'operador_id' => $nadia,
        ]);
        $this->crearTemporada($cabania, 'baja', 0, 59, 2);
        $this->crearTemporada($cabania, 'alta', 60, 239, 7);
        $this->cargarPrecios($cabania, 0, 59, 60000, $marisa);
        $this->cargarPrecios($cabania, 60, 239, 90000, $marisa);   //8 meses: para publicar hacen falta 6
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
            'deposito_garantia' => 80000, 'estado_publicacion' => 'publicada',
        ]);
        $this->crearTemporada($casa, 'media', 0, 239, 3);
        $this->cargarPrecios($casa, 0, 239, 80000, $marisa);
        $this->table('administraciones')->insert([
            'unidad_id' => $casa, 'administrador_id' => $cacho, 'porcentaje_comision' => 15,
        ])->saveData();
        $this->table('fotos_unidad')->insert([   //para publicarse necesita al menos una foto
            'unidad_id' => $casa, 'url' => 'https://res.cloudinary.com/ynclnyuh/image/upload/samples/landscapes/landscape-panorama.jpg', 'orden' => 0,
        ])->saveData();

        $this->getOutput()->writeln('Datos de ejemplo cargados: marisa, cacho, nadia y juan @ejemplo.local (contraseña: ' . self::CONTRASENIA . ').');
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

    //La fecha de dentro de $dias días (de Argentina), como "AAAA-MM-DD"
    private function fecha(int $dias): string
    {
        return (new \DateTimeImmutable("today +$dias days", new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
    }

    //El id que MySQL le asignó a la última fila insertada
    private function ultimoId(): int
    {
        return (int) $this->getAdapter()->getConnection()->lastInsertId();
    }
}
