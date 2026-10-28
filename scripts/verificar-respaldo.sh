#!/usr/bin/env bash
# Verifica que el respaldo diario más reciente restaura correctamente, sin tocar la base en uso
# (docs/ci-cd.md §2.6). Restaura en una base temporal del mismo servidor MySQL y la borra al
# terminar, pase lo que pase.
#
# Corre por cron en el servidor de producción:
#   0 4 * * 0  /usr/local/sbin/credits-manager-verificar-respaldo >> /var/log/credits-manager-verificar-respaldo.log 2>&1
set -Eeuo pipefail

REPO_DIR="${REPO_DIR:-/root/repositories/credits-manager}"
BASE_TEMPORAL=verificacion_respaldo

log() { printf '[verificar-respaldo %s] %s\n' "$(date '+%F %T')" "$*"; }
fallar() { log "ERROR: $*"; exit 1; }

cd "$REPO_DIR"
compose() { podman compose "$@"; }
mysql_root() { compose exec -T database sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" $*"; }

limpiar() {
  mysql_root "-e 'DROP DATABASE IF EXISTS $BASE_TEMPORAL;'" >/dev/null 2>&1 || true
}
trap limpiar EXIT
trap 'log "Falló el comando de la línea $LINENO."' ERR

carpeta="$(compose exec -T api sh -c 'ls -1 /var/respaldos/diario 2>/dev/null | sort | tail -n1')"
[[ -n "$carpeta" ]] || fallar "no hay respaldos diarios en /var/respaldos/diario"
ruta="/var/respaldos/diario/$carpeta"
log "Verificando respaldo $carpeta"

compose exec -T api sh -c "cd $ruta && sha256sum -c SHA256SUMS" \
  || fallar "SHA256SUMS no coincide en $carpeta: el respaldo está dañado o fue alterado"

log "Restaurando en base temporal $BASE_TEMPORAL (sin tocar la base en uso)..."
mysql_root "-e 'CREATE DATABASE $BASE_TEMPORAL CHARACTER SET utf8mb4;'"

compose exec -T api sh -c "gunzip -c $ruta/base_datos.sql.gz" \
  | compose exec -T database sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" $BASE_TEMPORAL" \
  || fallar "la restauración del volcado falló"

tablas="$(mysql_root "-N -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \"$BASE_TEMPORAL\"'")"
if [[ ! "$tablas" =~ ^[0-9]+$ ]] || (( tablas == 0 )); then
  fallar "la restauración no generó ninguna tabla: el respaldo podría estar corrupto"
fi

log "OK: $carpeta restaura correctamente ($tablas tablas)."
