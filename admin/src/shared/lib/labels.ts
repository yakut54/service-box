export const ORDER_STATUS_LABELS: Record<string, string> = {
  pending:         'Ожидает',
  paid:            'Оплачен',
  processing:      'В работе',
  completed:       'Завершён',
  cancelled:       'Отменён',
  needs_attention: 'Требует внимания',
}

export const PRODUCT_CHANGE_FIELD_LABELS: Record<string, string> = {
  stock:          'Остаток',
  price:          'Цена',
  compare_price:  'Старая цена',
  name:           'Название',
  category:       'Категория',
  active:         'Показ в каталоге',
}

export const PRODUCT_CHANGE_ACTION_LABELS: Record<string, string> = {
  created: 'Товар создан',
  deleted: 'Товар удалён',
}

export const BOOKING_STATUS_LABELS: Record<string, string> = {
  pending:   'Ожидает',
  confirmed: 'Подтверждена',
  completed: 'Завершена',
  cancelled: 'Отменена',
  no_show:   'Неявка',
}
