@extends('emails.layout')

@section('title', 'Заказ принят')
@section('header_color', '#16a34a')
@section('header_title', 'Спасибо за заказ!')

@section('content')
  <p class="value">Ваш заказ №{{ $order['short_id'] }} принят в обработку. Вот что вы заказали:</p>

  @include('emails.partials.order-items', [
    'items' => $order['items'],
    'discountAmount' => $order['discount_amount'],
    'totalPrice' => $order['total_price'],
  ])

  @if($order['notes'])
  <p class="label">Ваш комментарий</p>
  <p class="value">{{ $order['notes'] }}</p>
  @endif
@endsection
