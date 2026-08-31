@extends('emails.layout')

@section('title', 'Нужна доплата')
@section('header_color', '#d97706')
@section('header_title', 'Требуется доплата')

@section('content')
  <p class="value">
    Ваш заказ №{{ $order['short_id'] }} собран. Фактический вес товара оказался
    больше заявленного при оформлении, поэтому образовалась доплата.
  </p>

  <p class="label">Сумма доплаты</p>
  <p class="value" style="font-size: 22px; font-weight: 700;">{{ $order['surcharge_amount'] }} ₽</p>

  <p class="label">Подтвердите до</p>
  <p class="value">{{ $order['deadline'] }}, иначе заказ будет отменён</p>

  @if($order['payment_url'])
  <p style="margin: 24px 0 0;">
    <a href="{{ $order['payment_url'] }}"
       style="display: inline-block; background: #d97706; color: #fff; text-decoration: none; font-weight: 600; font-size: 15px; padding: 12px 24px; border-radius: 8px;">
      Оплатить доплату
    </a>
  </p>
  @endif
@endsection
