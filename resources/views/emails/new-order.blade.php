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
    .body p { margin: 0 0 12px; color: #374151; font-size: 15px; line-height: 1.6; }
    .label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin: 0 0 4px; }
    .value { font-size: 15px; color: #111827; margin: 0 0 16px; }
    .table { width: 100%; border-collapse: collapse; margin: 16px 0; }
    .table th { text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; padding: 0 0 8px; border-bottom: 1px solid #f3f4f6; }
    .table td { padding: 10px 0; font-size: 14px; color: #374151; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    .table td.right { text-align: right; white-space: nowrap; }
    .total { display: flex; justify-content: space-between; padding: 14px 0 0; font-weight: 700; font-size: 16px; color: #111827; }
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

      <table class="table">
        <thead>
          <tr>
            <th>Товар</th>
            <th class="right">Кол-во</th>
            <th class="right">Цена</th>
          </tr>
        </thead>
        <tbody>
          @foreach($order->items ?? [] as $item)
          <tr>
            <td>{{ $item->product_name }}</td>
            <td class="right">{{ $item->quantity }}</td>
            <td class="right">{{ number_format($item->price, 0, '.', ' ') }} ₽</td>
          </tr>
          @endforeach
        </tbody>
      </table>

      @if($order->discount_amount > 0)
      <div style="text-align:right; font-size:14px; color:#6b7280; margin-bottom:4px;">
        Скидка: −{{ number_format($order->discount_amount, 0, '.', ' ') }} ₽
      </div>
      @endif

      <div class="total">
        <span>Итого</span>
        <span>{{ number_format($order->total_price, 0, '.', ' ') }} ₽</span>
      </div>
    </div>
    <div class="footer">
      &copy; {{ date('Y') }} {{ config('app.name') }}. Это автоматическое письмо — не отвечайте на него.
    </div>
  </div>
</body>
</html>
