# Tests automatizados

Los tests usan **PHPUnit** y están en dos grupos:

| Grupo | Carpeta | Qué prueba | Qué necesita |
|---|---|---|---|
| **Unitarios** | `tests/Unitarios` | Funciones sueltas: validación de DNI y CUIT, permisos por rol, configuración por variables de entorno, lectura del JSON de las peticiones, formato de los logs | Nada (ni MySQL ni servidor) |
| **Integración** | `tests/Integracion` | La API completa, como la usa el front: registro, login, sesión, permisos, backoffice, "hacerme propietario", verificación de mail, OAuth, health check y manejo de errores | MySQL |

## Cómo correrlos

Desde la raíz del backend:

```bash
vendor/bin/phpunit                          # todos
vendor/bin/phpunit --testsuite unitarios    # solo los unitarios (no necesitan MySQL)
vendor/bin/phpunit --testsuite integracion  # solo los de integración
vendor/bin/phpunit --filter RegistroTest    # una clase (o un test) puntual
```

Si es la primera vez o cambió `composer.json`, antes hay que correr `composer install` (instala PHPUnit, que es una dependencia de desarrollo).

## Qué hacen los tests de integración

Antes del primer test, `tests/Soporte/EntornoDePrueba.php`:

1. **Crea una base de prueba vacía**, `alojamiento_test`, y le aplica todas las migraciones. **No toca la base de desarrollo** (`alojamiento`): se conecta con el mismo usuario y contraseña de MySQL de tu `phinx.php` (entorno `development`), pero a otra base. La base de prueba se borra y se vuelve a crear en cada ejecución; por seguridad, su nombre tiene que terminar en `_test`.
2. **Levanta el backend** con `php -S 127.0.0.1:8089`, conectado a esa base.
3. Los tests le hacen peticiones HTTP, igual que el front (con `tests/Soporte/ClienteApi.php`, que guarda la cookie de sesión como un navegador).

`ErroresTest` levanta además un segundo backend en el puerto 8090 **sin base de datos**, para probar el health check, el mensaje genérico al usuario y el log del error.

Cada test usa su propio mail, DNI y CUIT (`tests/Soporte/Datos.php`), así no dependen unos de otros.

### Variables de entorno (opcionales)

| Variable | Para qué | Por defecto |
|---|---|---|
| `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_USER`, `TEST_DB_PASS` | Conexión a MySQL | Los del entorno `development` de `phinx.php` |
| `TEST_DB_NAME` | Nombre de la base de prueba (tiene que terminar en `_test`) | `alojamiento_test` |
| `TEST_PUERTO` | Puerto del backend de prueba (usa ese y el siguiente) | `8089` |
| `MAILPIT_URL` | API de Mailpit, para el test del mail real | `http://127.0.0.1:8025` |

GitHub Actions las define en `.github/workflows/ci.yml`.

### El test del mail real

`RecuperacionContraseniaTest` también usa Mailpit para comprobar el enlace de recuperación y el aviso posterior al cambio. Sus otros casos no necesitan consultar Mailpit; cubren tokens, validación, límites y revocación de sesiones.

`VerificacionMailTest::testRecorridoCompletoConElMailReal` se registra, busca el mail de verificación en **Mailpit** y abre el enlace. Si Mailpit no está corriendo, ese test se **saltea** (aparece como `S`); el resto de la verificación de mail se prueba igual. En GitHub Actions, Mailpit corre siempre.

### En Windows tardan más

En Windows los tests de integración tardan alrededor de un minuto y medio: cada registro intenta mandar el mail de verificación, y si Mailpit no está levantado, Windows tarda en rechazar la conexión. En GitHub Actions (Linux) tardan mucho menos.

## Integración y despliegue continuos (CI/CD)

En cada push y en cada pull request hacia `main`, **GitHub Actions** corre `.github/workflows/ci.yml`:

1. Instala PHP 8.5 y las dependencias.
2. **Lint**: revisa la sintaxis de todos los archivos PHP.
3. Corre los **tests unitarios** y los **de integración**, contra un MySQL 8.4 configurado con el mismo modo SQL que Aiven (la base de producción) y un Mailpit.
4. **Construye la imagen de Docker** de producción (la misma que usa Railway) y verifica que no tenga archivos sensibles.

Cuando el cambio entra a `main` (un PR mergeado), el mismo workflow sigue con el **despliegue continuo (CD)**: despliega en Railway y verifica que producción responda con la versión nueva. Solo se ejecuta si todo lo anterior pasó (ver `docs/despliegue.md`, sección "Pipeline CI/CD").

El resultado se ve en la pestaña **Actions** del repo, y al lado de cada commit y de cada PR (✅ o ❌).

## Prueba de carga (k6)

Aparte de PHPUnit, `tests/carga/carga.js` es una **prueba de carga con k6**: mide cuánto tarda el backend con 1 y con 10 usuarios a la vez (RNF-13). No corre en el CI: se ejecuta a mano, con Docker. Ver `tests/carga/README.md`.

## Cómo agregar un test

- **Unitario:** una clase en `tests/Unitarios` que extienda `PHPUnit\Framework\TestCase`, con métodos que empiecen con `test`.
- **De integración:** una clase en `tests/Integracion` que extienda `Tests\Integracion\CasoDeIntegracion`. Tiene atajos: `$this->cliente()` (un navegador nuevo), `$this->registrar('huesped')`, `$this->crearUsuarioEnBase('backoffice')`, `$this->iniciarSesion($mail)` y `$this->pdo()` (la base de prueba).
- El archivo tiene que terminar en `Test.php`.
