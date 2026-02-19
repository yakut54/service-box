# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ServiceBox is a **multi-tenant SaaS platform** for e-commerce and booking systems. It consists of:
- **Laravel 11 backend** — REST API with per-tenant PostgreSQL schema isolation
- **Admin dashboard** (`admin/`) — Vue 3 + Pinia SPA for shop owners
- **Embeddable widget** (`widget/`) — Vue 3 + Shadow DOM component for third-party sites

---

## Development Commands

### Backend (Laravel)
```bash
php artisan serve              # Start dev server
php artisan migrate            # Run migrations
php artisan tinker             # Interactive REPL
php artisan queue:work         # Process queued jobs
composer install               # Install PHP dependencies
```

### Admin Dashboard (`admin/`)
```bash
cd admin
npm install
npm run dev        # Dev server on http://localhost:3000
npm run build      # vue-tsc type check + Vite build → admin/dist/
npm run preview    # Preview built output
```

### Widget (`widget/`)
```bash
cd widget
npm install
npm run dev        # Dev server on http://localhost:3001
npm run build      # vue-tsc --noEmit + Vite build → widget/dist/widget.js
npm run preview    # Preview built output
```

### Docker
```bash
docker-compose up -d           # Local development stack
docker-compose -f docker-compose.prod.yml up -d   # Production
./deploy.sh                    # Production deployment script
```

---

## Architecture

### Multi-Tenancy
- Each shop gets an isolated PostgreSQL schema
- Tenant is resolved from the `X-Shop-ID` request header via `tenant` middleware
- `TenantService` switches the database schema context per request

### API Routes (`routes/api.php`)
Three groups:
1. **`/auth`** — Public: register, login
2. **`/widget/*`** — Public + tenant-aware (no Bearer token): shop config, products, orders, phone OTP verification
3. **`/admin/*`** — Protected by `auth:sanctum` + `auth.shop` + subscription middlewares: full CRUD for products, orders, customers, bookings, masters, uploads, settings

### Widget Architecture
- Rendered inside a **Shadow DOM** to prevent CSS conflicts on host sites
- Entry: floating action button (FAB) injected into `document.body`; widget content lives in the shadow root
- Auto-initializes from: `data-shop-id` on the `<script>` tag, `<div id="servicebox-widget" data-shop-id="...">`, or `VITE_SHOP_ID` env var (dev only)
- **Deferred config loading**: shop config is fetched on first widget open, not on page load
- Vite builds widget as IIFE (`widget.js`) with CSS inlined (no separate CSS file) — see custom Shadow DOM CSS plugin in `widget/vite.config.ts`

### State Management
**Admin** (`admin/src/stores/`): `auth.ts`, `products.ts`, `orders.ts`, `bookings.ts`
**Widget** (`widget/src/stores/`): `shop.ts` (theme, open/close, config), `cart.ts` (items, totals)

### Authentication
- Laravel Sanctum Bearer tokens
- Admin: token stored in `localStorage`, auto-cleared on 401
- Widget: phone-based OTP verification (OTP sent via Telegram bot), token stored in memory

### API Client (`admin/src/lib/api.ts`)
Singleton `ApiClient` class wrapping `fetch`. Handles Bearer auth, `FormData` for uploads, `ApiError` for typed errors.

### Widget UI Flow
```
FAB → Catalog → ProductDetail → Cart (slide-in drawer) → Checkout → Confirmation
                                                          ↓
                                              BookingCalendar (for services)
                                              MyOrders / MyBookings (customer history)
```

---

## Key Conventions

- **Language**: Code comments and README are in Russian
- **PHP**: Laravel 11, PHP 8.2+, strict typing encouraged
- **Frontend**: Vue 3 Composition API with `<script setup>` syntax, TypeScript strict mode
- **Styling**: Tailwind CSS (admin), plain CSS with CSS custom properties (widget)
- **Widget breakpoints**: 320px / 360px / 400px / 450px — recent commits focus on responsive QA at these widths
- **Path aliases**: `@/` maps to `src/` in both admin and widget (configured in `tsconfig.json` and `vite.config.ts`)
