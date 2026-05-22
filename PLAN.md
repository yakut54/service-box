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
| Bookings | Слоты, бронирование через виджет, статусы; поиск по клиенту/телефону; календарь по мастерам (Yclients-стиль) |
| Masters | CRUD, привязка услуг, расписание |
| Discounts | Промокоды, автоприменение, история использования |
| Reviews | Отзывы с рейтингом через виджет |
| Payments | YooKassa, подписки, история платежей, предоплата бронирований |
| Legal | Документы (оферта, конфиденциальность) на домене магазина |
| Superadmin | Список магазинов, тарифы, выручка платформы |
| Widget | Каталог, корзина, checkout, мои заказы, мои записи |
| Telegram | Уведомления, напоминания, оценки, plan gate |
| MAX | Уведомления владельцу, подключение по коду, confirm/cancel |
| Команда (Roles) | Владелец приглашает администраторов по email; admin-токен, plan gate Business/Pro |
| UX | Автополлинг в админке, CSV-экспорт заказов и клиентов |
| Тёмная тема | Переключатель в админке и виджете |

---

## 🔜 Портал мастера — Дорожная карта

> Исследованы конкуренты: Yclients, Dikidi, Altegio, Fresha, SimplyBook.
> Вывод: все используют ту же систему с отдельной ролью. Telegram для мастеров — дыра в рынке, конкурентное преимущество.
> Архитектура: роль `master` в `shop_staff` + `master_id` FK на запись мастера в tenant-схеме.

### Этап М1 — Backend: связка мастера с аккаунтом

| # | Что | Статус |
|---|---|---|
| М1.1 | Миграция: `master_id` (nullable UUID) в таблицу `shop_staff` | ✅ |
| М1.2 | `StaffController`: пригласить мастера по email с привязкой к master_id | ✅ |
| М1.3 | `InviteController`: при принятии приглашения с ролью `master` — записать `user_id` в `masters` (tenant) | ✅ |
| М1.4 | `resolveShopAndRole` возвращает роль `master` | ✅ (SetShopFromAuth автоматически) |
| М1.5 | API `GET /admin/bookings` фильтрует по master_id если роль `master` | ✅ |
| М1.6 | Кнопка "Пригласить" на карточке мастера в MastersView | ✅ |

### Этап М2 — Frontend: личный кабинет мастера

> Mobile-first. Отдельный layout — не admin-панель. Открывается по той же ссылке, но рендерит другой вид.

| # | Что | Статус |
|---|---|---|
| М2.1 | `MasterLayout.vue` — упрощённый layout: только шапка + контент, без сайдбара | ✅ |
| М2.2 | `MasterScheduleView.vue` — расписание на сегодня / неделю, только свои записи | ✅ |
| М2.3 | Карточка записи: клиент, услуга, время, телефон, кнопки статуса | ✅ |
| М2.4 | Переключение день / неделя, навигация вперёд-назад | ✅ |
| М2.5 | Роутер: если `role === 'master'` → `MasterLayout`, иначе `AppLayout` | ✅ |
| М2.6 | Настройка скрытия телефона клиента (владелец управляет в настройках) | ⬜ отложено |

### Этап М3 — Мессенджеры для мастера (Telegram + MAX)

> Конкурентное преимущество — ни у кого в СНГ нет нативно. Оба мессенджера на равных.

| # | Что | Статус |
|---|---|---|
| М3.1 | Мастер привязывает Telegram через deep-link (аналог клиентской привязки) | ✅ |
| М3.2 | Мастер привязывает MAX через код (аналог клиентской привязки) | ✅ |
| М3.3 | Уведомление мастеру: новая запись к нему | ✅ |
| М3.4 | Уведомление мастеру: запись отменена / перенесена | ✅ |
| М3.5 | Напоминание мастеру за 2ч до начала смены | ✅ |
| М3.6 | Кнопки в боте: "Клиент пришёл" / "Не пришёл" | ⬜ отложено |

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

### Фаза 5 — Продвинутая кастомизация (обойти конкурентов)

> Цель: чтобы шопер без знания CSS мог сделать виджет под свой бренд за 2 минуты.
> Ориентир: Calendly, Typeform, Fresha — но лучше.
> Правило: переиспользовать готовые компоненты (color picker, dropdown, toggle).

#### Этап 5.1 — Preset-темы ✅

Три готовые темы одной кнопкой. При выборе — автоматически меняет основной цвет + фон.

| # | Что | Статус |
|---|---|---|
| 5.1.1 | Backend: поле `preset` в `widget_config` | ✅ |
| 5.1.2 | Admin: карточки-превью тем (Светлая / Тёмная / Минимализм) | ✅ |
| 5.1.3 | Widget: CSS-переменные переключаются по теме | ✅ |
| 5.1.4 | Кнопка переключения темы убрана из виджета — тема управляется шопером | ✅ |

#### Этап 5.2 — Расширенные цвета + шрифт ✅

Цвет фона виджета (переопределяет пресет) + выбор шрифта из 5 вариантов.

| # | Что | Статус |
|---|---|---|
| 5.2.1 | Backend: поля `bg_color`, `font_family` в `widget_config` | ✅ |
| 5.2.2 | Admin: чекбокс + color picker для кастомного фона | ✅ |
| 5.2.3 | Admin: кнопки-превью шрифта — Системный / Inter / Roboto / Montserrat / Georgia | ✅ |
| 5.2.4 | Widget: динамическая подгрузка Google Fonts + применение через CSS-переменную `--sb-font` | ✅ |

**Проверить:** выбрать Montserrat → сохранить → открыть виджет → шрифт применился. Включить кастомный фон → цвет переопределил пресет.

#### Этап 5.3 — Живой превью ✅

Виджет рендерится прямо в настройках и обновляется в реальном времени при любом изменении — без сохранения.

| # | Что | Статус |
|---|---|---|
| 5.3.1 | Iframe виджета внутри страницы настроек (полная ширина + sticky) | ✅ |
| 5.3.2 | postMessage от настроек в iframe при каждом изменении | ✅ |
| 5.3.3 | Widget: previewConfig в store, main.ts слушает postMessage | ✅ |
| 5.3.4 | Кнопка "Смотреть как клиент" → /book/{apiKey} в новой вкладке | ✅ |
| 5.3.5 | /book/{apiKey}?preview=1 — blade отдаёт виджет в inline-режиме | ✅ |
| 5.3.6 | Мобайл: кнопка "Предпросмотр" показывает iframe под формой | ✅ |

**Проверить:** поменять цвет → виджет справа обновился без сохранения. "Смотреть как клиент" открывает /book в новой вкладке.

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

## External API v1 ✅

> Внешний REST API для Pro-шоперов. Доступ по X-API-Key из настроек → вкладка "Интеграция".

### Middleware-цепочка для `/api/v1/*`
`force.json` → `api.auth` (ApiKeyAuth) → `api.ratelimit` (60 req/min) → `api.pro` (hasFeature api_access)

| Endpoint | Метод | Что делает |
|---|---|---|
| `/api/v1/ping` | GET | Проверка ключа, возвращает имя магазина и тариф |
| `/api/v1/bookings` | GET | Список записей (фильтры: status, master_id, date_from, date_to) |
| `/api/v1/bookings` | POST | Создать запись (throttle:20,1) |
| `/api/v1/bookings/{id}` | GET | Детальная запись |
| `/api/v1/bookings/{id}` | PATCH | Изменить статус (throttle:20,1) |
| `/api/v1/bookings/{id}` | DELETE | Отменить запись |
| `/api/v1/available-slots` | GET | Доступные слоты (service_id, date, master_id) |
| `/api/v1/orders` | GET | Список заказов (фильтры: status, customer_id, date_from, date_to, search) |
| `/api/v1/orders` | POST | Создать заказ с items (throttle:20,1) |
| `/api/v1/orders/{id}` | GET | Детальный заказ |
| `/api/v1/orders/{id}` | PATCH | Изменить статус заказа (throttle:20,1) |
| `/api/v1/clients` | GET | Список клиентов (поиск по имени/телефону/email) |
| `/api/v1/clients` | POST | Создать клиента (throttle:20,1, 409 на дубль телефона) |
| `/api/v1/clients/{id}` | GET | Детальный клиент |
| `/api/v1/products` | GET | Список товаров (фильтры: type, active) |
| `/api/v1/products/{id}` | GET | Детальный товар |
| `/api/v1/services` | GET | Список услуг (алиас products?type=service) |
| `/api/v1/categories` | GET | Дерево категорий с children |
| `/api/v1/masters` | GET | Список мастеров (фильтр: active) |
| `/api/v1/masters` | POST | Создать мастера (throttle:20,1) |
| `/api/v1/masters/{id}` | GET | Детальный мастер |
| `/api/v1/masters/{id}` | PATCH | Обновить мастера (throttle:20,1, каскад при деактивации) |
| `/api/v1/masters/{id}` | DELETE | Удалить мастера (каскад: записи отменяются/переназначаются) |

### Безопасность
- X-API-Key валидируется через TenantService::setContextByApiKey()
- Rate limit: 60 req/min global + 20 req/min для POST-мутаций per API key
- Заголовки: X-RateLimit-Limit, X-RateLimit-Remaining; 429 → Retry-After
- ForceJson middleware: принудительный Accept: application/json — предотвращает 302-редирект при ошибках валидации
- Доступно только на тарифе Pro (feature gate: `api_access`)

### Demo & Docs ✅
- `page-api-example/index.html` — интерактивная демо-страница: все вкладки (записи, заказы, клиенты, услуги, товары, мастера), CRUD, фильтры, пагинация, универсальный модал, retry_after при 429
- `page-api-example/docs.html` — полная документация всех эндпоинтов с примерами запросов/ответов

### Admin UI
- Настройки → вкладка "Интеграция" → показ/скрытие ключа, копирование, перегенерация с подтверждением

---

## Деплой

1. Пуш в ветку `new-branch`
2. GitHub Actions → SSH на сервер → `git pull` + `composer install` + `php artisan migrate` + перезапуск контейнера
3. Время деплоя: ~1 минута
4. Фронт (admin/widget) деплоится отдельно при изменениях в `admin/` или `widget/`
