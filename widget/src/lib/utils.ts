/**
 * Russian pluralization.
 * @param n    the number
 * @param one  form for 1, 21, 31… (именительный ед.ч.)
 * @param few  form for 2-4, 22-24… (родительный ед.ч.)
 * @param many form for 5-20, 25-30, 0… (родительный мн.ч.)
 */
export function plural(n: number, one: string, few: string, many: string): string {
  const abs = Math.abs(Math.round(n)) % 100
  const mod10 = abs % 10
  if (abs >= 11 && abs <= 19) return many
  if (mod10 === 1) return one
  if (mod10 >= 2 && mod10 <= 4) return few
  return many
}

/** Strip phone to digits only */
export function cleanPhone(value: string): string {
  return value.replace(/\D/g, '')
}

/** Format phone as +7 (XXX) XXX-XX-XX */
export function formatPhone(value: string): string {
  let digits = cleanPhone(value)

  // Normalize: if starts with 8, replace with 7
  if (digits.length > 0 && digits[0] === '8') {
    digits = '7' + digits.slice(1)
  }

  // If user types without country code, prepend 7
  if (digits.length > 0 && digits[0] !== '7') {
    digits = '7' + digits
  }

  // Limit to 11 digits
  digits = digits.slice(0, 11)

  if (digits.length === 0) return ''
  if (digits.length <= 1) return '+7'
  if (digits.length <= 4) return `+7 (${digits.slice(1)}`
  if (digits.length <= 7) return `+7 (${digits.slice(1, 4)}) ${digits.slice(4)}`
  if (digits.length <= 9) return `+7 (${digits.slice(1, 4)}) ${digits.slice(4, 7)}-${digits.slice(7)}`
  return `+7 (${digits.slice(1, 4)}) ${digits.slice(4, 7)}-${digits.slice(7, 9)}-${digits.slice(9)}`
}

/** Check if phone has exactly 11 digits (valid Russian number) */
export function isPhoneValid(value: string): boolean {
  const digits = cleanPhone(value)
  return digits.length === 11 && digits[0] === '7'
}

/** Check if email format is valid */
export function isEmailValid(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

/** Check if Russian postal code is valid (exactly 6 digits) */
export function isPostalCodeValid(value: string): boolean {
  return /^\d{6}$/.test(value.trim())
}

export function formatPrice(rubles: number): string {
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'RUB',
    minimumFractionDigits: 0,
  }).format(rubles)
}

let debounceTimer: ReturnType<typeof setTimeout> | null = null
export function debounce(fn: (...args: any[]) => void, ms: number) {
  return (...args: any[]) => {
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => fn(...args), ms)
  }
}
