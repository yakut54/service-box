#!/bin/bash
set -e

REPO_DIR="/var/www/servicebox"
BRANCH="new-branch"

echo "=== ServiceBox Deploy ==="
echo "→ Dir: $REPO_DIR"
echo "→ Branch: $BRANCH"

cd "$REPO_DIR"

# ── 1. Pull latest code ────────────────────────────────────────
echo ""
echo "[1/7] Pulling latest code..."
git fetch origin
git reset --hard origin/$BRANCH

# ── 2. Build admin SPA ────────────────────────────────────────
echo ""
echo "[2/7] Building admin SPA..."
cd admin
npm ci --prefer-offline
VITE_API_URL=/api npm run build
cd ..
echo "    admin/dist ready ($(du -sh admin/dist | cut -f1))"

# ── 3. Build widget ───────────────────────────────────────────
echo ""
echo "[3/7] Building widget..."
cd widget
npm ci --prefer-offline
npm run build
cd ..
echo "    widget/dist ready ($(du -sh widget/dist | cut -f1))"

# ── 4. Start / restart containers ─────────────────────────────
echo ""
echo "[4/7] Starting containers..."
# Start DB separately (never recreate — keeps existing pgdata volume + password)
docker compose -f docker-compose.prod.yml up -d db
# Rebuild and restart app + web only
docker compose -f docker-compose.prod.yml up -d --build --remove-orphans app web

# ── 5. Sync DB password (peer-auth — no password needed) ─────
echo ""
echo "[5/7] Syncing DB password..."
sleep 5
DB_PASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d'=' -f2- | tr -d '"'"'"' ')
# unix socket → trust auth (no password needed), syncs pgdata password to .env value
docker exec servicebox_db psql -U servicebox \
  -c "ALTER USER servicebox WITH PASSWORD '${DB_PASSWORD:-servicebox}';" \
  && echo "    DB password synced OK" || echo "    [warn] DB password sync skipped"

# ── 6. Run migrations ─────────────────────────────────────────
echo ""
echo "[6/7] Running migrations..."
docker exec servicebox_app php artisan migrate --force
docker exec servicebox_app php artisan config:cache
docker exec servicebox_app php artisan route:cache

# ── 7. Done ───────────────────────────────────────────────────
echo ""
echo "[7/7] Health check..."
sleep 2
curl -sf http://localhost:8000/api/up && echo " → API OK" || echo " → API not responding yet (check docker logs)"

echo ""
echo "=== Deploy complete ==="
echo "    Admin: https://yakut54.ru"
echo "    API:   https://yakut54.ru/api/up"
echo "    Widget: https://yakut54.ru/widget.js"
