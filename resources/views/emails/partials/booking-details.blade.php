{{-- Ожидает: $booking (массив из MailService::bookingPayload). --}}
<p class="label">Услуга</p>
<p class="value">
  {{ $booking['service_name'] ?? '—' }}
  <span class="badge badge-service">Услуга</span>
</p>

@if($booking['service_price'])
<table class="summary" style="margin-top: -10px;">
  <tr class="total">
    <td>Стоимость</td>
    <td class="right">{{ $booking['service_price'] }} ₽</td>
  </tr>
</table>
@endif

<p class="label">Дата и время</p>
<p class="value">{{ $booking['date'] }}, {{ $booking['time_from'] }} — {{ $booking['time_to'] }}</p>

@if($booking['master_name'])
<p class="label">Мастер</p>
<p class="value">{{ $booking['master_name'] }}</p>
@endif
