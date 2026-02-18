#!/bin/bash
set -e

REPO_DIR="/var/www/servicebox"
BRANCH="new-branch"

cd "$REPO_DIR"

# ── Bootstrap: pull latest code, re-exec so the UPDATED script runs ──
# This solves the problem where bash caches the old script while git reset
# is updating the file on disk mid-execution.
if [ -z "$SERVICEBOX_DEPLOY_V" ]; then
  echo "=== ServiceBox Deploy (bootstrap) ==="
  echo "→ Pulling latest code from $BRANCH..."
  git fetch origin
  git reset --hard origin/$BRANCH
  echo "→ Re-executing updated deploy.sh..."
  export SERVICEBOX_DEPLOY_V=2
  exec bash "$REPO_DIR/deploy.sh"
fi

# ═══ Everything below runs from the UPDATED script ═══════════════════

echo "=== ServiceBox Deploy ==="
echo "→ Dir: $REPO_DIR"
echo "→ Branch: $BRANCH"

# ── 1. Build admin SPA ────────────────────────────────────────
echo ""
echo "[1/6] Building admin SPA..."
cd admin
npm ci --prefer-offline
VITE_API_URL=/api npm run build
cd ..
echo "    admin/dist ready ($(du -sh admin/dist | cut -f1))"

# ── 2. Build widget ───────────────────────────────────────────
echo ""
echo "[2/6] Building widget..."
cd widget
npm ci --prefer-offline
npm run build
cd ..
echo "    widget/dist ready ($(du -sh widget/dist | cut -f1))"

# ── 3. Start / restart containers ─────────────────────────────
echo ""
echo "[3/6] Starting containers..."
# Start DB separately (never recreate — keeps existing pgdata volume + password)
docker compose -f docker-compose.prod.yml up -d db
# Rebuild and restart app + web only
docker compose -f docker-compose.prod.yml up -d --build --remove-orphans app web

# ── 4. Sync DB password ────────────────────────────────────────
echo ""
echo "[4/6] Syncing DB password..."
sleep 5
DB_PASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d'=' -f2- | tr -d '"'"'"' ')
# unix socket uses trust auth inside the container — no password needed to connect
docker exec servicebox_db psql -U servicebox \
  -c "ALTER USER servicebox WITH PASSWORD '${DB_PASSWORD:-servicebox}';" \
  && echo "    DB password synced OK" || echo "    [warn] DB password sync skipped"

# ── 5. Run migrations ─────────────────────────────────────────
echo ""
echo "[5/6] Running migrations..."
docker exec servicebox_app php artisan migrate --force
docker exec servicebox_app php artisan storage:link --force 2>/dev/null || true
docker exec servicebox_app php artisan config:cache
docker exec servicebox_app php artisan route:cache

# ── 6. Done ───────────────────────────────────────────────────
echo ""
echo "[6/6] Health check..."
sleep 2
curl -sf http://localhost:8000/api/up && echo " → API OK" || echo " → API not responding yet (check docker logs)"

echo ""
echo "=== Deploy complete ==="
echo "    Admin: https://yakut54.ru"
echo "    API:   https://yakut54.ru/api/up"
echo "    Widget: https://yakut54.ru/widget.js"
