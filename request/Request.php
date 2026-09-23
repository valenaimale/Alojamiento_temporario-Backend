<?php

namespace App\Request;

use PAW\Core\Traits\Loggable; //incluyo loggable para 
//usar el trait

class Request{
    use Loggable;
    public function uri(){
        return parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    }
    public function method_http(){
        return $_SERVER['REQUEST_METHOD'];
    }
    public function route(){
        $this->logger->info(
                "Se recibio una request:",
                [
                    "Path"=>$this->uri(),
                    "Method"=>$this->method_http(),
                ]
            );       
        return $this->method_http(). "@" .$this->uri();
    }
}