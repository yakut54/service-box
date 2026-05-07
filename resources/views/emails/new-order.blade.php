<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Новый заказ</title>
  <style>
    body { margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    .wrap { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .header { background: #2563eb; padding: 28px 40px; }
    .header h1 { margin: 0; color: #fff; font-size: 20px; font-weight: 700; }
    .header p { margin: 6px 0 0; color: #bfdbfe; font-size: 14px; }
    .body { padding: 32px 40px; }
    .label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin: 0 0 4px; }
    .value { font-size: 15px; color: #111827; margin: 0 0 18px; line-height: 1.5; }
    .items { width: 100%; border-collapse: collapse; margin: 16px 0 0; }
    .items th { text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; padding: 0 8px 8px 0; border-bottom: 1px solid #f3f4f6; }
    .items th.right { text-align: right; padding-right: 0; }
    .items td { padding: 10px 8px 10px 0; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    .items td.right { text-align: right; white-space: nowrap; padding-right: 0; }
    .badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 1px 6px; border-radius: 4px; margin-left: 6px; vertical-align: middle; }
    .badge-physical { background: #f0fdf4; color: #16a34a; }
    .badge-digital  { background: #eff6ff; color: #2563eb; }
    .badge-service  { background: #faf5ff; color: #7c3aed; }
    .summary { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .summary td { padding: 5px 0; font-size: 14px; color: #6b7280; }
    .summary td.right { text-align: right; white-space: nowrap; }
    .summary tr.total td { padding-top: 12px; font-size: 16px; font-weight: 700; color: #111827; border-top: 1px solid #e5e7eb; }
    .footer { border-top: 1px solid #f3f4f6; padding: 18px 40px; color: #9ca3af; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <h1>Новый заказ</h1>
      <p>{{ $shopName }}</p>
    </div>
    <div class="body">

      <p class="label">Клиент</p>
      <p class="value">{{ $order->customer_name }} &nbsp;·&nbsp; {{ $order->customer_phone }}@if($order->customer_email) &nbsp;·&nbsp; {{ $order->customer_email }}@endif</p>

      @if($order->notes)
      <p class="label">Комментарий</p>
      <p class="value">{{ $order->notes }}</p>
      @endif

      <table class="items">
        <thead>
          <tr>
            <th>Товар</th>
            <th class="right">Кол-во</th>
            <th class="right">Цена</th>
          </tr>
        </thead>
        <tbody>
          @foreach($order->items ?? [] as $item)
          @php
            $typeLabels = ['physical' => 'Физический', 'digital' => 'Цифровой', 'service' => 'Услуга'];
            $typeClasses = ['physical' => 'badge-physical', 'digital' => 'badge-digital', 'service' => 'badge-service'];
            $typeLabel = $typeLabels[$item->product_type] ?? null;
            $typeClass = $typeClasses[$item->product_type] ?? '';
          @endphp
          <tr>
            <td>
              {{ $item->product_name }}
              @if($typeLabel)
              <span class="badge {{ $typeClass }}">{{ $typeLabel }}</span>
              @endif
            </td>
            <td class="right">{{ $item->quantity }}</td>
            <td class="right">{{ number_format($item->price / 100, 0, '.', ' ') }} ₽</td>
          </tr>
          @endforeach
        </tbody>
      </table>

      <table class="summary">
        @if($order->discount_amount > 0)
        <tr>
          <td>Скидка</td>
          <td class="right">−{{ number_format($order->discount_amount / 100, 0, '.', ' ') }} ₽</td>
        </tr>
        @endif
        <tr class="total">
          <td>Итого</td>
          <td class="right">{{ number_format($order->total_price / 100, 0, '.', ' ') }} ₽</td>
        </tr>
      </table>

    </div>
    <div class="footer">
      &copy; {{ date('Y') }} {{ config('app.name') }}. Это автоматическое письмо — не отвечайте на него.
    </div>
  </div>
</body>
</html>
