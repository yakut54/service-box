-- Seed: Салон "Ой, всё!" — 3 цифровых товара (подарочные сертификаты)
-- Схема: shop_omtgfybbrig5
-- Дата: 2026-05-05

SET search_path TO shop_omtgfybbrig5;

INSERT INTO categories (id, name, slug, sort_order) VALUES
  ('dc000001-0000-0000-0000-000000000001', 'Подарочные сертификаты', 'gift-certificates', 99)
ON CONFLICT (id) DO NOTHING;

INSERT INTO products (id, type, name, description, price, currency, is_active, sort_order, category_id) VALUES
  (
    'dd100001-0000-0000-0000-000000000001',
    'digital',
    'Подарочный сертификат 3 000 ₽',
    'Электронный подарочный сертификат на любую услугу салона. Действует 1 год с момента покупки.',
    300000,
    'RUB',
    true,
    1,
    'dc000001-0000-0000-0000-000000000001'
  ),
  (
    'dd100001-0000-0000-0000-000000000002',
    'digital',
    'Подарочный сертификат 5 000 ₽',
    'Электронный подарочный сертификат на любую услугу салона. Действует 1 год с момента покупки.',
    500000,
    'RUB',
    true,
    2,
    'dc000001-0000-0000-0000-000000000001'
  ),
  (
    'dd100001-0000-0000-0000-000000000003',
    'digital',
    'Подарочный сертификат 10 000 ₽',
    'Электронный подарочный сертификат на любую услугу салона. Хватит на всё! Действует 1 год с момента покупки.',
    1000000,
    'RUB',
    true,
    3,
    'dc000001-0000-0000-0000-000000000001'
  )
ON CONFLICT (id) DO NOTHING;

INSERT INTO products_digital (product_id, delivery_type, access_days) VALUES
  ('dd100001-0000-0000-0000-000000000001', 'download', 365),
  ('dd100001-0000-0000-0000-000000000002', 'download', 365),
  ('dd100001-0000-0000-0000-000000000003', 'download', 365)
ON CONFLICT (product_id) DO NOTHING;
