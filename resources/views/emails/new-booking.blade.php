@extends('emails.layout')

@section('title', 'Новая запись')
@section('header_color', '#7c3aed')
@section('header_title', 'Новая запись')

@section('content')
  <p class="label">Услуга</p>
  <p class="value">
    {{ $booking->service?->name ?? '—' }}
    <span class="badge badge-service">Услуга</span>
  </p>

  @if($booking->service?->price)
  <table class="summary" style="margin-top: -10px;">
    <tr class="total">
      <td>Стоимость</td>
      <td class="right">{{ number_format($booking->service->price, 0, '.', ' ') }} ₽</td>
    </tr>
  </table>
  @endif

  <p class="label">Дата и время</p>
  <p class="value">
    {{ \Carbon\Carbon::parse($booking->start_time)->format('d.m.Y, H:i') }}
    — {{ \Carbon\Carbon::parse($booking->end_time)->format('H:i') }}
  </p>

  @if($booking->master)
  <p class="label">Мастер</p>
  <p class="value">{{ $booking->master->name }}</p>
  @endif

  <p class="label">Клиент</p>
  <p class="value">{{ $booking->customer_name }} &nbsp;·&nbsp; {{ $booking->customer_phone }}@if($booking->customer_email) &nbsp;·&nbsp; {{ $booking->customer_email }}@endif</p>

  @if($booking->notes)
  <p class="label">Комментарий</p>
  <p class="value">{{ $booking->notes }}</p>
  @endif
@endsection
