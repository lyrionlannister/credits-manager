#!/usr/bin/env bash
# Despliegue de credits-manager en producción (docs/ci-cd.md).
#
# Lo invoca GitHub Actions por SSH con una llave de command= forzado, o el operador a mano:
#   sudo credits-manager-deploy <sha-completo-del-commit-en-main>
#
# Se instala como copia de root en /usr/local/sbin/credits-manager-deploy; si este archivo
# cambia en el repositorio, hay que reinstalarlo (el script avisa al terminar).
set -Eeuo pipefail

REPO_DIR="${REPO_DIR:-/root/repositories/credits-manager}"
ESTADO_DIR=/var/lib/credits-manager
DESPLEGADO="$ESTADO_DIR/desplegado"
HISTORIAL="$ESTADO_DIR/historial"
LOCK_FILE=/run/lock/credits-manager-deploy.lock
URL_API=https://api.ddasolucionescredicias.tech/up
URL_APP=https://app.ddasolucionescredicias.tech/
IMG_API=localhost/credits-manager-api
IMG_NGINX=localhost/credits-manager-nginx
GHCR_API=ghcr.io/innoval-tech-sas/credits-manager-api
GHCR_NGINX=ghcr.io/innoval-tech-sas/credits-manager-nginx
IMAGENES_CONSERVADAS=5

# Todo lo que corre con la imagen propia. database, redis y phpmyadmin no se tocan.
SERVICIOS_APP=(
  auditoria-listener horizon scheduler monitor reverb api nginx
)

export HOME=/root
en_mantenimiento=0
recreado=0

log() { printf '[deploy %s] %s\n' "$(date '+%F %T')" "$*"; }
fallar() { log "ERROR: $*"; exit 1; }

# Si falló DESPUÉS de recrear los contenedores con la imagen nueva y SIN quedar en mantenimiento
# (ninguna migración a medias), vuelve sola a la imagen anterior: deja la app como estaba antes del
# despliegue en vez de dejar contenedores rotos corriendo. Con mantenimiento de por medio no se toca
# nada — la base pudo quedar a medio migrar y ahí la única vía segura es restaurar el respaldo (§7).
intentar_rollback() {
  if ! podman image exists "$IMG_API:$anterior" || ! podman image exists "$IMG_NGINX:$anterior"; then
    log "No se puede revertir sola: no hay imagen local etiquetada $anterior (¿primer despliegue?)."
    return
  fi

  log "Revirtiendo a $anterior..."
  podman tag "$IMG_API:$anterior" "$IMG_API:latest"
  podman tag "$IMG_NGINX:$anterior" "$IMG_NGINX:latest"

  if ! compose up -d --force-recreate --no-deps "${SERVICIOS_APP[@]}"; then
    log "La reversión tampoco pudo recrear los contenedores. Revisa a mano."
    return
  fi
  compose restart nginx

  if verificar_url "$URL_API" && verificar_url "$URL_APP"; then
    log "Reversión OK: $anterior quedó corriendo de nuevo."
  else
    log "Revertió la imagen pero $URL_API/$URL_APP siguen sin responder. Revisa a mano."
  fi
}

al_salir() {
  local estado=$?
  (( estado == 0 )) && return
  log "ERROR: el despliegue falló (código $estado)."
  if (( en_mantenimiento )); then
    log "La aplicación QUEDÓ EN MANTENIMIENTO a propósito: revisa los logs antes de reabrirla."
    log "  Si la base quedó a medio migrar, restaura el respaldo previo (docs/respaldos.md §7)."
    log "  Para reabrir: podman compose exec --user www-data api php artisan up"
  elif (( recreado )); then
    intentar_rollback
  fi
}
trap al_salir EXIT
trap 'log "Falló el comando de la línea $LINENO."' ERR

# Sin `9>&-`, conmon y los contenedores que arranca podman heredan el descriptor del candado y lo
# mantienen tomado mientras vivan: todo despliegue posterior fallaría con "ya hay un despliegue en curso".
podman() { command podman "$@" 9>&-; }
compose() { podman compose "$@"; }
artisan() { compose exec -T --user www-data api php artisan "$@"; }

esperar_api() {
  local _intento
  for _intento in $(seq 1 45); do
    if compose exec -T api php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);' >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  return 1
}

verificar_url() {
  local url="$1"
  local _intento
  for _intento in $(seq 1 15); do
    if curl -fsS --max-time 10 -o /dev/null "$url"; then
      return 0
    fi
    sleep 3
  done
  return 1
}

# --- 0. Validaciones ---------------------------------------------------------

sha="${1:-}"
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || fallar "se esperaba el SHA completo (40 caracteres) del commit a desplegar"

mkdir -p "$ESTADO_DIR"
exec 9>"$LOCK_FILE"
flock -n 9 || fallar "ya hay un despliegue en curso"

cd "$REPO_DIR"
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || fallar "hay cambios locales sin commit en $REPO_DIR"

git fetch --quiet origin main
if [[ "$(git rev-parse origin/main)" != "$sha" ]]; then
  log "origin/main ya no apunta a $sha (hubo un merge posterior); ese despliegue se encargará. Nada que hacer."
  exit 0
fi

# La primera vez se toma como desplegado lo que hay hoy en el checkout. Se fija antes del merge
# para que un despliegue fallido a mitad de camino se reintente completo.
[[ -f "$DESPLEGADO" ]] || git rev-parse HEAD > "$DESPLEGADO"
anterior="$(cat "$DESPLEGADO")"
if [[ "$anterior" == "$sha" ]]; then
  log "$sha ya está desplegado. Nada que hacer."
  exit 0
fi

log "Desplegando $anterior -> $sha"

# --- 1. Código ---------------------------------------------------------------

git merge --ff-only --quiet "$sha"

hay_migraciones=0
if ! git diff --quiet "$anterior" "$sha" -- api/database/migrations; then
  hay_migraciones=1
  log "El cambio trae migraciones:"
  git diff --name-only "$anterior" "$sha" -- api/database/migrations | sed 's/^/    /'
fi

# --- 2. Respaldo previo (obligatorio: si falla, no se toca nada más) ----------

log "Respaldo previo..."
artisan respaldos:ejecutar --manual || fallar "el respaldo previo falló; los contenedores siguen intactos"

# --- 3. Imágenes -------------------------------------------------------------
# Ya las construyó y probó el CI (job "Imágenes de producción"); aquí solo se descargan. Evita
# reconstruir en el servidor —más lento, y podría diferir de lo que realmente pasó CI (caché
# distinto, una dependencia que cambió de versión entre un build y otro).

log "Descargando imágenes construidas por CI ($sha)..."
podman pull "$GHCR_API:$sha"   || fallar "no se pudo descargar $GHCR_API:$sha (¿el job \"Imágenes de producción\" del CI terminó bien? ¿'podman login ghcr.io' sigue vigente? docs/ci-cd.md §2.5)"
podman pull "$GHCR_NGINX:$sha" || fallar "no se pudo descargar $GHCR_NGINX:$sha"

podman tag "$GHCR_API:$sha"   "$IMG_API:latest"
podman tag "$GHCR_NGINX:$sha" "$IMG_NGINX:latest"
podman tag "$GHCR_API:$sha"   "$IMG_API:$sha"
podman tag "$GHCR_NGINX:$sha" "$IMG_NGINX:$sha"

# --- 4. Contenedores y migraciones -------------------------------------------

if (( hay_migraciones )); then
  log "Modo mantenimiento mientras se migra..."
  artisan down --retry=60
  en_mantenimiento=1
fi

# --force-recreate: podman-compose no recrea un contenedor si solo cambió la imagen.
# --no-deps: la base, Redis y phpMyAdmin siguen corriendo sin reiniciarse.
log "Recreando servicios: ${SERVICIOS_APP[*]}"
compose up -d --force-recreate --no-deps "${SERVICIOS_APP[@]}"
recreado=1

esperar_api || fallar "api no levantó php-fpm a tiempo"

log "Migraciones..."
compose exec -T api php artisan migrate --force

if (( en_mantenimiento )); then
  artisan up
  en_mantenimiento=0
fi

# nginx guarda la IP de api al arrancar; reiniciarlo evita el 502 tras recrear api.
compose restart nginx

# --- 5. Verificación ---------------------------------------------------------

verificar_url "$URL_API" || fallar "$URL_API no responde 200"
verificar_url "$URL_APP" || fallar "$URL_APP no responde 200"

echo "$sha" > "$DESPLEGADO"
echo "$sha" >> "$HISTORIAL"

# --- 6. Limpieza de imágenes viejas (solo quita etiquetas de despliegues anteriores) ---

if (( $(wc -l < "$HISTORIAL") > IMAGENES_CONSERVADAS )); then
  head -n "-$IMAGENES_CONSERVADAS" "$HISTORIAL" | while read -r viejo; do
    podman rmi "$IMG_API:$viejo" "$IMG_NGINX:$viejo" "$GHCR_API:$viejo" "$GHCR_NGINX:$viejo" >/dev/null 2>&1 || true
  done
  tail -n "$IMAGENES_CONSERVADAS" "$HISTORIAL" > "$HISTORIAL.tmp"
  mv "$HISTORIAL.tmp" "$HISTORIAL"
fi

if ! cmp -s "$0" "$REPO_DIR/scripts/deploy.sh"; then
  log "AVISO: scripts/deploy.sh cambió en el repositorio; reinstálalo (docs/ci-cd.md §2)."
fi

log "Listo: $sha desplegado."
