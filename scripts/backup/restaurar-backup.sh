#!/usr/bin/env bash
# Restaura un backup: descifra el dump y lo carga en una base VACÍA, y comprueba que quedaron las mismas filas.
# Se usa ante un desastre (la base se perdió o se rompió) y para probar que los backups sirven (docs/backups.md).
#
# Uso: bash scripts/backup/restaurar-backup.sh ARCHIVO.sql.gz.gpg BASE_DESTINO [ARCHIVO.filas.tsv]
# La conexión de destino, por variables de entorno (por defecto, el MySQL local):
#   DESTINO_HOST (127.0.0.1), DESTINO_PORT (3306), DESTINO_USER (root)
#   DESTINO_PASS     si no está, la pide por teclado
#   DESTINO_SSL_CA   ruta al certificado CA, si el destino exige SSL (por ejemplo, una base nueva en Aiven)
#   BACKUP_CLAVE     la clave del backup; si no está, la pide por teclado
#
# Por seguridad, NUNCA restaura sobre una base que ya tiene tablas: así no se puede pisar producción por error.
# Si se pasa el .filas.tsv del backup, compara tabla por tabla y termina con error si falta alguna tabla.
set -euo pipefail
source "$(dirname "$0")/comun.sh"

archivo="${1:?Indicá el archivo del backup (.sql.gz.gpg)}"
base="${2:?Indicá el nombre de la base de destino (vacía)}"
filas="${3:-}"
[ -f "$archivo" ] || fallar "No existe $archivo"
[[ "$base" =~ ^[A-Za-z0-9_]+$ ]] || fallar "El nombre de la base solo puede tener letras, números y _"

if [ -z "${DESTINO_PASS+x}" ]; then read -r -s -p "Contraseña de MySQL de ${DESTINO_USER:-root}: " DESTINO_PASS; echo; fi
if [ -z "${BACKUP_CLAVE:-}" ]; then read -r -s -p "Clave del backup: " BACKUP_CLAVE; echo; fi

opciones="$(crear_opciones "${DESTINO_HOST:-127.0.0.1}" "${DESTINO_PORT:-3306}" "${DESTINO_USER:-root}" "$DESTINO_PASS" "${DESTINO_SSL_CA:-}")"
temporal="$(mktemp -d)"
trap 'rm -rf "$temporal" "$opciones"' EXIT
mysql_destino() { mysql --defaults-extra-file="$(ruta_para_mysql "$opciones")" "$@"; }

# 1. Se descifra primero (con una clave equivocada, gpg falla y no se toca ninguna base)
gpg --batch --yes --quiet --pinentry-mode loopback --passphrase-fd 3 \
    --decrypt --output "$temporal/dump.sql.gz" "$archivo" 3<<< "$BACKUP_CLAVE" || fallar "No se pudo descifrar: ¿la clave es la correcta?"

# 2. La base de destino tiene que estar vacía (si no existe, se crea)
mysql_destino -e "CREATE DATABASE IF NOT EXISTS \`$base\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
tablas="$(mysql_destino -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$base'" | tr -d '\r')"
[ "$tablas" = "0" ] || fallar "La base $base ya tiene $tablas tablas. Solo se restaura sobre una base vacía (para no pisar datos)."

# 3. Se carga el dump en la base vacía
echo "Restaurando en $base…"
gzip -dc "$temporal/dump.sql.gz" | mysql_destino "$base"

# 4. Se comparan las filas de cada tabla con las que había al hacer el backup
contar_filas "$opciones" "$base" > "$temporal/restauradas.tsv"
echo "Tablas restauradas: $(wc -l < "$temporal/restauradas.tsv")"
if [ -n "$filas" ]; then
    faltan=0
    printf '%-40s %10s %10s\n' "Tabla" "Backup" "Restaurada"
    while IFS=$'\t' read -r tabla esperadas; do
        restauradas="$(awk -F'\t' -v t="$tabla" '$1 == t { print $2 }' "$temporal/restauradas.tsv")"
        if [ -z "$restauradas" ]; then
            faltan=$((faltan + 1)); restauradas="FALTA"
        fi
        marca=""; [ "$restauradas" = "$esperadas" ] || marca="  ← distinta"
        printf '%-40s %10s %10s%s\n' "$tabla" "$esperadas" "$restauradas" "$marca"
    done < <(tr -d '\r' < "$filas")
    # una diferencia de filas puede ser normal (alguien escribió entre el conteo y el dump); una tabla que falta, no
    [ "$faltan" -eq 0 ] || fallar "Faltan $faltan tablas en la base restaurada"
fi
echo "Restore terminado en la base $base."
