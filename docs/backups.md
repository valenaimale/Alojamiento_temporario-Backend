# Backups y restore

**En resumen:** la base de producción tiene **dos backups diarios independientes**: el automático de Aiven y uno propio, cifrado, que se guarda 30 días en GitHub. El backup propio **se restaura de prueba todos los días**: se sabe que sirve, no se supone. El código del back y del front está en GitHub, y Railway y Vercel guardan los despliegues anteriores para volver atrás con un clic.

## Qué se respalda y dónde

| Qué | Backup | Dónde queda | Cuánto tiempo | Cómo se recupera |
|---|---|---|---|---|
| **Base de datos** (Aiven) | Automático de Aiven, todos los días | Fuera del servidor de la base: DigitalOcean, San Francisco (`do-sfo2`) | Unos días (depende del plan) | Desde la consola de Aiven ("Backups and forking") |
| **Base de datos** | **Propio**: dump cifrado, todos los días a las 04:00 | GitHub Actions, como artifact de cada ejecución | **30 días** | Con `scripts/backup/restaurar-backup.sh` (abajo) |
| **Código del back y del front** | Cada commit | GitHub (y en la PC de cada integrante) | Todo el historial | `git clone` |
| **Back desplegado** (Railway) | Cada despliegue | Railway | Los despliegues anteriores | Railway → el servicio → *Deployments* → *Redeploy* en uno anterior |
| **Front desplegado** (Vercel) | Cada despliegue | Vercel | Los despliegues anteriores | Vercel → el proyecto → *Deployments* → *Instant Rollback* |
| **Configuración** (variables de Railway) | Documentada (nombres y para qué sirve cada una, nunca los valores) | [despliegue.md](despliegue.md), paso 2 y "Pagos reales" | — | Se vuelven a cargar en Railway |

Los **valores** secretos (contraseñas, claves de APIs, la clave privada de la plataforma) no se guardan en el repo: los tiene el equipo, fuera de GitHub.

## Algunas palabras

- **Dump** (o volcado): un archivo con todas las instrucciones SQL para reconstruir la base desde cero (`CREATE TABLE …` y los `INSERT …` con los datos). Es una foto de la base en un momento. Lo genera `mysqldump`.
- **Backup**: hacer ese dump y guardarlo **en otro lugar**, separado de la base.
- **Restore**: el camino inverso. Si la base se pierde o se rompe, se carga el dump en una base vacía y queda como estaba en el momento del backup.

## El backup propio, paso a paso

Lo hace el workflow [`.github/workflows/backup-bd.yml`](../.github/workflows/backup-bd.yml), en GitHub Actions:

1. **Cuenta las filas** de cada tabla de producción.
2. **Hace el dump** con `mysqldump --single-transaction`: una foto consistente de todas las tablas, sin bloquear la base (los usuarios siguen usando la aplicación mientras tanto). Comprueba que el dump esté completo.
3. **Lo comprime y lo cifra** con AES-256 y la clave `BACKUP_CLAVE`. El backup tiene datos personales (nombres, mails, DNI), así que nunca se guarda sin cifrar: sin la clave, el archivo no se puede leer.
4. **Lo restaura de prueba** en un MySQL vacío que se crea solo para eso, y compara las filas de cada tabla con las del paso 1. El resultado queda en el resumen de la ejecución.
5. **Lo guarda 30 días** como artifact: el `.sql.gz.gpg` (el backup) y el `.filas.tsv` (las filas de cada tabla).

Corre solo **todos los días a las 04:00** de Argentina. Para hacer uno en el momento (por ejemplo, antes de una migración): GitHub → **Actions → Backup de la base → Run workflow**.

**Nunca escribe en producción**: solo la lee.

### Configuración (una sola vez)

En el repo del back → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Qué va |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Los datos de conexión a Aiven (los mismos que en Railway) |
| `BACKUP_CLAVE` | Una clave larga para cifrar los backups |

> **Guardá `BACKUP_CLAVE` también fuera de GitHub** (en un gestor de contraseñas). GitHub no deja volver a verla, y **sin esa clave los backups no se pueden abrir**.

El certificado de Aiven (`certificados/aiven-ca.pem`) ya está en el repo: la conexión va cifrada y se verifica que el servidor sea el de Aiven.

## Cómo se restaura

El script [`scripts/backup/restaurar-backup.sh`](../scripts/backup/restaurar-backup.sh) descifra el backup, lo carga en una base y compara las filas. **Solo restaura sobre una base vacía**: si la base de destino ya tiene tablas, se niega. Así no se puede pisar producción por error.

### Probarlo en la PC (Git Bash)

1. GitHub → **Actions → Backup de la base →** la ejecución → **Artifacts** → descargar `backup-…` y descomprimir el `.zip`.
2. En Git Bash, desde la carpeta del back:

   ```bash
   bash scripts/backup/restaurar-backup.sh ~/Downloads/backup-123/alojamiento-20261113-0700.sql.gz.gpg alojamiento_restaurada ~/Downloads/backup-123/alojamiento-20261113-0700.filas.tsv
   ```

   Pide la contraseña del MySQL local y la clave del backup. Crea la base `alojamiento_restaurada` y muestra una tabla con las filas de cada tabla (backup vs. restaurada).
3. Al terminar, borrar la base de prueba: `DROP DATABASE alojamiento_restaurada;`

### Ante un desastre (se perdió la base de producción)

1. Crear una base **vacía** en Aiven (o un servicio nuevo).
2. Restaurar el último backup ahí, con las variables `DESTINO_HOST`, `DESTINO_PORT`, `DESTINO_USER`, `DESTINO_PASS` y `DESTINO_SSL_CA=certificados/aiven-ca.pem`:

   ```bash
   DESTINO_HOST=<host de Aiven> DESTINO_PORT=<puerto> DESTINO_USER=avnadmin DESTINO_SSL_CA=certificados/aiven-ca.pem \
     bash scripts/backup/restaurar-backup.sh alojamiento-<fecha>.sql.gz.gpg alojamiento_nueva alojamiento-<fecha>.filas.tsv
   ```

3. En Railway, cambiar `DB_NAME` (y `DB_HOST`, si es otro servicio) en el back y en los dos crons. Railway vuelve a desplegar solo.
4. Verificar `https://<back>/estado` → `"base_de_datos": "ok"`.

Se pierde lo que pasó entre el último backup y el desastre (como mucho, un día). Los **pagos** no se pierden: están en la blockchain, y el contrato guarda cuánto se pagó y devolvió de cada reserva.

## Cómo se verifica

- **Todos los días:** la ejecución del workflow en verde = el backup se hizo **y** se restauró bien. Si falla, GitHub avisa por mail (a quien agregó el workflow) y la ejecución queda en rojo en **Actions**.
- En el resumen de cada ejecución está la tabla de filas (backup vs. restaurada), como evidencia.

## Límites

- El backup es diario: no es una réplica en tiempo real.
- Los artifacts de GitHub duran 30 días; para guardar uno más tiempo, se descarga.
- Una diferencia de pocas filas en la prueba puede ser normal: alguien usó la aplicación entre el conteo y el dump. Lo que nunca puede pasar es que falte una tabla (eso hace fallar la prueba).
