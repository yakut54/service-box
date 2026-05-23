<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Эта миграция решает две задачи:
 * 1. Приводит функцию create_shop_schema к полной актуальной версии
 *    (предыдущие версии отставали на 3+ месяца — новые магазины создавались без многих колонок).
 * 2. Через ADD COLUMN IF NOT EXISTS дотягивает все существующие схемы до нужного состояния.
 *
 * ВАЖНО ДЛЯ БУДУЩИХ РАЗРАБОТЧИКОВ:
 * При добавлении новых колонок нужно:
 *   а) добавить колонку в create_shop_schema ниже (чтобы новые магазины получали её сразу)
 *   б) добавить ALTER TABLE ... ADD COLUMN IF NOT EXISTS в секцию patch (для существующих)
 * Обе части обязательны.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Обновляем create_shop_schema до полной актуальной версии ────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.create_shop_schema(p_schema_name TEXT)
            RETURNS VOID AS $$
            BEGIN
                EXECUTE format('DROP SCHEMA IF EXISTS %I CASCADE', p_schema_name);
                EXECUTE format('CREATE SCHEMA %I', p_schema_name);

                -- categories
                EXECUTE format($sql$
                    CREATE TABLE %I.categories (
                        id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        parent_id   UUID REFERENCES %I.categories(id) ON DELETE CASCADE,
                        name        TEXT NOT NULL,
                        slug        TEXT NOT NULL,
                        description TEXT,
                        image_url   TEXT,
                        is_visible  BOOLEAN NOT NULL DEFAULT TRUE,
                        sort_order  INTEGER NOT NULL DEFAULT 0,
                        deleted_at  TIMESTAMPTZ,
                        created_at  TIMESTAMPTZ DEFAULT NOW(),
                        updated_at  TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name);
                EXECUTE format('CREATE UNIQUE INDEX ON %I.categories(slug) WHERE deleted_at IS NULL', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.categories(parent_id)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.categories(is_visible)', p_schema_name);

                -- products
                EXECUTE format($sql$
                    CREATE TABLE %I.products (
                        id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        type          TEXT NOT NULL CHECK (type IN ('physical','digital','service')),
                        name          TEXT NOT NULL,
                        description   TEXT,
                        price         INTEGER NOT NULL DEFAULT 0,
                        compare_price INTEGER DEFAULT NULL,
                        currency      TEXT DEFAULT 'RUB',
                        image_url     TEXT,
                        is_active     BOOLEAN DEFAULT TRUE,
                        auto_hidden   BOOLEAN DEFAULT FALSE,
                        category_id   UUID REFERENCES %I.categories(id) ON DELETE SET NULL,
                        sort_order    INTEGER DEFAULT 0,
                        created_at    TIMESTAMPTZ DEFAULT NOW(),
                        updated_at    TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.products(is_active)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.products(type)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.products(category_id)', p_schema_name);

                -- products_physical
                EXECUTE format($sql$
                    CREATE TABLE %I.products_physical (
                        product_id      UUID PRIMARY KEY REFERENCES %I.products(id) ON DELETE CASCADE,
                        sku             TEXT,
                        stock_quantity  INTEGER DEFAULT 0,
                        allow_backorder BOOLEAN DEFAULT FALSE,
                        weight_grams    INTEGER,
                        length_cm       NUMERIC(10,2),
                        width_cm        NUMERIC(10,2),
                        height_cm       NUMERIC(10,2),
                        color           TEXT,
                        brand           TEXT,
                        material        TEXT,
                        dimensions      TEXT
                    )
                $sql$, p_schema_name, p_schema_name);

                -- products_digital
                EXECUTE format($sql$
                    CREATE TABLE %I.products_digital (
                        product_id    UUID PRIMARY KEY REFERENCES %I.products(id) ON DELETE CASCADE,
                        delivery_type TEXT DEFAULT 'download',
                        access_days   INTEGER,
                        download_url  TEXT,
                        file_size_bytes BIGINT,
                        file_size_mb  NUMERIC(10,2),
                        file_format   TEXT
                    )
                $sql$, p_schema_name, p_schema_name);

                -- products_service
                EXECUTE format($sql$
                    CREATE TABLE %I.products_service (
                        product_id          UUID PRIMARY KEY REFERENCES %I.products(id) ON DELETE CASCADE,
                        duration_minutes    INTEGER DEFAULT 60,
                        max_concurrent      INTEGER DEFAULT 1,
                        requires_booking    BOOLEAN DEFAULT TRUE,
                        break_minutes       INTEGER DEFAULT 0,
                        requires_prepayment BOOLEAN DEFAULT FALSE
                    )
                $sql$, p_schema_name, p_schema_name);

                -- customers
                EXECUTE format($sql$
                    CREATE TABLE %I.customers (
                        id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        name             TEXT NOT NULL,
                        email            TEXT,
                        phone            TEXT NOT NULL,
                        notes            TEXT,
                        total_orders     INTEGER DEFAULT 0,
                        total_spent      INTEGER DEFAULT 0,
                        telegram_chat_id BIGINT DEFAULT NULL,
                        max_user_id      BIGINT DEFAULT NULL,
                        created_at       TIMESTAMPTZ DEFAULT NOW(),
                        last_order_at    TIMESTAMPTZ
                    )
                $sql$, p_schema_name);
                EXECUTE format('CREATE UNIQUE INDEX ON %I.customers(phone)', p_schema_name);

                -- discounts
                EXECUTE format($sql$
                    CREATE TABLE %I.discounts (
                        id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        name                TEXT NOT NULL,
                        type                TEXT NOT NULL CHECK (type IN ('percent','fixed')),
                        value               INTEGER NOT NULL CHECK (value > 0),
                        code                TEXT,
                        scope               TEXT NOT NULL DEFAULT 'cart' CHECK (scope IN ('cart','product','category')),
                        scope_value         TEXT,
                        min_order_amount    INTEGER NOT NULL DEFAULT 0,
                        max_discount_amount INTEGER,
                        usage_limit         INTEGER,
                        usage_count         INTEGER NOT NULL DEFAULT 0,
                        per_user_limit      INTEGER NOT NULL DEFAULT 1,
                        priority            INTEGER NOT NULL DEFAULT 0,
                        is_active           BOOLEAN NOT NULL DEFAULT TRUE,
                        starts_at           TIMESTAMPTZ,
                        ends_at             TIMESTAMPTZ,
                        created_at          TIMESTAMPTZ DEFAULT NOW(),
                        updated_at          TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name);
                EXECUTE format('CREATE UNIQUE INDEX ON %I.discounts(code) WHERE code IS NOT NULL', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.discounts(is_active)', p_schema_name);

                -- orders
                EXECUTE format($sql$
                    CREATE TABLE %I.orders (
                        id                      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        customer_id             UUID REFERENCES %I.customers(id) ON DELETE SET NULL,
                        status                  TEXT DEFAULT 'pending',
                        total_price             INTEGER DEFAULT 0,
                        discount_id             UUID REFERENCES %I.discounts(id) ON DELETE SET NULL,
                        discount_code           TEXT,
                        discount_amount         INTEGER NOT NULL DEFAULT 0,
                        payment_id              TEXT,
                        payment_url             TEXT,
                        customer_name           TEXT NOT NULL,
                        customer_email          TEXT NOT NULL,
                        customer_phone          TEXT NOT NULL,
                        shipping_address        JSONB,
                        delivery_method         TEXT,
                        delivery_price          INTEGER DEFAULT 0,
                        notes                   TEXT,
                        consent_offer_accepted  BOOLEAN DEFAULT FALSE,
                        consent_privacy_accepted BOOLEAN DEFAULT FALSE,
                        consent_accepted_at     TIMESTAMPTZ,
                        consent_ip              VARCHAR(45),
                        consent_ua              TEXT,
                        created_at              TIMESTAMPTZ DEFAULT NOW(),
                        updated_at              TIMESTAMPTZ DEFAULT NOW(),
                        paid_at                 TIMESTAMPTZ
                    )
                $sql$, p_schema_name, p_schema_name, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.orders(status)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.orders(created_at DESC)', p_schema_name);

                -- order_items
                EXECUTE format($sql$
                    CREATE TABLE %I.order_items (
                        id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        order_id     UUID NOT NULL REFERENCES %I.orders(id) ON DELETE CASCADE,
                        product_id   UUID NOT NULL REFERENCES %I.products(id) ON DELETE RESTRICT,
                        quantity     INTEGER DEFAULT 1,
                        price        INTEGER NOT NULL,
                        product_name TEXT NOT NULL,
                        product_type TEXT NOT NULL
                    )
                $sql$, p_schema_name, p_schema_name, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.order_items(order_id)', p_schema_name);

                -- discount_uses
                EXECUTE format($sql$
                    CREATE TABLE %I.discount_uses (
                        id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        discount_id     UUID NOT NULL REFERENCES %I.discounts(id) ON DELETE CASCADE,
                        order_id        UUID REFERENCES %I.orders(id) ON DELETE SET NULL,
                        customer_phone  TEXT,
                        discount_amount INTEGER NOT NULL,
                        created_at      TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.discount_uses(discount_id)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.discount_uses(customer_phone)', p_schema_name);

                -- reviews
                EXECUTE format($sql$
                    CREATE TABLE %I.reviews (
                        id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        product_id    UUID NOT NULL REFERENCES %I.products(id) ON DELETE CASCADE,
                        customer_id   UUID REFERENCES %I.customers(id) ON DELETE SET NULL,
                        order_id      UUID REFERENCES %I.orders(id) ON DELETE SET NULL,
                        customer_name TEXT NOT NULL,
                        rating        SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
                        text          TEXT,
                        is_published  BOOLEAN NOT NULL DEFAULT FALSE,
                        created_at    TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name, p_schema_name, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.reviews(product_id, is_published)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.reviews(is_published)', p_schema_name);

                -- masters
                EXECUTE format($sql$
                    CREATE TABLE %I.masters (
                        id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        name           TEXT NOT NULL,
                        phone          TEXT,
                        email          TEXT,
                        specialization TEXT,
                        avatar_url     TEXT,
                        is_active      BOOLEAN DEFAULT TRUE,
                        sort_order     INTEGER DEFAULT 0,
                        user_id        UUID DEFAULT NULL,
                        created_at     TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name);

                -- bookings
                EXECUTE format($sql$
                    CREATE TABLE %I.bookings (
                        id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        service_id               UUID NOT NULL REFERENCES %I.products(id) ON DELETE RESTRICT,
                        customer_id              UUID REFERENCES %I.customers(id) ON DELETE SET NULL,
                        master_id                UUID REFERENCES %I.masters(id) ON DELETE SET NULL,
                        start_time               TIMESTAMPTZ NOT NULL,
                        end_time                 TIMESTAMPTZ NOT NULL,
                        status                   TEXT DEFAULT 'pending',
                        payment_id               TEXT,
                        payment_url              VARCHAR(500),
                        customer_name            TEXT NOT NULL,
                        customer_phone           TEXT NOT NULL,
                        customer_email           TEXT,
                        notes                    TEXT,
                        consent_offer_accepted   BOOLEAN DEFAULT FALSE,
                        consent_privacy_accepted BOOLEAN DEFAULT FALSE,
                        consent_accepted_at      TIMESTAMP,
                        consent_ip               VARCHAR(45),
                        consent_ua               VARCHAR(500),
                        reminder_24h_sent        BOOLEAN DEFAULT FALSE,
                        reminder_2h_sent         BOOLEAN DEFAULT FALSE,
                        rating_sent              BOOLEAN DEFAULT FALSE,
                        owner_completion_sent    BOOLEAN DEFAULT FALSE,
                        paid_at                  TIMESTAMPTZ,
                        created_at               TIMESTAMPTZ DEFAULT NOW(),
                        updated_at               TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name, p_schema_name, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.bookings(start_time, end_time)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.bookings(master_id, start_time)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.bookings(status)', p_schema_name);

                -- master_services
                EXECUTE format($sql$
                    CREATE TABLE %I.master_services (
                        master_id  UUID NOT NULL REFERENCES %I.masters(id) ON DELETE CASCADE,
                        product_id UUID NOT NULL REFERENCES %I.products(id) ON DELETE CASCADE,
                        PRIMARY KEY (master_id, product_id)
                    )
                $sql$, p_schema_name, p_schema_name, p_schema_name);

                -- telegram_link_tokens
                EXECUTE format($sql$
                    CREATE TABLE %I.telegram_link_tokens (
                        token          TEXT PRIMARY KEY,
                        customer_phone TEXT NOT NULL,
                        expires_at     TIMESTAMPTZ NOT NULL,
                        used           BOOLEAN DEFAULT FALSE,
                        created_at     TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name);

                -- widget_analytics
                EXECUTE format($sql$
                    CREATE TABLE %I.widget_analytics (
                        id         BIGSERIAL PRIMARY KEY,
                        session_id TEXT NOT NULL,
                        event      TEXT NOT NULL,
                        product_id UUID,
                        meta       JSONB DEFAULT '{}',
                        created_at TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.widget_analytics(session_id)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.widget_analytics(event, created_at)', p_schema_name);

            END;
            $$ LANGUAGE plpgsql;
SQL
        );

        // ── 2. Патчим все существующие схемы через ADD COLUMN IF NOT EXISTS ───
        $schemas = DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            // Только схемы вида shop_<строчные буквы и цифры>
            if (!preg_match('/^shop_[a-z0-9]+$/', $s)) {
                Log::warning("fix_shop_schema: пропускаем подозрительную схему: {$s}");
                continue;
            }

            // products
            DB::statement("ALTER TABLE \"{$s}\".products ADD COLUMN IF NOT EXISTS compare_price INTEGER DEFAULT NULL");
            DB::statement("ALTER TABLE \"{$s}\".products ADD COLUMN IF NOT EXISTS auto_hidden BOOLEAN DEFAULT FALSE");

            // products_physical
            DB::statement("ALTER TABLE \"{$s}\".products_physical ADD COLUMN IF NOT EXISTS color TEXT");
            DB::statement("ALTER TABLE \"{$s}\".products_physical ADD COLUMN IF NOT EXISTS brand TEXT");
            DB::statement("ALTER TABLE \"{$s}\".products_physical ADD COLUMN IF NOT EXISTS material TEXT");
            DB::statement("ALTER TABLE \"{$s}\".products_physical ADD COLUMN IF NOT EXISTS dimensions TEXT");

            // products_digital
            DB::statement("ALTER TABLE \"{$s}\".products_digital ADD COLUMN IF NOT EXISTS file_size_mb NUMERIC(10,2)");
            DB::statement("ALTER TABLE \"{$s}\".products_digital ADD COLUMN IF NOT EXISTS file_format TEXT");

            // products_service
            DB::statement("ALTER TABLE \"{$s}\".products_service ADD COLUMN IF NOT EXISTS break_minutes INTEGER DEFAULT 0");
            DB::statement("ALTER TABLE \"{$s}\".products_service ADD COLUMN IF NOT EXISTS requires_prepayment BOOLEAN DEFAULT FALSE");

            // customers
            DB::statement("ALTER TABLE \"{$s}\".customers ADD COLUMN IF NOT EXISTS telegram_chat_id BIGINT DEFAULT NULL");
            DB::statement("ALTER TABLE \"{$s}\".customers ADD COLUMN IF NOT EXISTS max_user_id BIGINT DEFAULT NULL");

            // discounts
            DB::statement("ALTER TABLE \"{$s}\".discounts ADD COLUMN IF NOT EXISTS priority INTEGER NOT NULL DEFAULT 0");

            // orders
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS discount_id UUID");
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS discount_code TEXT");
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS discount_amount INTEGER NOT NULL DEFAULT 0");
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS consent_offer_accepted BOOLEAN DEFAULT FALSE");
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS consent_privacy_accepted BOOLEAN DEFAULT FALSE");
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS consent_accepted_at TIMESTAMPTZ");
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS consent_ip VARCHAR(45)");
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS consent_ua TEXT");
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS delivery_method TEXT");
            DB::statement("ALTER TABLE \"{$s}\".orders ADD COLUMN IF NOT EXISTS delivery_price INTEGER DEFAULT 0");

            // masters
            DB::statement("ALTER TABLE \"{$s}\".masters ADD COLUMN IF NOT EXISTS user_id UUID DEFAULT NULL");

            // bookings
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS consent_offer_accepted BOOLEAN DEFAULT FALSE");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS consent_privacy_accepted BOOLEAN DEFAULT FALSE");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS consent_accepted_at TIMESTAMP");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS consent_ip VARCHAR(45)");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS consent_ua VARCHAR(500)");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS reminder_24h_sent BOOLEAN DEFAULT FALSE");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS reminder_2h_sent BOOLEAN DEFAULT FALSE");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS rating_sent BOOLEAN DEFAULT FALSE");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS owner_completion_sent BOOLEAN DEFAULT FALSE");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS payment_url VARCHAR(500)");
            DB::statement("ALTER TABLE \"{$s}\".bookings ADD COLUMN IF NOT EXISTS paid_at TIMESTAMPTZ");

            // Создаём таблицы которых может не быть

            DB::statement("
                CREATE TABLE IF NOT EXISTS \"{$s}\".master_services (
                    master_id  UUID NOT NULL REFERENCES \"{$s}\".masters(id) ON DELETE CASCADE,
                    product_id UUID NOT NULL REFERENCES \"{$s}\".products(id) ON DELETE CASCADE,
                    PRIMARY KEY (master_id, product_id)
                )
            ");

            DB::statement("
                CREATE TABLE IF NOT EXISTS \"{$s}\".telegram_link_tokens (
                    token          TEXT PRIMARY KEY,
                    customer_phone TEXT NOT NULL,
                    expires_at     TIMESTAMPTZ NOT NULL,
                    used           BOOLEAN DEFAULT FALSE,
                    created_at     TIMESTAMPTZ DEFAULT NOW()
                )
            ");

            DB::statement("
                CREATE TABLE IF NOT EXISTS \"{$s}\".widget_analytics (
                    id         BIGSERIAL PRIMARY KEY,
                    session_id TEXT NOT NULL,
                    event      TEXT NOT NULL,
                    product_id UUID,
                    meta       JSONB DEFAULT '{}',
                    created_at TIMESTAMPTZ DEFAULT NOW()
                )
            ");
        }
    }

    public function down(): void
    {
        // Откат не реализован — удалять колонки с данными опасно
    }
};
