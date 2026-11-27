<?php

namespace App\Request;

use App\Exceptions\DatosInvalidosException;

//Los datos de la petición que llega: la ruta (para el Router), el cuerpo JSON y los parámetros de la URL,
//controlando que tengan la forma esperada.
class Request{
    //La ruta pedida, sin el query string: "/backoffice/usuario?id=5" → "/backoffice/usuario"
    public function uri(){
        return parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    }
    //El método HTTP: GET, POST, OPTIONS...
    public function method_http(){
        return $_SERVER['REQUEST_METHOD'];
    }
    //La clave con la que el Router busca la ruta: "POST@/iniciar-sesion"
    public function route(){
        return $this->method_http(). "@" .$this->uri();
    }

    //Lee el cuerpo JSON de la petición. Los controladores lo usan en lugar de json_decode(file_get_contents(...)).
    public static function datosJson(array $listasPermitidas = []): array{
        return self::decodificarJson(file_get_contents('php://input'), $listasPermitidas);
    }

    //Pasa el cuerpo de la petición a un array asociativo, controlando que tenga la forma esperada:
    //  - sin cuerpo: devuelve [] (cada controlador responde que faltan los campos obligatorios);
    //  - si no es un objeto JSON, o un campo trae una lista u objeto donde se espera un valor simple
    //    (texto, número, true/false), lanza DatosInvalidosException y el router responde 422.
    //Las listas se habilitan por nombre explícito; su controlador valida la estructura interna.
    //Sin este control, un campo como "nombre": ["Ana"] llegaba a trim() y terminaba en un error 500.
    public static function decodificarJson(string $cuerpo, array $listasPermitidas = []): array{
        if(trim($cuerpo) === ''){
            return [];
        }
        $datos = json_decode($cuerpo, true);
        //{} se decodifica como [] (vacío): se acepta. Una lista como [1,2,3] no es un objeto.
        if(!is_array($datos) || ($datos !== [] && array_is_list($datos))){
            throw new DatosInvalidosException('El cuerpo de la petición no es un objeto JSON');
        }
        foreach($datos as $campo => $valor){
            if(is_array($valor) && !in_array($campo, $listasPermitidas, true)){
                throw new DatosInvalidosException("El campo '$campo' no es un valor simple");
            }
        }
        return $datos;
    }

    //Un parámetro de la URL (?buscar=...) como texto. Con ?buscar[]=x PHP arma una lista: se rechaza igual que arriba.
    public static function parametroGet(string $nombre): string{
        $valor = $_GET[$nombre] ?? '';
        if(is_array($valor)){
            throw new DatosInvalidosException("El parámetro '$nombre' no es un valor simple");
        }
        return $valor;
    }

    //Un número entero de lo que mandó el front (un campo del cuerpo JSON o un parámetro de la URL), entre $minimo y
    //$maximo, o null si no lo es. Es la única forma de leer un id o una cantidad: antes cada controlador lo validaba
    //a su manera y en algunos se colaban valores que no son números.
    //Acepta 5 y "5". Rechaza true/false (filter_var tomaría true como 1), listas, null, decimales como 3.5 y textos
    //como "5abc" (un (int) los convertiría en 5).
    public static function entero(mixed $valor, int $minimo = 1, ?int $maximo = null): ?int
    {
        if ($valor === null || is_bool($valor) || is_array($valor) || is_object($valor)) {
            return null;
        }
        $opciones = ['min_range' => $minimo] + ($maximo === null ? [] : ['max_range' => $maximo]);
        $entero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => $opciones]);
        return $entero === false ? null : $entero;
    }

    //Un parámetro de la URL como entero (Request::entero), o null si falta o no es un número válido:
    //?id=5 → 5; ?id=5abc, ?id=0 o ?id[]=5 → null
    public static function enteroDeLaUrl(string $nombre, int $minimo = 1, ?int $maximo = null): ?int
    {
        $valor = $_GET[$nombre] ?? null;
        return is_string($valor) ? self::entero($valor, $minimo, $maximo) : null;
    }
}
