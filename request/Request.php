<?php

namespace App\Request;

use App\Exceptions\DatosInvalidosException;

class Request{
    public function uri(){
        return parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    }
    public function method_http(){
        return $_SERVER['REQUEST_METHOD'];
    }
    public function route(){
        return $this->method_http(). "@" .$this->uri();
    }

    //Lee el cuerpo JSON de la petición. Los controladores lo usan en lugar de json_decode(file_get_contents(...)).
    public static function datosJson(): array{
        return self::decodificarJson(file_get_contents('php://input'));
    }

    //Pasa el cuerpo de la petición a un array asociativo, controlando que tenga la forma esperada:
    //  - sin cuerpo: devuelve [] (cada controlador responde que faltan los campos obligatorios);
    //  - si no es un objeto JSON, o un campo trae una lista u objeto donde se espera un valor simple
    //    (texto, número, true/false), lanza DatosInvalidosException y el router responde 422.
    //Sin este control, un campo como "nombre": ["Ana"] llegaba a trim() y terminaba en un error 500.
    public static function decodificarJson(string $cuerpo): array{
        if(trim($cuerpo) === ''){
            return [];
        }
        $datos = json_decode($cuerpo, true);
        //{} se decodifica como [] (vacío): se acepta. Una lista como [1,2,3] no es un objeto.
        if(!is_array($datos) || ($datos !== [] && array_is_list($datos))){
            throw new DatosInvalidosException('El cuerpo de la petición no es un objeto JSON');
        }
        foreach($datos as $campo => $valor){
            if(is_array($valor)){
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
}
