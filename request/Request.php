<?php

namespace App\Request;

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
}