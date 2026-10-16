/** Все денежные поля в API — копейки (integer). Делим на 100 перед показом. */
export function formatPrice(kopecks: number): string {
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'RUB',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Math.round(kopecks / 100))
}

/** "2024-01-15T10:30:00" → "15 янв, 10:30". Передай timeZone для отображения в timezone магазина. */
export function formatDateTime(dateStr: string | null | undefined, timeZone?: string): string {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleString('ru-RU', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
    ...(timeZone && { timeZone }),
  })
}

/** "2024-01-15T10:30:00" → "15 янв 2024". Передай timeZone для отображения в timezone магазина. */
export function formatDate(dateStr: string | null | undefined, timeZone?: string): string {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    ...(timeZone && { timeZone }),
  })
}

/** Граммы → человекочитаемая строка: мелкие остатки в граммах, крупные —
 * в кг с одним знаком после запятой (100000 г нечитаемо, 100 кг — нормально). */
export function formatWeight(grams: number): string {
  if (grams >= 1000) return `${(grams / 1000).toFixed(1).replace(/\.0$/, '')} кг`
  return `${grams} г`
}

/** Значение строки «Журнала изменений» (product_changes.old_value/new_value,
 * всегда TEXT) — формат зависит от поля: остаток/цена/видимость читаются не
 * как голая строка, название/категория — как есть. Общая для
 * ProductChangesView.vue и карточки товара (ProductEditView.vue), чтобы не
 * дублировать правило форматирования в двух местах. */
export function formatProductChangeValue(field: string | null, unit: string | null, raw: string | null): string {
  if (raw === null) return '—'
  if (field === 'stock') return unit === 'g' ? formatWeight(Number(raw)) : raw
  if (field === 'price' || field === 'compare_price') return formatPrice(Number(raw))
  if (field === 'active') return raw === '1' ? 'Показан' : 'Скрыт'
  return raw
}

/** "2024-01-15T10:30:00" → "15 мин назад" / "2 ч назад" / "3 дн назад".
 * Для очереди сборщика — важнее, сколько заказ уже ждёт, чем точное время. */
export function formatRelativeTime(dateStr: string | null | undefined): string {
  if (!dateStr) return '—'
  const diffMs = Date.now() - new Date(dateStr).getTime()
  const minutes = Math.floor(diffMs / 60_000)
  if (minutes < 1) return 'только что'
  if (minutes < 60) return `${minutes} мин назад`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours} ч назад`
  const days = Math.floor(hours / 24)
  return `${days} дн назад`
}
