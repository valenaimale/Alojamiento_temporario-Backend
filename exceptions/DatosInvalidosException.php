<?php

namespace App\Exceptions;

//Se lanza cuando los datos de la petición no tienen la forma esperada: el cuerpo no es un objeto JSON,
//o un campo trae una lista u objeto donde se espera un valor simple (por ejemplo, "nombre": ["Ana"]).
//El router la atrapa y responde 422 "Los datos enviados no son válidos".
class DatosInvalidosException extends \Exception {}
