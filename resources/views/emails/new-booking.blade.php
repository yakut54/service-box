<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Новая запись</title>
  <style>
    body { margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    .wrap { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .header { background: #7c3aed; padding: 28px 40px; }
    .header h1 { margin: 0; color: #fff; font-size: 20px; font-weight: 700; }
    .header p { margin: 6px 0 0; color: #ddd6fe; font-size: 14px; }
    .body { padding: 32px 40px; }
    .label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin: 0 0 4px; }
    .value { font-size: 15px; color: #111827; margin: 0 0 18px; line-height: 1.5; }
    .badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 4px; margin-bottom: 6px; background: #ede9fe; color: #6d28d9; border: 1px solid #c4b5fd; }
    .price-row { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
    .price-row td { font-size: 15px; color: #111827; padding: 0; }
    .price-row td.right { text-align: right; font-weight: 700; }
    .footer { border-top: 1px solid #f3f4f6; padding: 18px 40px; color: #9ca3af; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <h1>Новая запись</h1>
      <p>{{ $shopName }}</p>
    </div>
    <div class="body">

      <span class="badge">Услуга</span>
      @php $servicePrice = $booking->service?->price; @endphp
      @if($servicePrice !== null)
      <table class="price-row">
        <tr>
          <td>{{ $booking->service->name }}</td>
          <td class="right">{{ number_format($servicePrice / 100, 0, '.', ' ') }} ₽</td>
        </tr>
      </table>
      @else
      <p class="value">{{ $booking->service?->name ?? '—' }}</p>
      @endif

      <p class="label">Дата и время</p>
      <p class="value">
        {{ \Carbon\Carbon::parse($booking->start_time)->format('d.m.Y, H:i') }}
        — {{ \Carbon\Carbon::parse($booking->end_time)->format('H:i') }}
      </p>

      @if($booking->master)
      <p class="label">Мастер</p>
      <p class="value">{{ $booking->master->name }}</p>
      @endif

      <p class="label">Клиент</p>
      <p class="value">
        {{ $booking->customer_name }} &nbsp;·&nbsp; {{ $booking->customer_phone }}@if($booking->customer_email) &nbsp;·&nbsp; {{ $booking->customer_email }}@endif
      </p>

      @if($booking->notes)
      <p class="label">Комментарий</p>
      <p class="value">{{ $booking->notes }}</p>
      @endif

    </div>
    <div class="footer">
      &copy; {{ date('Y') }} {{ config('app.name') }}. Это автоматическое письмо — не отвечайте на него.
    </div>
  </div>
</body>
</html>
