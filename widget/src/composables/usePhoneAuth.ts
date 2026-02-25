import { ref, onMounted } from 'vue'
import { useShopStore } from '@/stores/shop'
import { formatPhone, cleanPhone, isPhoneValid } from '@/lib/utils'
import { handlePhoneInput } from '@/lib/phoneInput'

const PHONE_KEY = 'sb-customer-phone'
const TOKEN_KEY = 'sb-phone-token'

export type AuthStep = 'phone' | 'code' | 'results'

export function usePhoneAuth(onAuthenticated: (phone: string, token: string) => void) {
  const shopStore = useShopStore()

  const step      = ref<AuthStep>('phone')
  const phone     = ref('')
  const phoneValid = ref(false)
  const otpCode   = ref('')
  const otpSending = ref(false)
  const otpError  = ref('')
  const devCode   = ref('')
  const phoneToken = ref('')

  function rawPhone(): string {
    return '+' + cleanPhone(phone.value)
  }

  function onPhoneInput(e: Event) {
    handlePhoneInput(e, (v) => {
      phone.value = v
      phoneValid.value = isPhoneValid(v)
      otpError.value = ''
    })
  }

  onMounted(() => {
    const saved      = localStorage.getItem(`${PHONE_KEY}:${shopStore.shopId}`)
    const savedToken = localStorage.getItem(`${TOKEN_KEY}:${shopStore.shopId}`)

    if (saved) {
      phone.value = formatPhone(saved)
      phoneValid.value = isPhoneValid(phone.value)
    }

    if (saved && savedToken) {
      phoneToken.value = savedToken
      step.value = 'results'
      onAuthenticated(rawPhone(), savedToken)
    }
  })

  async function requestCode() {
    if (!isPhoneValid(phone.value)) return

    otpSending.value = true
    otpError.value   = ''
    devCode.value    = ''

    try {
      const resp = await shopStore.getApi().requestPhoneCode(rawPhone())
      devCode.value  = resp._dev_code || ''
      step.value     = 'code'
      otpCode.value  = devCode.value  // dev: auto-fill
    } catch (e: any) {
      otpError.value = e.message || 'Не удалось отправить код'
    }
    otpSending.value = false
  }

  async function verifyCode() {
    if (otpCode.value.length !== 4) return

    otpSending.value = true
    otpError.value   = ''

    try {
      const resp = await shopStore.getApi().verifyPhoneCode(rawPhone(), otpCode.value)
      phoneToken.value = resp.token

      localStorage.setItem(`${PHONE_KEY}:${shopStore.shopId}`, rawPhone())
      localStorage.setItem(`${TOKEN_KEY}:${shopStore.shopId}`, resp.token)

      step.value = 'results'
      onAuthenticated(rawPhone(), resp.token)
    } catch (e: any) {
      otpError.value = e.message || 'Неверный код'
    }
    otpSending.value = false
  }

  function resetAuth(message?: string) {
    step.value       = 'phone'
    phoneToken.value = ''
    otpCode.value    = ''
    otpError.value   = message || ''
    localStorage.removeItem(`${TOKEN_KEY}:${shopStore.shopId}`)
  }

  return {
    step, phone, phoneValid, otpCode, otpSending, otpError, devCode, phoneToken,
    rawPhone, onPhoneInput, requestCode, verifyCode, resetAuth,
  }
}
