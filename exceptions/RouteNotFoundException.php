<?php

namespace App\Exceptions;

//Se lanza cuando no hay ninguna ruta registrada para el metodo + path de la request.
class RouteNotFoundException extends \Exception {}
