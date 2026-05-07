<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>@yield('title')</title>
  <style>
    body { margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    .wrap { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .header { padding: 28px 40px; }
    .header h1 { margin: 0; color: #fff; font-size: 20px; font-weight: 700; }
    .header p { margin: 6px 0 0; font-size: 14px; opacity: .75; color: #fff; }
    .body { padding: 32px 40px; }
    .label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin: 0 0 4px; }
    .value { font-size: 15px; color: #111827; margin: 0 0 18px; line-height: 1.5; }
    .badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 4px; margin-left: 6px; vertical-align: middle; }
    .badge-physical { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
    .badge-digital  { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
    .badge-service  { background: #ede9fe; color: #6d28d9; border: 1px solid #c4b5fd; }
    .items { width: 100%; border-collapse: collapse; margin: 16px 0 0; }
    .items th { text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; padding: 0 8px 8px 0; border-bottom: 1px solid #f3f4f6; }
    .items th.right { text-align: right; padding-right: 0; }
    .items td { padding: 10px 8px 10px 0; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    .items td.right { text-align: right; white-space: nowrap; padding-right: 0; }
    .summary { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .summary td { padding: 5px 0; font-size: 14px; color: #6b7280; }
    .summary td.right { text-align: right; white-space: nowrap; }
    .summary tr.total td { padding-top: 12px; font-size: 16px; font-weight: 700; color: #111827; border-top: 1px solid #e5e7eb; }
    .footer { border-top: 1px solid #f3f4f6; padding: 18px 40px; color: #9ca3af; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header" style="background: @yield('header_color');">
      <h1>@yield('header_title')</h1>
      <p>{{ $shopName }}</p>
    </div>
    <div class="body">
      @yield('content')
    </div>
    <div class="footer">
      &copy; {{ date('Y') }} {{ config('app.name') }}. Это автоматическое письмо — не отвечайте на него.
    </div>
  </div>
</body>
</html>
