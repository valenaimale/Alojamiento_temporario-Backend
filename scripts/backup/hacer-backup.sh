#!/usr/bin/env bash
# Hace el backup de la base: un dump (todas las tablas con sus datos, como instrucciones SQL), comprimido y cifrado.
# Lo corre el workflow .github/workflows/backup-bd.yml todos los días (ver docs/backups.md).
#
# Uso: bash scripts/backup/hacer-backup.sh CARPETA_DE_SALIDA
# Lee la conexión de variables de entorno (en GitHub salen de los secrets):
#   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS   la base de origen
#   DB_SSL_CA                                     la ruta al certificado CA (Aiven exige SSL), como en Railway:
#                                                 certificados/aiven-ca.pem; vacío = sin SSL (el MySQL local)
#   BACKUP_CLAVE                                  la clave con la que se cifra el backup
#
# Deja en la carpeta:
#   <base>-<fecha>.sql.gz.gpg   el dump comprimido y cifrado (AES-256): sin la clave no se puede leer
#   <base>-<fecha>.filas.tsv    cuántas filas tenía cada tabla al hacer el backup (para comprobar el restore)
set -euo pipefail
source "$(dirname "$0")/comun.sh"

salida="${1:?Indicá la carpeta de salida}"
: "${DB_HOST:?Falta DB_HOST}" "${DB_NAME:?Falta DB_NAME}" "${DB_USER:?Falta DB_USER}" "${DB_PASS:?Falta DB_PASS}"
: "${BACKUP_CLAVE:?Falta BACKUP_CLAVE (la clave para cifrar el backup)}"
mkdir -p "$salida"

# Archivos temporales (las credenciales y el dump sin cifrar): se borran siempre al terminar, aunque algo falle
temporal="$(mktemp -d)"
trap 'rm -rf "$temporal"' EXIT
ca="${DB_SSL_CA:-}"
[ -z "$ca" ] || [ -f "$ca" ] || fallar "No existe el certificado $ca"
opciones="$(crear_opciones "$DB_HOST" "${DB_PORT:-3306}" "$DB_USER" "$DB_PASS" "$ca")"
trap 'rm -rf "$temporal" "$opciones"' EXIT

nombre="$DB_NAME-$(date -u +%Y%m%d-%H%M)"
echo "Backup de la base $DB_NAME → $nombre"

# 1. Cuántas filas tiene cada tabla ahora (justo antes del dump)
contar_filas "$opciones" "$DB_NAME" > "$salida/$nombre.filas.tsv"
[ -s "$salida/$nombre.filas.tsv" ] || fallar "No se encontraron tablas en $DB_NAME"
echo "Tablas: $(wc -l < "$salida/$nombre.filas.tsv")"

# 2. El dump:
#   --single-transaction  una foto consistente de todas las tablas, sin bloquear la base (InnoDB)
#   --routines --triggers también los procedimientos y triggers, si los hay
#   --set-gtid-purged=OFF sin datos de replicación de Aiven, que harían fallar el restore en otro servidor
#   --no-tablespaces      no pide el permiso PROCESS (no hace falta para un dump lógico)
mysqldump --defaults-extra-file="$(ruta_para_mysql "$opciones")" \
    --single-transaction --quick --routines --triggers --hex-blob \
    --set-gtid-purged=OFF --no-tablespaces \
    "$DB_NAME" | gzip -9 > "$temporal/$nombre.sql.gz"

# mysqldump termina el archivo con "-- Dump completed": si no está, el dump quedó cortado
gzip -dc "$temporal/$nombre.sql.gz" | tail -n 1 | grep -q "Dump completed" || fallar "El dump quedó incompleto"

# 3. Se cifra con la clave (AES-256) y solo se guarda la versión cifrada
gpg --batch --yes --quiet --pinentry-mode loopback --passphrase-fd 3 \
    --symmetric --cipher-algo AES256 --output "$salida/$nombre.sql.gz.gpg" \
    "$temporal/$nombre.sql.gz" 3<<< "$BACKUP_CLAVE"

echo "Listo: $salida/$nombre.sql.gz.gpg ($(du -h "$salida/$nombre.sql.gz.gpg" | cut -f1))"
