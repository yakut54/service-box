{{-- Ожидает: $items (массив ['product_name','product_type','quantity','price']),
     $discountAmount (string|null), $totalPrice (string). Цены уже отформатированы
     в рублях (MailService::rubles) — здесь просто выводим. --}}
@php
  $typeLabels  = ['physical' => 'Физический', 'digital' => 'Цифровой', 'service' => 'Услуга'];
  $typeClasses = ['physical' => 'badge-physical', 'digital' => 'badge-digital', 'service' => 'badge-service'];
@endphp

<table class="items">
  <thead>
    <tr>
      <th>Товар</th>
      <th class="right">Кол-во</th>
      <th class="right">Цена</th>
    </tr>
  </thead>
  <tbody>
    @foreach($items as $item)
    <tr>
      <td>
        {{ $item['product_name'] }}
        @if(isset($typeLabels[$item['product_type']]))
        <span class="badge {{ $typeClasses[$item['product_type']] }}">{{ $typeLabels[$item['product_type']] }}</span>
        @endif
      </td>
      <td class="right">{{ $item['quantity'] }}</td>
      <td class="right">{{ $item['price'] }} ₽</td>
    </tr>
    @endforeach
  </tbody>
</table>

<table class="summary">
  @if($discountAmount)
  <tr>
    <td>Скидка</td>
    <td class="right">−{{ $discountAmount }} ₽</td>
  </tr>
  @endif
  <tr class="total">
    <td>Итого</td>
    <td class="right">{{ $totalPrice }} ₽</td>
  </tr>
</table>
