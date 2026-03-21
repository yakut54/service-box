<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>@yield('title') — ServiceBox</title>
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
      max-width: 800px;
      margin: 0 auto;
      padding: 0 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; }
    .logo-mark {
      width: 36px; height: 36px;
      background: rgba(255,255,255,.2);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 15px; color: #fff; flex-shrink: 0;
    }
    .logo-name { color: #fff; font-size: 18px; font-weight: 700; }
    .logo-sub  { color: #bfdbfe; font-size: 12px; margin-top: 1px; }
    .btn-print {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 14px;
      border: 1px solid rgba(255,255,255,.3);
      border-radius: 8px;
      background: rgba(255,255,255,.12);
      color: #fff;
      font-size: 13px;
      cursor: pointer;
      text-decoration: none;
      white-space: nowrap;
    }
    .btn-print:hover { background: rgba(255,255,255,.22); }
    main {
      max-width: 800px;
      margin: 32px auto;
      padding: 0 24px 60px;
    }
    .nav-docs {
      display: flex; flex-wrap: wrap; gap: 8px;
      margin-bottom: 24px;
    }
    .nav-docs a {
      display: inline-block;
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 13px;
      text-decoration: none;
      background: #f1f5f9;
      color: #475569;
      transition: background .15s;
    }
    .nav-docs a:hover  { background: #e2e8f0; }
    .nav-docs a.active { background: #2563eb; color: #fff; }
    .card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,.07);
      padding: 36px 40px;
      margin-bottom: 24px;
    }
    .doc-title {
      font-size: 24px; font-weight: 700; color: #0f172a;
      margin: 0 0 6px;
    }
    .doc-meta {
      font-size: 13px; color: #94a3b8;
      margin-bottom: 28px;
      padding-bottom: 20px;
      border-bottom: 1px solid #f1f5f9;
    }
    .doc-body { font-size: 15px; line-height: 1.85; color: #334155; }
    .doc-body h2 {
      font-size: 16px; font-weight: 700; color: #1e293b;
      margin: 28px 0 10px;
      padding-bottom: 6px;
      border-bottom: 1px solid #f1f5f9;
    }
    .doc-body h2:first-child { margin-top: 0; }
    .doc-body p { margin: 0 0 12px; }
    .doc-body ul, .doc-body ol { margin: 0 0 12px; padding-left: 20px; }
    .doc-body li { margin-bottom: 5px; }
    .requisites-card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,.07);
      padding: 28px 40px;
      margin-bottom: 24px;
    }
    .requisites-card h2 {
      font-size: 16px; font-weight: 600; color: #1e293b;
      margin: 0 0 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid #f1f5f9;
    }
    .row {
      display: flex; gap: 12px;
      padding: 9px 0;
      border-bottom: 1px solid #f8fafc;
      align-items: baseline;
    }
    .row:last-child { border-bottom: none; }
    .row-label { flex: 0 0 190px; font-size: 13px; color: #64748b; }
    .row-value  { font-size: 14px; color: #1e293b; font-weight: 500; }
    a { color: #2563eb; text-decoration: none; }
    a:hover { text-decoration: underline; }
    footer {
      text-align: center;
      color: #94a3b8;
      font-size: 12px;
      padding: 24px;
    }
    footer a { color: #94a3b8; }
    @media (max-width: 540px) {
      header .inner { flex-direction: column; align-items: flex-start; gap: 12px; }
      .card, .requisites-card { padding: 20px; }
      .row { flex-direction: column; gap: 2px; }
      .row-label { flex: none; font-size: 12px; }
    }
    @media print {
      body { background: #fff; }
      header { background: #1e293b !important; -webkit-print-color-adjust: exact; }
      .nav-docs, .btn-print, footer { display: none; }
      .card, .requisites-card { box-shadow: none; border: 1px solid #e2e8f0; }
      main { margin-top: 16px; }
    }
  </style>
</head>
<body>

<header>
  <div class="inner">
    <a href="/" class="logo">
      <div class="logo-mark">SB</div>
      <div>
        <div class="logo-name">ServiceBox</div>
        <div class="logo-sub">Платформа для онлайн-продаж</div>
      </div>
    </a>
    <button class="btn-print" onclick="window.print()">
      <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
      </svg>
      Распечатать
    </button>
  </div>
</header>

<main>
  <div class="nav-docs">
    <a href="/offer"   class="{{ Request::is('offer')   ? 'active' : '' }}">Публичная оферта</a>
    <a href="/privacy" class="{{ Request::is('privacy') ? 'active' : '' }}">Политика конфиденциальности</a>
  </div>

  @yield('content')
</main>

<footer>
  &copy; {{ date('Y') }} ИП [ФИО] &nbsp;·&nbsp;
  <a href="/offer">Оферта</a> &nbsp;·&nbsp;
  <a href="/privacy">Конфиденциальность</a> &nbsp;·&nbsp;
  <a href="/kontakty">Контакты</a>
</footer>

</body>
</html>
