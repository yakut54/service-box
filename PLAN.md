# ServiceBox — Plan

## Стек

- **Backend:** Laravel 11, PostgreSQL 16 (multi-tenant schemas), Redis, Sanctum
- **Frontend:** Vue 3 + TypeScript, Tailwind CSS (admin), Pinia, Vite
- **Infrastructure:** Docker, Nginx, GitHub Actions (deploy on push to `new-branch`)
- **Server:** root@31.128.39.216, app `/var/www/servicebox/`, домен `https://yakut54.ru`
- **Queue driver:** `sync` (нет async-очередей — все операции синхронные)

---

## Архитектура

```
servicebox/
├── app/              — Laravel API
├── admin/            — SPA для владельца магазина (Vue 3)
├── widget/           — Встраиваемый виджет для сайта клиента (Vue 3, Shadow DOM)
└── database/         — Миграции (глобальные + функция создания tenant-схемы)
```

Каждый магазин — отдельная PostgreSQL-схема `shop_{id}`. Таблицы `customers`, `products`, `orders`, `bookings`, `masters` и др. изолированы per-tenant.

---

## Статус по модулям

### ✅ Готово

| Модуль | Что есть |
|---|---|
| Auth | Регистрация, логин, смена пароля, reset |
| Shop | Настройки магазина, часы работы, timezone (38 зон) |
| Categories | CRUD, drag-n-drop сортировка |
| Products | Физические / цифровые / услуги, фото, цена, акция |
| Orders | Создание, статусы, оплата YooKassa, webhook |
| Customers | Список, детальная карточка, удаление, CSV-экспорт |
| Bookings | Слоты, бронирование через виджет, статусы |
| Masters | CRUD, привязка услуг, расписание |
| Discounts | Промокоды, автоприменение, история использования |
| Reviews | Отзывы с рейтингом через виджет |
| Payments | YooKassa, подписки, история платежей, предоплата бронирований |
| Legal | Документы (оферта, конфиденциальность) на домене магазина |
| Superadmin | Список магазинов, тарифы, выручка платформы |
| Widget | Каталог, корзина, checkout, мои заказы, мои записи |
| Telegram | Уведомления, напоминания, оценки, plan gate |
| MAX | Уведомления владельцу, подключение по коду, confirm/cancel | ✅ Готово |
| UX | Автополлинг в админке, CSV-экспорт заказов и клиентов |
| Тёмная тема | Переключатель в админке и виджете |

---

## Telegram-бот (@sb_widget_bot)

**Токен:** `8527648051:AAGPvspeys1_ssPc_5pZAoZ-Mcuwv9l0ioY`
**Webhook:** `https://yakut54.ru/api/webhook/telegram`
**allowed_updates:** `["message", "callback_query"]`

| # | Что | Статус |
|---|---|---|
| 1 | Подключение / отключение бота | ✅ Готово |
| 2 | Уведомления владельцу о новых записях и заказах | ✅ Готово |
| 3 | Клиент привязывает Telegram через deep-link | ✅ Готово |
| 4a | Уведомление клиенту при подтверждении/отмене записи | ✅ Готово |
| 4b | Напоминания клиенту за 24ч и 2ч до записи | ✅ Готово |
| 5 | Запрос оценки через 2ч после окончания записи | ✅ Готово |
| 6 | Plan gate — Telegram только на Start и выше | ✅ Готово |

---

## Кастомный виджет — Дорожная карта

> Цель: лучший встраиваемый виджет на рынке СНГ.
> Исследованы: Stripe, Typeform, Yclients, Altegio, Calendly, Acuity, Intercom, Tidio, Fresha.

### Фаза 1 — Внешний вид (Business) ✅

| # | Что | Статус |
|---|---|---|
| 1.1 | Основной цвет магазина | ✅ |
| 1.2 | Загрузка логотипа | ✅ |
| 1.3 | Скрытие элементов: цена / длительность / мастер / описание | ✅ |
| 1.4 | Скругление кнопок | ✅ |

### Фаза 2 — Embed и интеграция (Business) ✅

| # | Что | Статус |
|---|---|---|
| 2.1 | 3 режима embed: inline / popup / автооткрытие | ✅ |
| 2.2 | Deep links на конкретную услугу | ✅ |
| 2.3 | QR-код из настроек | ✅ |
| 2.4 | Prefill через URL-параметры | ✅ |
| 2.5 | Генератор embed-кода | ✅ |

### Фаза 3 — UX виджета (Business) ✅

| # | Что | Статус |
|---|---|---|
| 3.1 | Conversational flow — один шаг за раз (имя → телефон → подтверждение) | ✅ |
| 3.2 | Сохранение прогресса в localStorage | ✅ |
| 3.3 | Exit intent диалог при закрытии во время записи | ✅ |

### Фаза 4 — Pro-фичи ✅

| # | Что | Статус |
|---|---|---|
| 4.1 | White label — убрать "Powered by ServiceBox" | ✅ |
| 4.2 | Custom CSS — поле для произвольных стилей | ✅ |
| 4.3 | Widget analytics — воронка по шагам | ✅ |

---

## Безопасность — аудит пройден ✅

| Уязвимость | Где | Статус |
|---|---|---|
| SQL injection через schema_name в SET search_path | TenantService | ✅ исправлено |
| HTML injection в Telegram / MAX сообщениях (customer_name, notes) | TelegramService, MaxService | ✅ исправлено |
| Race condition при применении автоскидок | DiscountService::findAutoApply | ✅ исправлено |
| Race condition при бронировании слота | BookingController | ✅ был защищён |
| Replay-атака на вебхук YooKassa | PaymentController | ✅ был защищён |
| Timing attack при проверке OTP | WidgetPhoneVerificationController | ✅ исправлено |
| CSS injection в custom_css | ShopController | ✅ исправлено |
| Перебор email через forgot-password | AuthController | ✅ исправлено |
| Небезопасное расширение файла при загрузке фото | ImageController | ✅ исправлено |
| Отсутствие rate limit на виджет-мутации | routes/api.php | ✅ исправлено |
| schema_name в публичном ответе API | Shop::$hidden | ✅ исправлено |
| SQL injection через schema_name в MAX/Telegram контроллерах | MaxController, TelegramController | ✅ safeSchema() |
| SSRF через logo_url | — | ✅ угрозы нет (сервер URL не фетчит) |

---

## Деплой

1. Пуш в ветку `new-branch`
2. GitHub Actions → SSH на сервер → `git pull` + `composer install` + `php artisan migrate` + перезапуск контейнера
3. Время деплоя: ~1 минута
4. Фронт (admin/widget) деплоится отдельно при изменениях в `admin/` или `widget/`
