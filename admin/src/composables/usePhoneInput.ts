/**
 * usePhoneInput — composable for Russian phone mask +7 (XXX) XXX-XX-XX
 * Handles cursor position correctly on any edit (insert / delete / paste)
 */



function digitsOnly(v: string): string {
  return v.replace(/\D/g, '')
}

/**
 * Format 10 user digits (after country code) into mask.
 * Input: raw string typed by user (may contain +7, 8, spaces, etc.)
 * Output: formatted string like "+7 (913) 469-93-66"
 */
export function applyPhoneMask(raw: string): string {
  // Extract digits
  let digits = digitsOnly(raw)

  // Normalize country code
  if (digits.startsWith('8')) digits = '7' + digits.slice(1)
  if (digits.startsWith('7')) digits = digits.slice(1) // remove country code, we'll re-add
  if (!digits) return ''

  // Limit to 10 digits (after country code)
  digits = digits.slice(0, 10)

  let result = '+7 ('
  const d = digits

  if (d.length === 0) return '+7 ('
  result += d.slice(0, 3)
  if (d.length < 3) return result
  result += ') '
  result += d.slice(3, 6)
  if (d.length < 6) return result
  result += '-'
  result += d.slice(6, 8)
  if (d.length < 8) return result
  result += '-'
  result += d.slice(8, 10)
  return result
}

/**
 * Returns the correct cursor position after formatting
 * Based on how many digits were before the old cursor position
 */
export function getPhoneCursorPos(formatted: string, digitsBeforeCursor: number): number {
  let count = 0
  // skip country code digit (7)
  let startCounting = false
  for (let i = 0; i < formatted.length; i++) {
    const ch = formatted[i]
    if (ch === '7' && !startCounting) { startCounting = true; continue }
    if (!startCounting) continue
    if (/\d/.test(ch)) {
      if (count === digitsBeforeCursor) return i
      count++
    }
  }
  return formatted.length
}

/**
 * oninput handler to be used with :value + @input
 * Call this from your @input handler:
 *   @input="handlePhoneInput($event, (v) => form.phone = v)"
 */
export function handlePhoneInput(
    event: Event,
    setter: (value: string) => void
): void {
  const input = event.target as HTMLInputElement
  const rawValue = input.value
  const cursorPos = input.selectionStart ?? rawValue.length

  // Count user digits before cursor (skip country code)
  const beforeCursor = rawValue.slice(0, cursorPos)
  const digitsBC = digitsOnly(beforeCursor)
  // subtract 1 if starts with 7 (country code)
  const userDigitsBC = digitsBC.startsWith('7')
      ? Math.max(0, digitsBC.length - 1)
      : digitsBC.length

  const formatted = applyPhoneMask(rawValue)
  setter(formatted)

  // CRITICAL: Always force the DOM value to the formatted string.
  // Vue skips re-render when the new value equals the old reactive value,
  // leaving the raw unformatted text in the DOM (e.g. when user types an 11th digit).
  input.value = formatted

  // Restore cursor position after Vue re-renders
  requestAnimationFrame(() => {
    if (formatted.length === 0) {
      input.setSelectionRange(0, 0)
      return
    }
    // Find position of userDigitsBC-th user digit in formatted string
    let count = 0
    let pos = formatted.length
    for (let i = 4; i < formatted.length; i++) { // start after '+7 ('
      if (count === userDigitsBC) { pos = i; break }
      if (/\d/.test(formatted[i])) count++
    }
    input.setSelectionRange(pos, pos)
  })
}

/** Validate Russian phone: must have exactly 10 user digits */
export function isValidPhone(value: string): boolean {
  const digits = digitsOnly(value)
  if (digits.startsWith('7')) return digits.length === 11
  return digits.length === 10
}
