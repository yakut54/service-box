@extends('emails.layout')

@section('title', 'Вы записаны')
@section('header_color', '#16a34a')
@section('header_title', 'Вы записаны!')

@section('content')
  <p class="value">Ждём вас — детали записи ниже.</p>

  @include('emails.partials.booking-details', ['booking' => $booking])

  @if($booking['notes'])
  <p class="label">Ваш комментарий</p>
  <p class="value">{{ $booking['notes'] }}</p>
  @endif
@endsection
