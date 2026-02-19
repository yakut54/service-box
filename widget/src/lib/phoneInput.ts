/**
 * handlePhoneInput — proper cursor restoration after phone mask formatting.
 * Mirrors admin/src/composables/usePhoneInput.ts logic.
 */
import { formatPhone } from './utils'

function digitsOnly(v: string): string {
  return v.replace(/\D/g, '')
}

/**
 * @input handler: call from @input="handlePhoneInput($event, v => form.phone = v)"
 * Formats the value, forces DOM update, and restores cursor to the correct digit position.
 */
export function handlePhoneInput(event: Event, setter: (value: string) => void): void {
  const input = event.target as HTMLInputElement
  const rawValue = input.value
  const cursorPos = input.selectionStart ?? rawValue.length

  // Count user digits before cursor (excluding country code digit '7')
  const beforeCursor = rawValue.slice(0, cursorPos)
  const digitsBC = digitsOnly(beforeCursor)
  const userDigitsBC = digitsBC.startsWith('7')
    ? Math.max(0, digitsBC.length - 1)
    : digitsBC.length

  const formatted = formatPhone(rawValue)
  setter(formatted)

  // Force DOM to formatted value (Vue may skip re-render if reactive value unchanged)
  input.value = formatted

  // Restore cursor after Vue's next microtask
  requestAnimationFrame(() => {
    if (!formatted) { input.setSelectionRange(0, 0); return }
    let count = 0
    let pos = formatted.length
    // Scan from position 4 (after '+7 (') to find the correct digit position
    for (let i = 4; i < formatted.length; i++) {
      if (count === userDigitsBC) { pos = i; break }
      if (/\d/.test(formatted[i])) count++
    }
    input.setSelectionRange(pos, pos)
  })
}
