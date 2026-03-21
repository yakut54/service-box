<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }} — {{ $shop->name }}</title>
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
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    header h1 { margin: 0; color: #fff; font-size: 20px; font-weight: 700; }
    header p  { margin: 4px 0 0; color: #bfdbfe; font-size: 13px; }
    main {
      max-width: 760px;
      margin: 32px auto;
      padding: 0 24px 60px;
    }
    .card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,.07);
      padding: 32px;
      margin-bottom: 24px;
    }
    .doc-title {
      font-size: 22px;
      font-weight: 700;
      color: #1e293b;
      margin: 0 0 8px;
    }
    .doc-meta {
      font-size: 13px;
      color: #94a3b8;
      margin-bottom: 24px;
    }
    .doc-body {
      font-size: 15px;
      line-height: 1.8;
      color: #334155;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .doc-empty {
      padding: 40px 0;
      text-align: center;
      color: #94a3b8;
      font-size: 15px;
    }
    .requisites h2 {
      margin: 0 0 16px;
      font-size: 16px;
      font-weight: 600;
      color: #1e293b;
      padding-bottom: 12px;
      border-bottom: 1px solid #f1f5f9;
    }
    .row {
      display: flex;
      gap: 12px;
      padding: 9px 0;
      border-bottom: 1px solid #f8fafc;
      align-items: baseline;
    }
    .row:last-child { border-bottom: none; }
    .row-label {
      flex: 0 0 180px;
      font-size: 13px;
      color: #64748b;
    }
    .row-value {
      font-size: 14px;
      color: #1e293b;
      font-weight: 500;
    }
    .nav-docs {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
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
    .nav-docs a:hover    { background: #e2e8f0; }
    .nav-docs a.active   { background: #2563eb; color: #fff; }
    .actions {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }
    .btn-print {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #fff;
      color: #475569;
      font-size: 13px;
      cursor: pointer;
      text-decoration: none;
    }
    .btn-print:hover { background: #f8fafc; }
    a { color: #2563eb; text-decoration: none; }
    a:hover { text-decoration: underline; }
    footer {
      text-align: center;
      color: #94a3b8;
      font-size: 12px;
      padding: 24px;
    }
    @media (max-width: 540px) {
      .row { flex-direction: column; gap: 2px; }
      .row-label { flex: none; font-size: 12px; }
      header .inner { flex-direction: column; align-items: flex-start; }
      .card { padding: 20px; }
    }
    @media print {
      body { background: #fff; }
      header { background: #1e293b !important; -webkit-print-color-adjust: exact; }
      .nav-docs, .actions, footer { display: none; }
      .card { box-shadow: none; border: 1px solid #e2e8f0; }
      main { margin-top: 16px; }
    }
  </style>
</head>
<body>

<header>
  <div class="inner">
    <div>
      <h1>{{ $shop->name }}</h1>
      <p>Юридические документы</p>
    </div>
    <div class="actions" style="color:#bfdbfe; font-size:13px;">
      <button class="btn-print" onclick="window.print()" style="background:rgba(255,255,255,.15); border-color:rgba(255,255,255,.3); color:#fff;">
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
        </svg>
        Распечатать
      </button>
    </div>
  </div>
</header>

<main>

  {{-- Навигация по документам --}}
  <div class="nav-docs">
    @foreach ($docTypes as $typeKey => $meta)
      <a href="{{ route('legal.document', [$apiKey, $typeKey]) }}"
         class="{{ $type === $typeKey ? 'active' : '' }}">
        {{ $meta['title'] }}
      </a>
    @endforeach
  </div>

  {{-- Документ --}}
  <div class="card">
    <div class="doc-title">{{ $title }}</div>
    <div class="doc-meta">
      {{ $shop->name }}
      @if (!empty($cfg['legal_updated_at']))
        &nbsp;·&nbsp; Обновлено: {{ \Carbon\Carbon::parse($cfg['legal_updated_at'])->format('d.m.Y') }}
      @endif
    </div>

    @if ($text)
      <div class="doc-body">{{ $text }}</div>
    @else
      <div class="doc-empty">
        Магазин ещё не заполнил этот документ.<br>
        Обратитесь к продавцу напрямую.
      </div>
    @endif
  </div>

  {{-- Реквизиты — показываем если есть хоть что-то --}}
  @if (!empty($cfg['requisites']) || !empty($cfg['contact_email']) || !empty($cfg['contact_phone']))
    <div class="card requisites">
      <h2>Реквизиты и контакты</h2>

      @if (!empty($cfg['requisites']))
        <div class="row">
          <span class="row-label">Реквизиты</span>
          <span class="row-value" style="white-space:pre-line;">{{ $cfg['requisites'] }}</span>
        </div>
      @endif

      @if (!empty($cfg['contact_phone']))
        <div class="row">
          <span class="row-label">Телефон</span>
          <span class="row-value">
            <a href="tel:{{ $cfg['contact_phone'] }}">{{ $cfg['contact_phone'] }}</a>
          </span>
        </div>
      @endif

      @if (!empty($cfg['contact_email']))
        <div class="row">
          <span class="row-label">Email</span>
          <span class="row-value">
            <a href="mailto:{{ $cfg['contact_email'] }}">{{ $cfg['contact_email'] }}</a>
          </span>
        </div>
      @endif
    </div>
  @endif

</main>

<footer>
  Документ предоставлен магазином «{{ $shop->name }}» через платформу ServiceBox
</footer>

</body>
</html>
