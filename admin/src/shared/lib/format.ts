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
