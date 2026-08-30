--
-- PostgreSQL database dump
--

-- Dumped from database version 16.13
-- Dumped by pg_dump version 16.13

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA IF NOT EXISTS public;


--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA public IS 'standard public schema';


--
-- Name: create_shop_schema(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.create_shop_schema(p_schema_name text) RETURNS void
    LANGUAGE plpgsql
    AS $_$
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
                        dimensions      TEXT,
                        sale_mode            TEXT NOT NULL DEFAULT 'piece' CHECK (sale_mode IN ('piece', 'weight_fixed', 'weight_variable')),
                        weight_step_grams    INTEGER DEFAULT 100,
                        weight_min_grams     INTEGER DEFAULT 100,
                        weight_max_grams     INTEGER DEFAULT 5000
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

                -- product_images
                EXECUTE format($sql$
                    CREATE TABLE %I.product_images (
                        id         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        product_id UUID NOT NULL REFERENCES %I.products(id) ON DELETE CASCADE,
                        url        TEXT NOT NULL,
                        sort_order INTEGER NOT NULL DEFAULT 0,
                        created_at TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.product_images(product_id)', p_schema_name);

                -- customers
                EXECUTE format($sql$
                    CREATE TABLE %I.customers (
                        id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        name             TEXT NOT NULL,
                        email            TEXT,
                        phone            TEXT NOT NULL,
                        notes            TEXT,
                        avatar_url       TEXT,
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
                        commission_amount       INTEGER NOT NULL DEFAULT 0,
                        payout_status           TEXT NOT NULL DEFAULT 'pending' CHECK (payout_status IN ('pending','transferred','failed')),
                        discount_id             UUID REFERENCES %I.discounts(id) ON DELETE SET NULL,
                        discount_code           TEXT,
                        discount_amount         INTEGER NOT NULL DEFAULT 0,
                        payment_id              TEXT,
                        payment_url             TEXT,
                        customer_name           TEXT NOT NULL,
                        customer_email          TEXT,
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
                        product_type TEXT NOT NULL,
                        weight_grams INTEGER
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
                        salary_type    VARCHAR(10) NOT NULL DEFAULT 'percent',
                        salary_rate    NUMERIC(10,2) NOT NULL DEFAULT 0,
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

                -- customer_addresses
                EXECUTE format($sql$
                    CREATE TABLE %I.customer_addresses (
                        id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        customer_id UUID NOT NULL REFERENCES %I.customers(id) ON DELETE CASCADE,
                        label       TEXT,
                        city        TEXT NOT NULL,
                        street      TEXT NOT NULL,
                        building    TEXT NOT NULL,
                        apartment   TEXT,
                        postal_code TEXT,
                        is_default  BOOLEAN NOT NULL DEFAULT FALSE,
                        created_at  TIMESTAMPTZ DEFAULT NOW(),
                        updated_at  TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.customer_addresses(customer_id)', p_schema_name);

                -- customer_sessions
                EXECUTE format($sql$
                    CREATE TABLE %I.customer_sessions (
                        id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        customer_id UUID NOT NULL REFERENCES %I.customers(id) ON DELETE CASCADE,
                        token       TEXT NOT NULL,
                        expires_at  TIMESTAMPTZ NOT NULL,
                        created_at  TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name);
                EXECUTE format('CREATE UNIQUE INDEX ON %I.customer_sessions(token)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.customer_sessions(customer_id)', p_schema_name);

                -- chat_threads
                EXECUTE format($sql$
                    CREATE TABLE %I.chat_threads (
                        id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        customer_id           UUID NOT NULL REFERENCES %I.customers(id) ON DELETE CASCADE,
                        last_message_at       TIMESTAMPTZ,
                        last_message_preview  TEXT,
                        unread_by_shop        INTEGER NOT NULL DEFAULT 0,
                        unread_by_customer    INTEGER NOT NULL DEFAULT 0,
                        shop_last_read_at     TIMESTAMPTZ,
                        customer_last_read_at TIMESTAMPTZ,
                        is_blocked_by_shop    BOOLEAN NOT NULL DEFAULT FALSE,
                        created_at            TIMESTAMPTZ DEFAULT NOW(),
                        updated_at            TIMESTAMPTZ DEFAULT NOW()
                    )
                $sql$, p_schema_name, p_schema_name);
                EXECUTE format('CREATE UNIQUE INDEX ON %I.chat_threads(customer_id)', p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.chat_threads(last_message_at DESC)', p_schema_name);

                -- chat_messages
                EXECUTE format($sql$
                    CREATE TABLE %I.chat_messages (
                        id                 UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                        thread_id          UUID NOT NULL REFERENCES %I.chat_threads(id) ON DELETE CASCADE,
                        sender_type        TEXT NOT NULL CHECK (sender_type IN ('shop', 'customer')),
                        sender_staff_id    UUID REFERENCES public.shop_staff(id) ON DELETE SET NULL,
                        client_message_id  UUID,
                        body               TEXT,
                        image_url          TEXT,
                        status             TEXT NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'read')),
                        reply_to_message_id UUID REFERENCES %I.chat_messages(id) ON DELETE SET NULL,
                        edited_at          TIMESTAMPTZ,
                        created_at         TIMESTAMPTZ DEFAULT NOW(),
                        CONSTRAINT chat_messages_not_empty CHECK (body IS NOT NULL OR image_url IS NOT NULL)
                    )
                $sql$, p_schema_name, p_schema_name, p_schema_name);
                EXECUTE format('CREATE INDEX ON %I.chat_messages(thread_id, created_at)', p_schema_name);
                EXECUTE format('CREATE UNIQUE INDEX ON %I.chat_messages(thread_id, client_message_id) WHERE client_message_id IS NOT NULL', p_schema_name);

            END;
            $_$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE IF NOT EXISTS public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE IF NOT EXISTS public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: mail_failures; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.mail_failures (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    shop_id uuid NOT NULL,
    entity_type character varying(255) NOT NULL,
    entity_id uuid,
    recipient_type character varying(255) NOT NULL,
    recipient_email character varying(255),
    error_message text,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT mail_failures_entity_type_check CHECK (((entity_type)::text = ANY ((ARRAY['order'::character varying, 'booking'::character varying, 'config'::character varying])::text[]))),
    CONSTRAINT mail_failures_recipient_type_check CHECK (((recipient_type)::text = ANY ((ARRAY['shop_owner'::character varying, 'buyer'::character varying, 'platform'::character varying])::text[])))
);


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE IF NOT EXISTS public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE IF NOT EXISTS public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: shop_features; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.shop_features (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    shop_id uuid NOT NULL,
    feature_key character varying(255) NOT NULL,
    enabled boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: shop_staff; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.shop_staff (
    id uuid NOT NULL,
    shop_id uuid NOT NULL,
    user_id uuid,
    role character varying(20) DEFAULT 'admin'::character varying NOT NULL,
    invite_email character varying(255),
    invite_name character varying(255),
    avatar_url character varying(1000),
    phone character varying(20),
    last_login_at timestamp(0) without time zone,
    invite_token character varying(64),
    accepted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    invite_expires_at timestamp(0) without time zone,
    master_id uuid,
    telegram_chat_id bigint,
    max_user_id bigint,
    messenger_link_token character varying(64),
    messenger_link_token_expires_at timestamp(0) without time zone
);


--
-- Name: shops; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.shops (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    domain character varying(255),
    api_key uuid NOT NULL,
    schema_name character varying(255) NOT NULL,
    telegram_chat_id bigint,
    telegram_bot_connected boolean DEFAULT false NOT NULL,
    payment_provider character varying(255) DEFAULT 'yookassa'::character varying NOT NULL,
    yookassa_shop_id character varying(255),
    yookassa_secret_key character varying(255),
    robokassa_login character varying(255),
    robokassa_password1 character varying(255),
    robokassa_password2 character varying(255),
    widget_config json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    work_start character varying(5) DEFAULT '09:00'::character varying NOT NULL,
    work_end character varying(5) DEFAULT '20:00'::character varying NOT NULL,
    slot_duration integer DEFAULT 30 NOT NULL,
    timezone character varying(64) DEFAULT 'Europe/Moscow'::character varying NOT NULL,
    legal_config jsonb,
    max_chat_id bigint,
    max_bot_connected boolean DEFAULT false NOT NULL,
    delivery_settings jsonb,
    min_booking_notice integer DEFAULT 0 NOT NULL,
    prepayment_enabled boolean DEFAULT false NOT NULL,
    prepayment_amount integer DEFAULT 0 NOT NULL,
    hide_customer_phone boolean DEFAULT false NOT NULL,
    chat_customer_delete_enabled boolean DEFAULT false NOT NULL,
    reviews_last_seen_at timestamp(0) without time zone,
    mail_failures_last_seen_at timestamp(0) without time zone,
    CONSTRAINT shops_payment_provider_check CHECK (((payment_provider)::text = ANY ((ARRAY['yookassa'::character varying, 'robokassa'::character varying, 'cloudpayments'::character varying])::text[])))
);


--
-- Name: telegram_codes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.telegram_codes (
    id uuid NOT NULL,
    code character varying(255) NOT NULL,
    shop_id uuid NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    used boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: telegram_messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.telegram_messages (
    id uuid NOT NULL,
    shop_id uuid NOT NULL,
    telegram_message_id bigint,
    telegram_chat_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    entity_id uuid,
    entity_type character varying(255),
    message_text text NOT NULL,
    inline_keyboard json,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    user_action character varying(255),
    user_action_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    retry_count integer DEFAULT 0 NOT NULL,
    last_retry_at timestamp(0) without time zone,
    CONSTRAINT telegram_messages_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'sent'::character varying, 'failed'::character varying, 'delivered'::character varying])::text[]))),
    CONSTRAINT telegram_messages_type_check CHECK (((type)::text = ANY ((ARRAY['order_notification'::character varying, 'booking_notification'::character varying, 'booking_reminder'::character varying, 'payment_success'::character varying, 'system_notification'::character varying])::text[])))
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE IF NOT EXISTS public.users (
    id uuid NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    terms_accepted_at timestamp(0) with time zone,
    terms_accepted_ip character varying(45),
    is_superadmin boolean DEFAULT false NOT NULL
);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: mail_failures mail_failures_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mail_failures
    ADD CONSTRAINT mail_failures_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: shop_features shop_features_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_features
    ADD CONSTRAINT shop_features_pkey PRIMARY KEY (id);


--
-- Name: shop_features shop_features_shop_id_feature_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_features
    ADD CONSTRAINT shop_features_shop_id_feature_key_unique UNIQUE (shop_id, feature_key);


--
-- Name: shop_staff shop_staff_invite_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_staff
    ADD CONSTRAINT shop_staff_invite_token_unique UNIQUE (invite_token);


--
-- Name: shop_staff shop_staff_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_staff
    ADD CONSTRAINT shop_staff_pkey PRIMARY KEY (id);


--
-- Name: shop_staff shop_staff_shop_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_staff
    ADD CONSTRAINT shop_staff_shop_id_user_id_unique UNIQUE (shop_id, user_id);


--
-- Name: shops shops_api_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shops
    ADD CONSTRAINT shops_api_key_unique UNIQUE (api_key);


--
-- Name: shops shops_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shops
    ADD CONSTRAINT shops_pkey PRIMARY KEY (id);


--
-- Name: shops shops_schema_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shops
    ADD CONSTRAINT shops_schema_name_unique UNIQUE (schema_name);


--
-- Name: telegram_codes telegram_codes_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_codes
    ADD CONSTRAINT telegram_codes_code_unique UNIQUE (code);


--
-- Name: telegram_codes telegram_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_codes
    ADD CONSTRAINT telegram_codes_pkey PRIMARY KEY (id);


--
-- Name: telegram_messages telegram_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_messages
    ADD CONSTRAINT telegram_messages_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: mail_failures_shop_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX mail_failures_shop_id_created_at_index ON public.mail_failures USING btree (shop_id, created_at);


--
-- Name: password_reset_tokens_email_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX password_reset_tokens_email_index ON public.password_reset_tokens USING btree (email);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: shop_features_shop_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shop_features_shop_id_index ON public.shop_features USING btree (shop_id);


--
-- Name: shop_staff_invite_token_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shop_staff_invite_token_index ON public.shop_staff USING btree (invite_token);


--
-- Name: shop_staff_messenger_link_token_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shop_staff_messenger_link_token_index ON public.shop_staff USING btree (messenger_link_token);


--
-- Name: shops_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX shops_user_id_index ON public.shops USING btree (user_id);



--
-- Name: telegram_codes_code_used_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_codes_code_used_index ON public.telegram_codes USING btree (code, used);


--
-- Name: telegram_codes_shop_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_codes_shop_id_index ON public.telegram_codes USING btree (shop_id);


--
-- Name: telegram_messages_entity_type_entity_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_messages_entity_type_entity_id_index ON public.telegram_messages USING btree (entity_type, entity_id);


--
-- Name: telegram_messages_shop_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_messages_shop_id_index ON public.telegram_messages USING btree (shop_id);


--
-- Name: telegram_messages_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_messages_status_index ON public.telegram_messages USING btree (status);


--
-- Name: telegram_messages_status_retry_count_last_retry_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_messages_status_retry_count_last_retry_at_index ON public.telegram_messages USING btree (status, retry_count, last_retry_at);


--
-- Name: telegram_messages_telegram_chat_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_messages_telegram_chat_id_index ON public.telegram_messages USING btree (telegram_chat_id);


--
-- Name: telegram_messages_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX telegram_messages_type_index ON public.telegram_messages USING btree (type);


--
-- Name: shop_features shop_features_shop_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_features
    ADD CONSTRAINT shop_features_shop_id_foreign FOREIGN KEY (shop_id) REFERENCES public.shops(id) ON DELETE CASCADE;


--
-- Name: shop_staff shop_staff_shop_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_staff
    ADD CONSTRAINT shop_staff_shop_id_foreign FOREIGN KEY (shop_id) REFERENCES public.shops(id) ON DELETE CASCADE;


--
-- Name: shop_staff shop_staff_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.shop_staff
    ADD CONSTRAINT shop_staff_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: telegram_codes telegram_codes_shop_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_codes
    ADD CONSTRAINT telegram_codes_shop_id_foreign FOREIGN KEY (shop_id) REFERENCES public.shops(id) ON DELETE CASCADE;


--
-- Name: telegram_messages telegram_messages_shop_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.telegram_messages
    ADD CONSTRAINT telegram_messages_shop_id_foreign FOREIGN KEY (shop_id) REFERENCES public.shops(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--
