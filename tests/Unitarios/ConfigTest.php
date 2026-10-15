<?php

namespace Tests\Unitarios;

use App\Config\Config;
use PHPUnit\Framework\TestCase;

//RNF de configuración por variables de entorno: cada clave de configuración se puede reemplazar
//con una variable de entorno (así se configura el backend en Railway, sin archivos con contraseñas).
final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SMTP_REMITENTE');//sin "=": borra la variable de entorno
    }

    public function testLaVariableDeEntornoTienePrioridadSobreElArchivo(): void
    {
        putenv('SMTP_REMITENTE=desde-el-entorno@test.local');
        $this->assertSame('desde-el-entorno@test.local', Config::obtener('smtp.remitente'));
    }

    public function testLaVariableDeEntornoSeLeeSinEspacios(): void
    {
        //pasó en Railway: el panel agregó un espacio al principio del valor
        putenv('SMTP_REMITENTE=   con-espacios@test.local  ');
        $this->assertSame('con-espacios@test.local', Config::obtener('smtp.remitente'));
    }

    public function testSinVariableDeEntornoSeUsaElArchivo(): void
    {
        $this->assertNotSame('', (string) Config::obtener('smtp.remitente'));
    }

    public function testUnaVariableVaciaNoReemplazaAlArchivo(): void
    {
        putenv('SMTP_REMITENTE=   ');
        $this->assertNotSame('', trim((string) Config::obtener('smtp.remitente')));
    }

    public function testUnaClaveInexistenteLanzaExcepcion(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::obtener('no.existe');
    }

    public function testUnaClaveInexistenteConValorPorDefecto(): void
    {
        $this->assertSame('por-defecto', Config::obtener('no.existe', 'por-defecto'));
    }
}
