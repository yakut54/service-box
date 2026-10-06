<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <title>Заказ №{{ strtoupper(substr($order->id, 0, 8)) }}</title>
  <style>
    {{-- dompdf не умеет flex/grid — только таблицы/блоки, тот же набор
         приёмов, что уже в resources/views/emails/layout.blade.php. --}}
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; margin: 0; }
    .header { width: 100%; margin-bottom: 18px; }
    .header td { vertical-align: top; }
    .shop-name { font-size: 18px; font-weight: bold; }
    .doc-title { font-size: 14px; font-weight: bold; text-align: right; }
    .doc-meta { text-align: right; color: #6b7280; }
    .section { margin-bottom: 14px; }
    .label { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin: 0 0 2px; }
    .value { margin: 0 0 8px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.items th { text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; padding: 4px 6px; border-bottom: 1px solid #d1d5db; }
    table.items th.right, table.items td.right { text-align: right; }
    table.items td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
    table.summary { width: 100%; margin-top: 6px; }
    table.summary td { padding: 2px 6px; }
    table.summary td.right { text-align: right; }
    table.summary tr.total td { font-size: 14px; font-weight: bold; border-top: 1px solid #d1d5db; padding-top: 6px; }
  </style>
</head>
<body>

  <table class="header">
    <tr>
      <td>
        <div class="shop-name">{{ $shop?->name }}</div>
      </td>
      <td>
        <div class="doc-title">Накладная по заказу №{{ strtoupper(substr($order->id, 0, 8)) }}</div>
        <div class="doc-meta">{{ $order->created_at->format('d.m.Y H:i') }}</div>
      </td>
    </tr>
  </table>

  <div class="section">
    <p class="label">Покупатель</p>
    <p class="value">
      {{ $order->customer?->name ?? $order->customer_name }}
      @if($order->customer_phone) · {{ $order->customer_phone }} @endif
      @if($order->customer_email) · {{ $order->customer_email }} @endif
    </p>

    @if($order->delivery_method)
      @php
        $deliveryLabels = ['pickup' => 'Самовывоз', 'courier' => 'Курьер', 'postal' => 'Почта / СДЭК'];
        // Те же поля, что уже выводит OrderDetailView.vue на фронте.
        $addr = $order->shipping_address ?? [];
        $addressParts = array_filter([
          $addr['city'] ?? null,
          trim(($addr['street'] ?? '') . (isset($addr['building']) ? ', ' . $addr['building'] : '')),
          isset($addr['apartment']) ? 'кв. ' . $addr['apartment'] : null,
          $addr['postal_code'] ?? null,
        ]);
        $addressLine = implode(', ', $addressParts);
      @endphp
      <p class="label">Доставка</p>
      <p class="value">
        {{ $deliveryLabels[$order->delivery_method] ?? $order->delivery_method }}
        @if($addressLine) — {{ $addressLine }} @endif
      </p>
    @endif
  </div>

  <table class="items">
    <thead>
      <tr>
        <th>Товар</th>
        <th class="right">Кол-во / Вес</th>
        <th class="right">Сумма (₽)</th>
      </tr>
    </thead>
    <tbody>
      @foreach($order->items as $item)
        @php
          $weight = $item->actual_weight_grams ?? $item->weight_grams;
          $qtyLabel = $weight !== null
            ? ($weight >= 1000 ? number_format($weight / 1000, 1) . ' кг' : $weight . ' г')
            : $item->quantity . ' шт.';
          $lineTotal = ($item->actual_price ?? ($item->price * $item->quantity)) / 100;
        @endphp
        <tr>
          <td>
            {{ $item->product_name }}
            @if($item->variant_label)
              <br><span style="color:#6b7280;font-size:10px;">{{ $item->variant_label }}</span>
            @endif
          </td>
          <td class="right">{{ $qtyLabel }}</td>
          <td class="right">{{ number_format($lineTotal, 2, '.', '') }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="summary">
    @if($order->discount_amount)
      <tr>
        <td>Скидка</td>
        <td class="right">−{{ number_format($order->discount_amount / 100, 2, '.', '') }} ₽</td>
      </tr>
    @endif
    @if($order->delivery_price)
      <tr>
        <td>Стоимость доставки</td>
        <td class="right">{{ number_format($order->delivery_price / 100, 2, '.', '') }} ₽</td>
      </tr>
    @endif
    <tr class="total">
      <td>Итого</td>
      <td class="right">{{ number_format($order->total_price / 100, 2, '.', '') }} ₽</td>
    </tr>
  </table>

  @if($order->notes)
    <div class="section" style="margin-top:14px;">
      <p class="label">Примечание</p>
      <p class="value">{{ $order->notes }}</p>
    </div>
  @endif

</body>
</html>
