<?php

namespace Tests\Soporte;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

//Cliente HTTP para los tests: le habla al backend como el front (JSON y cookie de sesión).
//Cada ClienteApi es como un navegador distinto: tiene sus propias cookies, así que su propia sesión.
//Usa Guzzle, que ya viene instalado como dependencia de la librería de OAuth.
final class ClienteApi
{
    private Client $http;
    private CookieJar $cookies;

    public function __construct(?string $urlBase = null)
    {
        $this->cookies = new CookieJar();
        $this->http = new Client([
            'base_uri'        => $urlBase ?? EntornoDePrueba::urlApi(),
            'http_errors'     => false,//un 4xx o 5xx no es una excepción: los tests verifican el código
            'allow_redirects' => false,//las redirecciones (OAuth) se verifican, no se siguen
            'cookies'         => $this->cookies,
            'timeout'         => 30,
        ]);
    }

    public function get(string $ruta, array $consulta = [], array $headers = []): Respuesta
    {
        return $this->enviar('GET', $ruta, ['query' => $consulta, 'headers' => $headers]);
    }

    //$cuerpo: un array se manda como JSON; un string se manda tal cual (para probar JSON roto).
    public function post(string $ruta, array|string|null $cuerpo = null, array $headers = []): Respuesta
    {
        $opciones = ['headers' => $headers];
        if (is_array($cuerpo)) {
            $opciones['json'] = $cuerpo;
        } elseif (is_string($cuerpo)) {
            $opciones['body'] = $cuerpo;
            $opciones['headers']['Content-Type'] = 'application/json';
        }
        return $this->enviar('POST', $ruta, $opciones);
    }

    public function options(string $ruta, array $headers = []): Respuesta
    {
        return $this->enviar('OPTIONS', $ruta, ['headers' => $headers]);
    }

    //El valor de la cookie de sesión (PHPSESSID) que tiene este "navegador", o null si no tiene.
    public function cookieDeSesion(): ?string
    {
        foreach ($this->cookies->toArray() as $cookie) {
            if ($cookie['Name'] === 'PHPSESSID') {
                return $cookie['Value'];
            }
        }
        return null;
    }

    private function enviar(string $metodo, string $ruta, array $opciones): Respuesta
    {
        $respuesta = $this->http->request($metodo, $ruta, $opciones);
        return new Respuesta($respuesta->getStatusCode(), (string) $respuesta->getBody(), $respuesta->getHeaders());
    }
}
