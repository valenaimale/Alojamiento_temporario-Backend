# Flujo de trabajo: ramas, pull requests y CI/CD

Cómo se trabaja en el backend: cómo se sube un cambio, qué controles pasa y cómo llega a producción.

## Las tres reglas

1. **Nadie hace push directo a `main`.** Todo cambio se hace en una rama y entra por un **pull request (PR)**.
2. **Un PR se puede mergear solo si el CI pasó.** GitHub Actions corre el lint, los tests y el build en cada PR. Si algo falla (❌), el botón de merge queda bloqueado.
3. **Lo que entra a `main` se despliega solo en producción**, pero solo si vuelve a pasar todos los controles. Nadie despliega a mano.

```
 tu rama ──► push ──► PR ──► CI: lint + tests + build ──► ✅ ──► merge a main ──► CI otra vez ──► deploy a Railway ──► verificación
                               │                                                    │
                               └─► ❌: no se puede mergear                           └─► ❌: no se despliega; producción sigue igual
```

## Las piezas

| Pieza | Qué es | Dónde está |
|---|---|---|
| **Pull request (PR)** | El pedido para incorporar los cambios de una rama a `main` | GitHub → pestaña **Pull requests** |
| **CI** (integración continua) | Verificación automática de cada cambio: **lint** (que no haya errores de sintaxis en PHP), **tests** (unitarios y de integración) y **build** (que la imagen de producción se pueda construir) | `.github/workflows/ci.yml`, corre en **GitHub Actions** |
| **CD** (despliegue continuo) | Llevar a producción, automáticamente, cada cambio que entra a `main` | El mismo workflow: los jobs **Deploy a Railway** y **Verificar producción** |
| **Regla "Proteger main"** | Bloquea el push directo a `main` y el merge de un PR con el CI en ❌ | GitHub → **Settings → Rules** |
| **Health check** | `GET /estado`: dice si el backend y la base funcionan. Railway lo consulta antes de pasarle el tráfico a una versión nueva | `controllers/EstadoController.php` |

### Qué hace GitHub Actions en cada ejecución

1. Prende una **máquina virtual limpia** en los servidores de GitHub.
2. Descarga el código e instala PHP 8.5 y las dependencias.
3. Levanta al lado un **MySQL** (con el mismo modo SQL estricto que Aiven) y un **Mailpit**.
4. Corre el **lint**, los **tests unitarios** y los **tests de integración**. Estos crean su propia base de prueba, levantan el backend y le hacen peticiones como el front.
5. Construye la **imagen de Docker** de producción.
6. **Solo si es `main` y todo lo anterior pasó:** despliega en Railway y espera a que `/estado` responda con el commit nuevo.
7. Borra la máquina.

### Los checks y qué significa cada estado

| Check | Cuándo corre |
|---|---|
| `Lint y tests (PHPUnit)` | En cada push y en cada PR |
| `Build de la imagen de producción (Docker)` | En cada push y en cada PR |
| `Deploy a Railway (producción)` | Solo en `main`, si los dos anteriores pasaron |
| `Verificar producción (/estado)` | Solo en `main`, después del deploy |

- ✅ **Pasó.**
- ❌ **Falló.** Entrando al check se ve en qué paso y por qué.
- ⊘ **Skipped (salteado).** No le tocaba correr. En un PR, el deploy y la verificación siempre aparecen así: es lo esperado.
- 🟡 **En curso.** Tarda unos 2 minutos (y unos 4 con el deploy).

---

## Paso a paso para subir un cambio

```bash
# 1. Partir de main actualizado
git checkout main
git pull

# 2. Crear una rama para el cambio (un nombre que diga qué hace)
git checkout -b agregar-reservas

# 3. Programar, y antes de subir, correr los tests en tu máquina (opcional, pero ahorra tiempo)
vendor/bin/phpunit

# 4. Commitear y subir la rama
git add <archivos>
git commit -m "Agrego la creación de reservas"
git push -u origin agregar-reservas
```

5. En GitHub, abrir el **PR** de `agregar-reservas` hacia `main`.
6. Esperar el CI (unos 2 minutos). Si está en ✅, **Merge pull request**.
7. El merge dispara el CI/CD en `main`. En unos 4 minutos, el cambio está en producción.
8. Volver a `main` en tu máquina: `git checkout main` y `git pull`.

---

## Ejemplos

### Ejemplo 1: los tests pasan

Ana agrega un campo "teléfono" al registro, en la rama `telefono-en-registro`, y también agrega su test. Sube la rama y abre el PR.

1. En el PR aparecen los checks en 🟡 y, a los 2 minutos, en ✅: **All checks have passed**. El deploy y la verificación figuran como ⊘ *skipped*, porque todavía no está en `main`.
2. El botón **Merge pull request** está habilitado. Ana mergea.
3. En **Actions** arranca el pipeline sobre `main`: lint y tests ✅, build ✅, **Deploy a Railway** ✅ y **Verificar producción** ✅ (*"OK: producción tiene la versión 3f2a1c9"*).
4. A los 4 minutos, el registro en producción ya pide el teléfono. Nadie tuvo que entrar a Railway.

### Ejemplo 2: un test falla en el PR

Juan cambia la validación del DNI y, sin darse cuenta, ahora acepta 9 dígitos. Sube la rama `cambio-dni` y abre el PR.

1. El check `Lint y tests (PHPUnit)` termina en ❌. El PR dice **Some checks were not successful**, y el botón de merge queda **bloqueado**: *"Merging is blocked: required status checks must pass"*.
2. Juan entra al check (**Details**) y, en el paso **Tests unitarios**, ve qué falló:
   ```
   1) Tests\Unitarios\ValidadorDocumentosTest::testEsDniValido with data set "9 dígitos"
   Failed asserting that true is identical to false.
   ```
   O sea: el test esperaba que un DNI de 9 dígitos fuera **inválido**, y el código dijo que era **válido**.
3. **Producción no se enteró de nada:** el cambio nunca llegó a `main`, así que no hubo deploy.
4. Juan corrige el código **en la misma rama**, commitea y hace `git push`. **No hace falta abrir otro PR:** el PR se actualiza solo y el CI vuelve a correr.
5. Ahora todo está en ✅, y el merge se habilita.

> Si el test estaba mal (porque la regla cambió a propósito), se corrige el test, no el código. Pero ese cambio también pasa por el PR, y el resto del equipo lo ve.

### Ejemplo 3: alguien intenta hacer push directo a `main`

María hace un cambio en `main` sin crear una rama y hace `git push`. GitHub lo rechaza:

```
remote: error: GH013: Repository rule violations found for refs/heads/main.
remote: - Changes must be made through a pull request.
 ! [remote rejected] main -> main (push declined due to repository rule violations)
```

Su commit no se perdió: sigue en su máquina. Para subirlo:

```bash
git checkout -b cambio-de-maria     # crea una rama con el commit que ya tenía
git push -u origin cambio-de-maria
```

Abre el PR y sigue el flujo normal. Cuando el PR se mergee, en su máquina vuelve a `main` y hace `git pull`.

### Ejemplo 4: el deploy no puede terminar (por ejemplo, Aiven está apagado)

Se mergea un PR con todo en ✅, pero la base de Aiven se apagó por inactividad.

1. En `main`, lint, tests y build pasan ✅. **Deploy a Railway** sube la versión nueva.
2. Railway consulta `/estado` antes de pasarle el tráfico: responde **503** (la base no responde). Railway **descarta la versión nueva**.
3. **Verificar producción** espera 6 minutos a que producción tenga el commit nuevo y termina en ❌.
4. **Producción sigue funcionando con la versión anterior.** Los usuarios no se enteraron.
5. Para resolverlo: encender Aiven y, en **Actions**, abrir la ejecución fallida y tocar **Re-run failed jobs**.

### Ejemplo 5: algo llegó a producción y hay que volver atrás

Un cambio pasó todos los tests, pero en producción se descubre un error que los tests no cubrían.

- **Lo más rápido:** Railway → el servicio → **Deployments** → la versión anterior → **Redeploy**. Producción vuelve a la versión anterior en un par de minutos.
- **Después:** se corrige en una rama, **con un test nuevo que cubra ese caso** (así no vuelve a pasar), y se sube por PR.

---

## Preguntas frecuentes

**¿Puedo hacer push a mi rama aunque los tests fallen?**
Sí. El push a tu rama siempre funciona: el CI corre después y te dice si algo falla. Lo que no se puede es **mergear a `main`** con el CI en ❌.

**¿Tengo que correr los tests en mi máquina?**
No es obligatorio, porque GitHub los corre igual, pero ahorra tiempo: te enterás en segundos, sin esperar el CI. Con `vendor/bin/phpunit --testsuite unitarios` corren en menos de un segundo y no necesitan MySQL. Cómo correr todos: `tests/README.md`.

**¿Por qué los checks aparecen dos veces, con `(push)` y `(pull_request)`?**
El CI corre cuando subís la rama (`push`) y cuando abrís o actualizás el PR (`pull_request`). Es redundante, pero inofensivo.

**¿Y el frontend?**
El frontend incorpora un workflow de sintaxis JavaScript y tests de interfaz con Node.js 24. Vercel despliega los cambios integrados en `main` y puede generar una preview del PR. La preview apunta a la API de producción: si cambia el contrato, revisar las ramas de ambos repositorios en local y coordinar las migraciones e integración del backend antes del frontend.

**¿Dónde está documentado el despliegue?**
En `docs/despliegue.md` (los servicios, las variables de entorno y el pipeline) y en `tests/README.md` (los tests).

---

## Glosario

| Término | Significado |
|---|---|
| **Rama** (*branch*) | Una línea de trabajo separada de `main`, donde se hace un cambio sin afectar a los demás |
| **Pull request (PR)** | El pedido para incorporar una rama a `main`. Ahí corre el CI y se revisa el cambio |
| **Merge** | Incorporar los cambios del PR a `main` |
| **CI** (*Continuous Integration*) | Verificar automáticamente cada cambio (lint, tests, build) |
| **CD** (*Continuous Deployment*) | Desplegar automáticamente en producción cada cambio que pasó el CI |
| **Workflow** | El archivo que define el pipeline (`.github/workflows/ci.yml`) |
| **Job** | Cada bloque del workflow (por ejemplo, "Lint y tests" o "Deploy a Railway") |
| **Check** | El resultado de un job en un commit o un PR (✅, ❌, ⊘) |
| **Health check** | Un endpoint (`/estado`) que dice si la aplicación funciona |
| **Rollback** | Volver a una versión anterior |
