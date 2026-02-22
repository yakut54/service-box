#!/bin/bash
set -e

REPO_DIR="/var/www/servicebox"
BRANCH="new-branch"

cd "$REPO_DIR"

# ── Bootstrap: pull latest code, re-exec so the UPDATED script runs ──
if [ -z "$SERVICEBOX_DEPLOY_V" ]; then
  echo "=== ServiceBox Deploy (bootstrap) ==="
  echo "→ Pulling latest code from $BRANCH..."
  git fetch origin
  git reset --hard origin/$BRANCH
  echo "→ Re-executing updated deploy.sh..."
  export SERVICEBOX_DEPLOY_V=2
  exec bash "$REPO_DIR/deploy.sh"
fi

echo "=== ServiceBox Deploy ==="
echo "→ Dir: $REPO_DIR"
echo "→ Branch: $BRANCH"

DEPLOY_VERSION=$(git rev-parse --short=8 HEAD)
echo "→ Version: $DEPLOY_VERSION"

# ── 0. Pre-deploy cleanup ─────────────────────────────────────
echo ""
echo "[0/7] Pre-deploy cleanup..."
docker image prune -f 2>/dev/null || true
docker container prune -f 2>/dev/null || true
echo "    Cleanup done (disk: $(df -h / | awk 'NR==2{print $4}') free)"

# ── 1. Build admin SPA ────────────────────────────────────────
echo ""
echo "[1/7] Building admin SPA..."
cd admin
npm ci
VITE_API_URL=/api npm run build
cd ..
rm -rf admin/node_modules
echo "    admin/dist ready ($(du -sh admin/dist | cut -f1))"

# ── 2. Build widget ───────────────────────────────────────────
echo ""
echo "[2/7] Building widget..."
cd widget
npm ci
npm run build
cd ..
rm -rf widget/node_modules
echo "$DEPLOY_VERSION" > widget/dist/.version
echo "    widget/dist ready ($(du -sh widget/dist | cut -f1)) — version: $DEPLOY_VERSION"

# ── 3. Start / restart containers ─────────────────────────────
echo ""
echo "[3/7] Starting containers..."
docker compose -f docker-compose.prod.yml up -d db
docker compose -f docker-compose.prod.yml up -d --build --remove-orphans app web

# ── 4. Post-build cleanup ─────────────────────────────────────
echo ""
echo "[4/7] Post-build cleanup..."
docker builder prune -f --filter "until=1h" 2>/dev/null || true
docker image prune -f 2>/dev/null || true
echo "    Cleanup done (disk: $(df -h / | awk 'NR==2{print $4}') free)"

# ── 4.1 Сброс кеша Nginx ──────────────────────────────────────
echo ""
echo "[4.1/7] Reloading Nginx..."
docker exec servicebox_web nginx -s reload 2>/dev/null \
  && echo "    Nginx reloaded OK" \
  || echo "    [warn] Nginx reload skipped"

# ── 5. Sync DB password ────────────────────────────────────────
echo ""
echo "[5/7] Syncing DB password..."
sleep 5
DB_PASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d'=' -f2- | tr -d '"'"'"' ')
docker exec servicebox_db psql -U servicebox \
  -c "ALTER USER servicebox WITH PASSWORD '${DB_PASSWORD:-servicebox}';" \
  && echo "    DB password synced OK" || echo "    [warn] DB password sync skipped"

# ── 6. Run migrations ─────────────────────────────────────────
echo ""
echo "[6/7] Running migrations..."
docker exec servicebox_app php artisan migrate --force
docker exec servicebox_app php artisan storage:link --force 2>/dev/null || true
docker exec servicebox_app php artisan config:cache
docker exec servicebox_app php artisan route:cache

# ── 7. Done ───────────────────────────────────────────────────
echo ""
echo "[7/7] Health check..."
sleep 2
curl -sf http://localhost:8000/api/health && echo " → API OK" || echo " → API not responding yet (check docker logs)"

echo ""
echo "=== Deploy complete ==="
echo "    Version:   $DEPLOY_VERSION"
echo "    Disk free: $(df -h / | awk 'NR==2{print $4}')"
echo "    Admin:     https://yakut54.ru"
echo "    API:       https://yakut54.ru/api/up"
echo "    Widget:    https://yakut54.ru/widget.js?v=$DEPLOY_VERSION"