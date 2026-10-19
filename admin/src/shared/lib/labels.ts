export const ORDER_STATUS_LABELS: Record<string, string> = {
  pending:         'Ожидает',
  paid:            'Оплачен',
  processing:      'В работе',
  collected:       'Собрано',
  in_delivery:     'В доставке',
  completed:       'Завершён',
  cancelled:       'Отменён',
  needs_attention: 'Требует внимания',
}

/**
 * Шаги визуального таймлайна статуса заказа (UiOrderStepper.vue). pending/
 * paid схлопнуты в один шаг «Оформлен» — разница между ними уже видна
 * текстом в других местах экрана (кнопка «Заказ ещё не оплачен»), таймлайну
 * нужна только общая картина. in_delivery показывается только для
 * курьерской доставки — самовывозу/почте нечего "развозить". Подписи
 * переиспользуют ORDER_STATUS_LABELS, кроме финального шага courier —
 * «Доставлен» точнее, чем общее «Завершён», когда известно, что это
 * курьер (для самовывоза/почты остаётся «Завершён»).
 */
export function orderStatusSteps(deliveryMethod?: string | null): { key: string; label: string }[] {
  const steps = [
    { key: 'pending',    label: 'Оформлен' },
    { key: 'processing', label: ORDER_STATUS_LABELS.processing },
    { key: 'collected',  label: ORDER_STATUS_LABELS.collected },
  ]
  if (deliveryMethod === 'courier') {
    steps.push({ key: 'in_delivery', label: ORDER_STATUS_LABELS.in_delivery })
    steps.push({ key: 'completed', label: 'Доставлен' })
  } else {
    steps.push({ key: 'completed', label: ORDER_STATUS_LABELS.completed })
  }
  return steps
}

/**
 * Индекс текущего шага среди orderStatusSteps() для реального статуса
 * заказа. paid маппится на тот же шаг, что pending («Оформлен»);
 * needs_attention — на шаг processing, он всегда туда и возвращается
 * (см. Order::TRANSITIONS) — на таймлайне выглядит как «застрял на сборке».
 */
export function orderStepIndex(status: string, deliveryMethod?: string | null): number {
  const steps = orderStatusSteps(deliveryMethod)
  const effectiveKey = status === 'paid' ? 'pending' : status === 'needs_attention' ? 'processing' : status
  const idx = steps.findIndex(s => s.key === effectiveKey)
  return idx === -1 ? 0 : idx
}

export const PRODUCT_CHANGE_FIELD_LABELS: Record<string, string> = {
  stock:          'Остаток',
  price:          'Цена',
  compare_price:  'Старая цена',
  name:           'Название',
  description:    'Описание',
  image_url:      'Фото',
  category:       'Категория',
  active:         'Показ в каталоге',
}

export const PRODUCT_CHANGE_ACTION_LABELS: Record<string, string> = {
  created: 'Товар создан',
  deleted: 'Товар удалён',
}

export const CATEGORY_CHANGE_FIELD_LABELS: Record<string, string> = {
  name:           'Название',
  parent_id:      'Родительская категория',
  description:    'Описание',
  image_url:      'Фото',
  visible:        'Показ в каталоге',
  age_restricted: '18+',
  no_return:      'Не подлежит возврату',
}

export const CATEGORY_CHANGE_ACTION_LABELS: Record<string, string> = {
  created: 'Категория создана',
  deleted: 'Категория удалена',
}

export const DELIVERY_METHOD_LABELS: Record<string, string> = {
  pickup:  'Самовывоз',
  courier: 'Курьер',
  postal:  'Почта / СДЭК',
}

export const BOOKING_STATUS_LABELS: Record<string, string> = {
  pending:   'Ожидает',
  confirmed: 'Подтверждена',
  completed: 'Завершена',
  cancelled: 'Отменена',
  no_show:   'Неявка',
}
