export function formatPrice(kopecks: number): string {
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'RUB',
    minimumFractionDigits: 0,
  }).format(kopecks / 100)
}

let debounceTimer: ReturnType<typeof setTimeout> | null = null
export function debounce(fn: (...args: any[]) => void, ms: number) {
  return (...args: any[]) => {
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => fn(...args), ms)
  }
}
