#!/bin/bash
set -e

REPO_DIR="/var/www/servicebox"
BRANCH="new-branch"

cd "$REPO_DIR"

# ── Bootstrap: pull latest code, re-exec so the UPDATED script runs ──
if [ -z "$SERVICEBOX_DEPLOY_V" ]; then
  echo "=== ServiceBox Deploy (bootstrap) ==="
  echo "→ Pulling latest code from $BRANCH..."

  # Save old hash BEFORE pull (for diff comparison)
  OLD_HASH=$(git rev-parse HEAD 2>/dev/null || echo "")

  git fetch origin
  git reset --hard origin/$BRANCH

  echo "→ Re-executing updated deploy.sh..."
  export OLD_HASH="$OLD_HASH"
  export SERVICEBOX_DEPLOY_V=2
  exec bash "$REPO_DIR/deploy.sh"
fi

# ═══ Everything below runs from the UPDATED script ════════════════════

NEW_HASH=$(git rev-parse HEAD)

echo "=== ServiceBox Deploy ==="
echo "→ Dir:    $REPO_DIR"
echo "→ Branch: $BRANCH"
echo "→ Commit: ${NEW_HASH:0:8}"

# ── Detect what changed since last deploy ──────────────────────────
if [ -n "$OLD_HASH" ] && [ "$OLD_HASH" != "$NEW_HASH" ]; then
  CHANGED=$(git diff --name-only "$OLD_HASH" "$NEW_HASH" 2>/dev/null || echo "ALL")
  echo "→ From:   ${OLD_HASH:0:8}"
  echo "→ Files changed:"
  echo "$CHANGED" | sed 's/^/     /'
else
  CHANGED="ALL"
  echo "→ No previous hash — full rebuild"
fi

# ── What needs to be rebuilt? ──────────────────────────────────────
ADMIN_BUILD=false   # rebuild admin/dist
ADMIN_NPM=false     # run npm ci for admin
WIDGET_BUILD=false  # rebuild widget/dist
WIDGET_NPM=false    # run npm ci for widget
DOCKER_BUILD=false  # rebuild Docker image (composer deps changed)
NGINX_RELOAD=false  # reload nginx config inside web container

if [ "$CHANGED" = "ALL" ]; then
  ADMIN_BUILD=true; ADMIN_NPM=true
  WIDGET_BUILD=true; WIDGET_NPM=true
  DOCKER_BUILD=true; NGINX_RELOAD=true
else
  # Admin: build if any admin/ file changed
  if echo "$CHANGED" | grep -qE '^admin/'; then
    ADMIN_BUILD=true
    # Full npm install only if packages changed
    echo "$CHANGED" | grep -qE '^admin/package(-lock)?\.json' && ADMIN_NPM=true || true
  fi

  # Widget: build if any widget/ file changed
  if echo "$CHANGED" | grep -qE '^widget/'; then
    WIDGET_BUILD=true
    echo "$CHANGED" | grep -qE '^widget/package(-lock)?\.json' && WIDGET_NPM=true || true
  fi

  # Docker image: only rebuild if PHP deps or Dockerfile changed
  # (PHP source files are bind-mounted so they update without rebuild)
  if echo "$CHANGED" | grep -qE '^(composer\.(json|lock)|Dockerfile)$'; then
    DOCKER_BUILD=true
  fi

  # nginx.conf changed → need to reload nginx
  if echo "$CHANGED" | grep -q '^nginx\.conf'; then
    NGINX_RELOAD=true
  fi

  # deploy.sh changed → full rebuild to be safe
  if echo "$CHANGED" | grep -q '^deploy\.sh'; then
    echo "→ deploy.sh changed — forcing full rebuild"
    ADMIN_BUILD=true; ADMIN_NPM=true
    WIDGET_BUILD=true; WIDGET_NPM=true
    DOCKER_BUILD=true
  fi
fi

echo ""
echo "→ Build plan:"
echo "     admin build:  $ADMIN_BUILD  (npm ci: $ADMIN_NPM)"
echo "     widget build: $WIDGET_BUILD (npm ci: $WIDGET_NPM)"
echo "     docker build: $DOCKER_BUILD"
echo ""

# ── 0. Cleanup ────────────────────────────────────────────────────
echo "[0/7] Cleanup..."
# Only prune very old cache (>7 days) — keep recent layers for fast rebuilds
docker builder prune -f --filter "until=168h" 2>/dev/null || true
docker container prune -f 2>/dev/null || true
echo "    Disk: $(df -h / | awk 'NR==2{print $4}') free"

# ── 1. Build admin SPA ────────────────────────────────────────────
echo ""
if [ "$ADMIN_BUILD" = "true" ]; then
  echo "[1/7] Building admin SPA..."
  cd admin

  if [ "$ADMIN_NPM" = "true" ]; then
    echo "    → npm ci (packages changed)"
    npm ci
  elif [ ! -d node_modules ]; then
    echo "    → npm ci (node_modules missing)"
    npm ci
    ADMIN_NPM=true   # mark so we clean up after
  else
    echo "    → skipping npm ci (packages unchanged, node_modules present)"
  fi

  echo "    → building..."
  VITE_API_URL=/api npm run build:server
  cd ..
  echo "    admin/dist ready ($(du -sh admin/dist | cut -f1))"

  # Free disk only if we did a fresh npm ci
  [ "$ADMIN_NPM" = "true" ] && rm -rf admin/node_modules && echo "    node_modules removed"
else
  echo "[1/7] admin SPA — no changes, skipped"
fi

# ── 2. Build widget ───────────────────────────────────────────────
echo ""
if [ "$WIDGET_BUILD" = "true" ]; then
  echo "[2/7] Building widget..."
  cd widget

  if [ "$WIDGET_NPM" = "true" ]; then
    echo "    → npm ci (packages changed)"
    npm ci
  elif [ ! -d node_modules ]; then
    echo "    → npm ci (node_modules missing)"
    npm ci
    WIDGET_NPM=true  # mark so we clean up after
  else
    echo "    → skipping npm ci (packages unchanged, node_modules present)"
  fi

  echo "    → building..."
  VITE_API_URL=https://yakut54.ru/api VITE_SHOP_ID= npm run build:server
  cd ..
  echo "    widget/dist ready ($(du -sh widget/dist | cut -f1))"

  [ "$WIDGET_NPM" = "true" ] && rm -rf widget/node_modules && echo "    node_modules removed"
else
  echo "[2/7] widget — no changes, skipped"
fi

# ── 3. Start / restart containers ────────────────────────────────
echo ""
echo "[3/7] Starting containers..."
docker compose -f docker-compose.prod.yml up -d db

if [ "$DOCKER_BUILD" = "true" ]; then
  echo "    → rebuilding PHP image (composer deps changed)"
  docker compose -f docker-compose.prod.yml up -d --build --remove-orphans app web
else
  echo "    → restarting containers (no image rebuild)"
  docker compose -f docker-compose.prod.yml up -d --remove-orphans app web
fi

# ── 3.5. Update vendor volume if composer deps changed ───────────
# Docker named volumes не обновляются при пересборке образа: vendor volume
# инициализируется единожды при первом запуске. При изменении composer.json
# нужно запустить composer install внутри контейнера чтобы обновить volume.
if [ "$DOCKER_BUILD" = "true" ]; then
  echo "    → running composer install inside container (vendor volume update)..."
  sleep 3   # ждём пока контейнер поднимется
  docker exec servicebox_app composer install --no-dev --no-interaction --no-scripts 2>&1 | tail -5 \
    && echo "    composer install OK" || echo "    [warn] composer install failed"
fi

# ── 3.6. Restart nginx if config changed ─────────────────────────
# Используем docker restart (не nginx -s reload): git заменяет файл новым инодом,
# и bind mount внутри контейнера всё ещё указывает на старый — reload не помогает.
if [ "$NGINX_RELOAD" = "true" ]; then
  echo "    → nginx.conf changed, restarting web container..."
  docker restart servicebox_web && echo "    nginx restarted OK" || echo "    [warn] nginx restart failed"
fi

# ── 4. Post-build cleanup ────────────────────────────────────────
echo ""
echo "[4/7] Post-build cleanup..."
docker image prune -f 2>/dev/null || true
echo "    Disk: $(df -h / | awk 'NR==2{print $4}') free"

# ── 5. Sync DB password ──────────────────────────────────────────
echo ""
echo "[5/7] Syncing DB password..."
sleep 5
DB_PASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d'=' -f2- | tr -d '"'"'"' ')
docker exec servicebox_db psql -U servicebox \
  -c "ALTER USER servicebox WITH PASSWORD '${DB_PASSWORD:-servicebox}';" \
  && echo "    DB password synced OK" || echo "    [warn] DB password sync skipped"

# ── 6. Run migrations + cache ────────────────────────────────────
echo ""
echo "[6/7] Running migrations..."
docker exec servicebox_app php artisan migrate --force
docker exec servicebox_app php artisan storage:link --force 2>/dev/null || true
docker exec servicebox_app php artisan config:cache
docker exec servicebox_app php artisan route:cache

# ── 6.5. Ensure host nginx allows large uploads ──────────────────
echo ""
echo "[6.5/7] Checking host nginx client_max_body_size..."
NGINX_CONF=$(nginx -t 2>&1 | grep -oP '(?<=configuration file )[^ ]+' | head -1)
[ -z "$NGINX_CONF" ] && NGINX_CONF="/etc/nginx/nginx.conf"
if grep -q 'client_max_body_size' "$NGINX_CONF"; then
  sed -i 's/client_max_body_size\s\+[^;]*/client_max_body_size 15M/' "$NGINX_CONF"
  echo "    → updated existing client_max_body_size to 15M"
else
  sed -i '/http\s*{/a\    client_max_body_size 15M;' "$NGINX_CONF"
  echo "    → added client_max_body_size 15M to http block"
fi
nginx -t && nginx -s reload && echo "    host nginx reloaded OK" || echo "    [warn] nginx reload failed"

# ── 7. Health check ──────────────────────────────────────────────
echo ""
echo "[7/7] Health check..."
sleep 2
curl -sf http://localhost:8000/api/health && echo " → API OK" || echo " → API not responding yet (check docker logs)"

echo ""
echo "=== Deploy complete ==="
echo "    Disk free: $(df -h / | awk 'NR==2{print $4}')"
echo "    Admin:  https://yakut54.ru"
echo "    API:    https://yakut54.ru/api/health"
echo "    Widget: https://yakut54.ru/widget.js"
