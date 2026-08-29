@extends('emails.layout')

@section('title', 'Новый заказ')
@section('header_color', '#2563eb')
@section('header_title', 'Новый заказ')

@section('content')
  <p class="label">Клиент</p>
  <p class="value">{{ $order['customer_name'] }} &nbsp;·&nbsp; {{ $order['customer_phone'] }}@if($order['customer_email']) &nbsp;·&nbsp; {{ $order['customer_email'] }}@endif</p>

  @if($order['notes'])
  <p class="label">Комментарий</p>
  <p class="value">{{ $order['notes'] }}</p>
  @endif

  @include('emails.partials.order-items', [
    'items' => $order['items'],
    'discountAmount' => $order['discount_amount'],
    'totalPrice' => $order['total_price'],
  ])
@endsection
