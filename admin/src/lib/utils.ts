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
