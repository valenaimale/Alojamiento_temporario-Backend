<?php

namespace Tests\Unitarios;

use App\Config\Config;
use PHPUnit\Framework\TestCase;

//RNF de configuración por variables de entorno: cada clave de configuración se puede reemplazar
//con una variable de entorno (así se configura el backend en Railway, sin archivos con contraseñas).
final class ConfigTest extends TestCase
{
    //Después de cada test borra la variable de entorno, para no afectar a los demás
    protected function tearDown(): void
    {
        putenv('SMTP_REMITENTE');//sin "=": borra la variable de entorno
    }

    //Una variable de entorno gana sobre el archivo de configuración (así se configura Railway)
    public function testLaVariableDeEntornoTienePrioridadSobreElArchivo(): void
    {
        putenv('SMTP_REMITENTE=desde-el-entorno@test.local');
        $this->assertSame('desde-el-entorno@test.local', Config::obtener('smtp.remitente'));
    }

    //Los espacios de los bordes de una variable de entorno se ignoran
    public function testLaVariableDeEntornoSeLeeSinEspacios(): void
    {
        //pasó en Railway: el panel agregó un espacio al principio del valor
        putenv('SMTP_REMITENTE=   con-espacios@test.local  ');
        $this->assertSame('con-espacios@test.local', Config::obtener('smtp.remitente'));
    }

    //Sin variable de entorno, se usa el archivo
    public function testSinVariableDeEntornoSeUsaElArchivo(): void
    {
        $this->assertNotSame('', (string) Config::obtener('smtp.remitente'));
    }

    //Una variable vacía (o solo con espacios) no reemplaza al archivo
    public function testUnaVariableVaciaNoReemplazaAlArchivo(): void
    {
        putenv('SMTP_REMITENTE=   ');
        $this->assertNotSame('', trim((string) Config::obtener('smtp.remitente')));
    }

    //Una clave que no existe lanza una excepción (un error de tipeo no pasa desapercibido)
    public function testUnaClaveInexistenteLanzaExcepcion(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::obtener('no.existe');
    }

    //Con un valor por defecto, una clave que no existe devuelve ese valor
    public function testUnaClaveInexistenteConValorPorDefecto(): void
    {
        $this->assertSame('por-defecto', Config::obtener('no.existe', 'por-defecto'));
    }
}
