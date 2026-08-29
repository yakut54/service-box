@extends('emails.layout')

@section('title', 'Новая запись')
@section('header_color', '#7c3aed')
@section('header_title', 'Новая запись')

@section('content')
  @include('emails.partials.booking-details', ['booking' => $booking])

  <p class="label">Клиент</p>
  <p class="value">{{ $booking['customer_name'] }} &nbsp;·&nbsp; {{ $booking['customer_phone'] }}@if($booking['customer_email']) &nbsp;·&nbsp; {{ $booking['customer_email'] }}@endif</p>

  @if($booking['notes'])
  <p class="label">Комментарий</p>
  <p class="value">{{ $booking['notes'] }}</p>
  @endif
@endsection
