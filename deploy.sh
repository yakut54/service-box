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
echo "[1/6] Pulling latest code..."
git fetch origin
git reset --hard origin/$BRANCH

# ── 2. Build admin SPA ────────────────────────────────────────
echo ""
echo "[2/6] Building admin SPA..."
cd admin
npm ci --prefer-offline
VITE_API_URL=/api npm run build
cd ..
echo "    admin/dist ready ($(du -sh admin/dist | cut -f1))"

# ── 3. Build widget ───────────────────────────────────────────
echo ""
echo "[3/6] Building widget..."
cd widget
npm ci --prefer-offline
npm run build
cd ..
echo "    widget/dist ready ($(du -sh widget/dist | cut -f1))"

# ── 4. Start / restart containers ─────────────────────────────
echo ""
echo "[4/6] Starting containers..."
docker compose -f docker-compose.prod.yml up -d --build --remove-orphans

# ── 5. Wait for DB, run migrations ───────────────────────────
echo ""
echo "[5/6] Running migrations..."
sleep 5
docker exec servicebox_app php artisan migrate --force
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
