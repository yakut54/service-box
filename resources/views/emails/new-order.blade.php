@extends('emails.layout')

@section('title', 'Новый заказ')
@section('header_color', '#2563eb')
@section('header_title', 'Новый заказ')

@section('content')
  <p class="label">Клиент</p>
  <p class="value">{{ $order->customer_name }} &nbsp;·&nbsp; {{ $order->customer_phone }}@if($order->customer_email) &nbsp;·&nbsp; {{ $order->customer_email }}@endif</p>

  @if($order->notes)
  <p class="label">Комментарий</p>
  <p class="value">{{ $order->notes }}</p>
  @endif

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
      @foreach($order->items ?? [] as $item)
      <tr>
        <td>
          {{ $item->product_name }}
          @if(isset($typeLabels[$item->product_type]))
          <span class="badge {{ $typeClasses[$item->product_type] }}">{{ $typeLabels[$item->product_type] }}</span>
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
@endsection
