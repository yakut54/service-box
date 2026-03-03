<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Контакты и реквизиты — Якут54</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 0;
      background: #f8fafc;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
      color: #1e293b;
    }
    header {
      background: #2563eb;
      padding: 20px 0;
    }
    header .inner {
      max-width: 760px;
      margin: 0 auto;
      padding: 0 24px;
    }
    header h1 {
      margin: 0;
      color: #fff;
      font-size: 22px;
      font-weight: 700;
    }
    header p {
      margin: 4px 0 0;
      color: #bfdbfe;
      font-size: 14px;
    }
    main {
      max-width: 760px;
      margin: 40px auto;
      padding: 0 24px 60px;
    }
    .card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,.07);
      padding: 32px;
      margin-bottom: 24px;
    }
    h2 {
      margin: 0 0 20px;
      font-size: 18px;
      font-weight: 700;
      color: #1e293b;
      padding-bottom: 12px;
      border-bottom: 1px solid #f1f5f9;
    }
    .row {
      display: flex;
      gap: 12px;
      padding: 10px 0;
      border-bottom: 1px solid #f8fafc;
      align-items: baseline;
    }
    .row:last-child { border-bottom: none; }
    .row-label {
      flex: 0 0 200px;
      font-size: 14px;
      color: #64748b;
    }
    .row-value {
      font-size: 15px;
      color: #1e293b;
      font-weight: 500;
    }
    a { color: #2563eb; text-decoration: none; }
    a:hover { text-decoration: underline; }
    footer {
      text-align: center;
      color: #94a3b8;
      font-size: 13px;
      padding: 24px;
    }
    @media (max-width: 540px) {
      .row { flex-direction: column; gap: 2px; }
      .row-label { flex: none; font-size: 12px; }
    }
  </style>
</head>
<body>

<header>
  <div class="inner">
    <h1>Якут54</h1>
    <p>Сервис онлайн-записи и продаж</p>
  </div>
</header>

<main>

  <div class="card">
    <h2>Реквизиты</h2>

    <div class="row">
      <span class="row-label">Организационная форма</span>
      <span class="row-value">Индивидуальный предприниматель</span>
    </div>
    <div class="row">
      <span class="row-label">Наименование</span>
      {{-- TODO: заменить на реальное ФИО ИП --}}
      <span class="row-value">ИП Иванов Иван Иванович</span>
    </div>
    <div class="row">
      <span class="row-label">ИНН</span>
      {{-- TODO: вставить реальный ИНН --}}
      <span class="row-value">143500000000</span>
    </div>
    <div class="row">
      <span class="row-label">ОГРНИП</span>
      {{-- TODO: вставить реальный ОГРНИП --}}
      <span class="row-value">312143500000000</span>
    </div>
    <div class="row">
      <span class="row-label">Адрес регистрации</span>
      {{-- TODO: вставить реальный юридический адрес --}}
      <span class="row-value">677000, Республика Саха (Якутия), г. Якутск, ул. Ленина, д. 1</span>
    </div>
  </div>

  <div class="card">
    <h2>Контактная информация</h2>

    <div class="row">
      <span class="row-label">Телефон</span>
      {{-- TODO: вставить реальный телефон --}}
      <span class="row-value"><a href="tel:+79134799312">+7 (913) 479-93-12</a></span>
    </div>
    <div class="row">
      <span class="row-label">Электронная почта</span>
      {{-- TODO: вставить реальный email --}}
      <span class="row-value"><a href="mailto:info@yakut54.ru">info@yakut54.ru</a></span>
    </div>
    <div class="row">
      <span class="row-label">Сайт</span>
      <span class="row-value"><a href="https://yakut54.ru">yakut54.ru</a></span>
    </div>
  </div>

  <div class="card">
    <h2>О сервисе</h2>
    <p style="margin:0; font-size:15px; line-height:1.7; color:#475569;">
      Якут54 — платформа для онлайн-записи на услуги и приёма заказов.
      Мы обеспечиваем безопасную обработку платежей через сертифицированного оператора
      ЮКасса (НКО «Юмани», лицензия ЦБ РФ). Все данные передаются по защищённому протоколу HTTPS.
    </p>
  </div>

</main>

<footer>
  &copy; {{ date('Y') }} Якут54. Все права защищены.
</footer>

</body>
</html>
