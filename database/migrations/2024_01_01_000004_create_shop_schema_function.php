<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.create_shop_schema(p_schema_name TEXT)
            RETURNS VOID AS $$
            BEGIN
                EXECUTE format('DROP SCHEMA IF EXISTS %I CASCADE', p_schema_name);
                EXECUTE format('CREATE SCHEMA %I', p_schema_name);

                -- products
                EXECUTE format($sql$
                    CREATE TABLE %I.products (
                        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        type TEXT NOT NULL CHECK (type IN ('physical', 'digital', 'service')),
                        name TEXT NOT NULL,
                        description TEXT,
                        price INTEGER NOT NULL DEFAULT 0,
                        currency TEXT DEFAULT 'RUB',
                        image_url TEXT,
                        is_active BOOLEAN DEFAULT TRUE,
                        category TEXT,
                        sort_order INTEGER DEFAULT 0,
                        created_at TIMESTAMPTZ DEFAULT NOW(),
                        updated_at TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name);

                EXECUTE format('CREATE INDEX ON %I.products(is_active)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.products(type)', p_schema_name);

                -- products_physical
                EXECUTE format($sql$
                    CREATE TABLE %I.products_physical (
                        product_id UUID PRIMARY KEY REFERENCES %I.products(id) ON DELETE CASCADE,
                        sku TEXT,
                        stock_quantity INTEGER DEFAULT 0,
                        allow_backorder BOOLEAN DEFAULT FALSE,
                        weight_grams INTEGER,
                        length_cm NUMERIC(10,2),
                        width_cm NUMERIC(10,2),
                        height_cm NUMERIC(10,2)
                    )
                $sql$, p_schema_name, p_schema_name);

                -- products_digital
                EXECUTE format($sql$
                    CREATE TABLE %I.products_digital (
                        product_id UUID PRIMARY KEY REFERENCES %I.products(id) ON DELETE CASCADE,
                        delivery_type TEXT DEFAULT 'download',
                        access_days INTEGER,
                        download_url TEXT,
                        file_size_bytes BIGINT
                    )
                $sql$, p_schema_name, p_schema_name);

                -- products_service
                EXECUTE format($sql$
                    CREATE TABLE %I.products_service (
                        product_id UUID PRIMARY KEY REFERENCES %I.products(id) ON DELETE CASCADE,
                        duration_minutes INTEGER DEFAULT 60,
                        max_concurrent INTEGER DEFAULT 1,
                        requires_booking BOOLEAN DEFAULT TRUE
                    )
                $sql$, p_schema_name, p_schema_name);

                -- customers
                EXECUTE format($sql$
                    CREATE TABLE %I.customers (
                        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        name TEXT NOT NULL,
                        email TEXT,
                        phone TEXT NOT NULL,
                        notes TEXT,
                        total_orders INTEGER DEFAULT 0,
                        total_spent INTEGER DEFAULT 0,
                        created_at TIMESTAMPTZ DEFAULT NOW(),
                        last_order_at TIMESTAMPTZ
                    )
                $sql$, p_schema_name);

                EXECUTE format('CREATE UNIQUE INDEX ON %I.customers(phone)', p_schema_name);

                -- orders
                EXECUTE format($sql$
                    CREATE TABLE %I.orders (
                        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        customer_id UUID REFERENCES %I.customers(id) ON DELETE SET NULL,
                        status TEXT DEFAULT 'pending',
                        total_price INTEGER DEFAULT 0,
                        payment_id TEXT,
                        payment_url TEXT,
                        customer_name TEXT NOT NULL,
                        customer_email TEXT NOT NULL,
                        customer_phone TEXT NOT NULL,
                        shipping_address JSONB,
                        notes TEXT,
                        created_at TIMESTAMPTZ DEFAULT NOW(),
                        updated_at TIMESTAMPTZ DEFAULT NOW(),
                        paid_at TIMESTAMPTZ
                    )
                $sql$, p_schema_name, p_schema_name);

                EXECUTE format('CREATE INDEX ON %I.orders(status)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.orders(created_at DESC)', p_schema_name);

                -- order_items
                EXECUTE format($sql$
                    CREATE TABLE %I.order_items (
                        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        order_id UUID NOT NULL REFERENCES %I.orders(id) ON DELETE CASCADE,
                        product_id UUID NOT NULL REFERENCES %I.products(id) ON DELETE RESTRICT,
                        quantity INTEGER DEFAULT 1,
                        price INTEGER NOT NULL,
                        product_name TEXT NOT NULL,
                        product_type TEXT NOT NULL
                    )
                $sql$, p_schema_name, p_schema_name, p_schema_name);

                EXECUTE format('CREATE INDEX ON %I.order_items(order_id)', p_schema_name);

                -- masters
                EXECUTE format($sql$
                    CREATE TABLE %I.masters (
                        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        name TEXT NOT NULL,
                        phone TEXT,
                        email TEXT,
                        specialization TEXT,
                        avatar_url TEXT,
                        is_active BOOLEAN DEFAULT TRUE,
                        sort_order INTEGER DEFAULT 0,
                        created_at TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name);

                -- bookings
                EXECUTE format($sql$
                    CREATE TABLE %I.bookings (
                        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        service_id UUID NOT NULL REFERENCES %I.products(id) ON DELETE RESTRICT,
                        customer_id UUID REFERENCES %I.customers(id) ON DELETE SET NULL,
                        master_id UUID REFERENCES %I.masters(id) ON DELETE SET NULL,
                        start_time TIMESTAMPTZ NOT NULL,
                        end_time TIMESTAMPTZ NOT NULL,
                        status TEXT DEFAULT 'pending',
                        payment_id TEXT,
                        customer_name TEXT NOT NULL,
                        customer_phone TEXT NOT NULL,
                        customer_email TEXT,
                        notes TEXT,
                        created_at TIMESTAMPTZ DEFAULT NOW(),
                        updated_at TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name, p_schema_name, p_schema_name);

                EXECUTE format('CREATE INDEX ON %I.bookings(start_time, end_time)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.bookings(master_id, start_time)', p_schema_name);

            END;
            $$ LANGUAGE plpgsql;
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.create_shop_schema(TEXT)');
    }
};
