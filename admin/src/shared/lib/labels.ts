export const ORDER_STATUS_LABELS: Record<string, string> = {
  pending:         'Ожидает',
  paid:            'Оплачен',
  processing:      'В работе',
  completed:       'Завершён',
  cancelled:       'Отменён',
  needs_attention: 'Требует внимания',
}

export const BOOKING_STATUS_LABELS: Record<string, string> = {
  pending:   'Ожидает',
  confirmed: 'Подтверждена',
  completed: 'Завершена',
  cancelled: 'Отменена',
  no_show:   'Неявка',
}
