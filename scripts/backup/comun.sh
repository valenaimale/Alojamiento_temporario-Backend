#!/usr/bin/env bash
# Funciones que comparten hacer-backup.sh y restaurar-backup.sh (se carga con "source", no se corre sola).
# Arman la conexión a MySQL sin poner la contraseña en la línea de comandos (se vería en la lista de procesos).

# La ruta que entiende mysql: en Git Bash (Windows) el cliente es un .exe y necesita la ruta de Windows
ruta_para_mysql() {
    if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else printf '%s\n' "$1"; fi
}

# Escribe un archivo de opciones de MySQL ([client]) con permisos solo para el usuario y devuelve su ruta.
# Uso: crear_opciones HOST PUERTO USUARIO CLAVE [RUTA_CA]
#   Con RUTA_CA exige SSL y verifica el certificado del servidor (Aiven); sin ella, conexión local común.
crear_opciones() {
    local host="$1" puerto="$2" usuario="$3" clave="$4" ca="${5:-}" archivo
    archivo="$(mktemp)"
    chmod 600 "$archivo"
    # en el archivo de opciones, la contraseña va entre comillas y se escapan \ y "
    clave="${clave//\\/\\\\}"
    clave="${clave//\"/\\\"}"
    {
        echo "[client]"
        echo "host=$host"
        echo "port=$puerto"
        echo "user=$usuario"
        echo "password=\"$clave\""
        echo "default-character-set=utf8mb4"
        if [ -n "$ca" ]; then
            echo "ssl-mode=VERIFY_CA"
            echo "ssl-ca=$(ruta_para_mysql "$ca")"
        fi
    } > "$archivo"
    printf '%s\n' "$archivo"
}

# Cuenta las filas de cada tabla de una base y las escribe como "tabla<TAB>filas", ordenadas por nombre.
# Uso: contar_filas ARCHIVO_OPCIONES BASE
contar_filas() {
    local opciones base tabla
    opciones="$(ruta_para_mysql "$1")"
    base="$2"
    mysql --defaults-extra-file="$opciones" -N -B -e \
        "SELECT table_name FROM information_schema.tables WHERE table_schema = '$base' AND table_type = 'BASE TABLE' ORDER BY table_name" |
        tr -d '\r' |
        while read -r tabla; do
            printf '%s\t%s\n' "$tabla" "$(mysql --defaults-extra-file="$opciones" -N -B -e "SELECT COUNT(*) FROM \`$base\`.\`$tabla\`" | tr -d '\r')"
        done
}

# Muestra un error y termina
fallar() {
    echo "ERROR: $*" >&2
    exit 1
}
