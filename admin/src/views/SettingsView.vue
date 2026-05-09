<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import type { Product } from '@/types'
import CustomSelect from '@/components/CustomSelect.vue'
import TimeInput from '@/components/TimeInput.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import { timezoneOptions } from '@/shared/lib/timezones'

const authStore = useAuthStore()

// ── Shop name + domain ────────────────────────────────────────
const shopName = ref('')
const shopDomain = ref('')
const savingName = ref(false)
const nameSuccess = ref(false)
const nameError = ref('')

async function saveShopName() {
  if (!shopName.value.trim()) { nameError.value = 'Название не может быть пустым'; return }
  savingName.value = true
  nameError.value = ''
  nameSuccess.value = false
  try {
    const updated = await api.updateShop({ name: shopName.value.trim(), domain: shopDomain.value.trim() || null })
    if (authStore.shop) { authStore.shop.name = updated.name; authStore.shop.domain = updated.domain }
    nameSuccess.value = true
    setTimeout(() => nameSuccess.value = false, 3000)
  } catch (e: unknown) {
    nameError.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    savingName.value = false
  }
}

// ── Embed code generator ──────────────────────────────────────
type EmbedMode = 'popup' | 'inline' | 'auto'

const embedMode        = ref<EmbedMode>('popup')
const embedServiceId   = ref('')
const embedProducts    = ref<Product[]>([])

onMounted(async () => {
  try {
    const res = await api.getProducts({ per_page: '200' })
    embedProducts.value = res.data
  } catch {
    // не критично — поле ввода останется как fallback
  }
})

const typeLabel: Record<string, string> = { service: 'Услуга', physical: 'Товар', digital: 'Цифровой' }

const embedProductOptions = computed(() => [
  { value: '', label: 'Весь каталог (без deep link)' },
  ...embedProducts.value.map(p => ({
    value: p.id,
    label: `${p.name} · ${typeLabel[p.type] ?? p.type}`,
  })),
])
const embedPrefillName  = ref('')
const embedPrefillPhone = ref('')
const copied = ref(false)

const embedModes: { value: EmbedMode; label: string; desc: string }[] = [
  { value: 'popup',  label: 'Попап',        desc: 'Кнопка в углу экрана, виджет открывается поверх страницы' },
  { value: 'inline', label: 'Встроенный',   desc: 'Виджет встраивается прямо в блок на странице, всегда виден' },
  { value: 'auto',   label: 'Автооткрытие', desc: 'Попап, но открывается сразу при загрузке страницы' },
]

const generatedEmbedCode = computed(() => {
  const apiKey = authStore.shop?.api_key || 'YOUR_API_KEY'
  const src = 'https://cdn.servicebox.ru/widget.js'
  const extras: string[] = []
  if (embedServiceId.value.trim())    extras.push(`data-service-id="${embedServiceId.value.trim()}"`)
  if (embedPrefillName.value.trim())  extras.push(`data-prefill-name="${embedPrefillName.value.trim()}"`)
  if (embedPrefillPhone.value.trim()) extras.push(`data-prefill-phone="${embedPrefillPhone.value.trim()}"`)
  const extrasStr = extras.length ? ' ' + extras.join(' ') : ''

  if (embedMode.value === 'inline') {
    return `<div id="servicebox-widget"\n  data-shop-id="${apiKey}"\n  data-mode="inline"${extrasStr}\n  style="height:600px;">\n</div>\n<script src="${src}"><\/script>`
  }
  const modeAttr = embedMode.value === 'auto' ? ' data-mode="auto"' : ''
  return `<script src="${src}" data-shop-id="${apiKey}"${modeAttr}${extrasStr}><\/script>`
})

function copyCode() {
  navigator.clipboard.writeText(generatedEmbedCode.value)
  copied.value = true
  setTimeout(() => copied.value = false, 2000)
}

const qrCodeUrl = computed(() => {
  const domain = authStore.shop?.domain
  if (!domain) return null
  let url = domain.startsWith('http') ? domain : `https://${domain}`
  url += '?sb_open=1'
  if (embedServiceId.value.trim()) url += `&sb_service=${embedServiceId.value.trim()}`
  return url
})

const qrImgSrc = computed(() => {
  if (!qrCodeUrl.value) return null
  return `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(qrCodeUrl.value)}`
})

const hasTelegramFeature = computed(() =>
  ['start', 'business', 'pro'].includes(authStore.shop?.subscription_plan ?? '')
)

const telegramCode = ref('')
const generatingCode = ref(false)
const telegramError = ref('')
const disconnecting = ref(false)
const showDisconnectConfirm = ref(false)

async function generateTelegramCode() {
  if (!authStore.shop) return
  generatingCode.value = true
  telegramError.value = ''
  try {
    const resp = await api.generateTelegramCode()
    telegramCode.value = resp.code
  } catch (e: unknown) {
    telegramError.value = e instanceof Error ? e.message : 'Ошибка генерации кода'
  }
  generatingCode.value = false
}

async function disconnectTelegram() {
  if (!authStore.shop) return
  disconnecting.value = true
  try {
    await api.disconnectTelegram()
    if (authStore.shop) {
      authStore.shop.telegram_bot_connected = false
      authStore.shop.telegram_chat_id = null
    }
    telegramCode.value = ''
  } catch (e: unknown) {
    telegramError.value = e instanceof Error ? e.message : 'Ошибка отключения'
  }
  disconnecting.value = false
}

// ── MAX messenger ─────────────────────────────────────────────
const maxCode          = ref('')
const generatingMaxCode = ref(false)
const maxError         = ref('')
const disconnectingMax = ref(false)
const showMaxDisconnectConfirm = ref(false)
const maxConnected     = ref(false)

onMounted(async () => {
  try {
    const s = await api.getMaxStatus()
    maxConnected.value = s.connected
  } catch {}
})

async function generateMaxCode() {
  generatingMaxCode.value = true
  maxError.value = ''
  try {
    const resp = await api.generateMaxCode()
    maxCode.value = resp.code
  } catch (e: unknown) {
    maxError.value = e instanceof Error ? e.message : 'Ошибка генерации кода'
  }
  generatingMaxCode.value = false
}

async function disconnectMax() {
  disconnectingMax.value = true
  try {
    await api.disconnectMax()
    maxConnected.value = false
    maxCode.value = ''
  } catch (e: unknown) {
    maxError.value = e instanceof Error ? e.message : 'Ошибка отключения'
  }
  disconnectingMax.value = false
}

// ── Work hours ───────────────────────────────────────────────
const workStart = ref('09:00')
const workEnd = ref('20:00')
const slotDuration = ref('30')
const timezone = ref('Europe/Moscow')
const savingHours = ref(false)
const hoursSuccess = ref(false)
const hoursError = ref('')

// ── Change password ───────────────────────────────────────────
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')
const savingPassword = ref(false)
const passwordSuccess = ref(false)
const passwordError = ref('')

const newPasswordTouched        = ref(false)
const newPasswordConfirmTouched = ref(false)

const newPasswordLengthError = computed(() => {
  if (!newPasswordTouched.value || !newPassword.value) return ''
  return newPassword.value.length >= 8 ? '' : 'Минимум 8 символов'
})

const newPasswordConfirmStatus = computed((): 'error' | 'success' | undefined => {
  if (!newPasswordConfirmTouched.value || !newPasswordConfirm.value) return undefined
  return newPasswordConfirm.value === newPassword.value ? 'success' : 'error'
})

async function savePassword() {
  newPasswordTouched.value        = true
  newPasswordConfirmTouched.value = true
  if (newPasswordLengthError.value || !currentPassword.value || !newPassword.value || !newPasswordConfirm.value) return
  if (newPassword.value !== newPasswordConfirm.value) return
  savingPassword.value = true
  passwordError.value = ''
  passwordSuccess.value = false
  try {
    await api.changePassword(currentPassword.value, newPassword.value, newPasswordConfirm.value)
    passwordSuccess.value = true
    currentPassword.value = ''
    newPassword.value = ''
    newPasswordConfirm.value = ''
    setTimeout(() => passwordSuccess.value = false, 3000)
  } catch (e: unknown) {
    passwordError.value = e instanceof Error ? e.message : 'Ошибка смены пароля'
  } finally {
    savingPassword.value = false
  }
}

const slotOptions = [
  { value: '10', label: '10 минут' },
  { value: '15', label: '15 минут' },
  { value: '20', label: '20 минут' },
  { value: '30', label: '30 минут' },
  { value: '45', label: '45 минут' },
  { value: '60', label: '1 час' },
]


onMounted(() => {
  if (authStore.shop) {
    shopName.value = authStore.shop.name || ''
    shopDomain.value = authStore.shop.domain || ''
    workStart.value = authStore.shop.work_start || '09:00'
    workEnd.value = authStore.shop.work_end || '20:00'
    slotDuration.value = String(authStore.shop.slot_duration || 30)
    timezone.value = authStore.shop.timezone || 'Europe/Moscow'
  }
})

// ── YooKassa ──────────────────────────────────────────────────
const yookassaShopId    = ref('')
const yookassaSecretKey = ref('')
const savingYookassa    = ref(false)
const yookassaSuccess   = ref(false)
const yookassaError     = ref('')

onMounted(() => {
  yookassaShopId.value = authStore.shop?.yookassa_shop_id || ''
})

async function saveYookassa() {
  if (!yookassaShopId.value) {
    yookassaError.value = 'Укажите Shop ID'
    return
  }
  savingYookassa.value = true
  yookassaError.value  = ''
  yookassaSuccess.value = false
  try {
    const payload: Record<string, string | null> = {
      yookassa_shop_id: yookassaShopId.value,
    }
    if (yookassaSecretKey.value) {
      payload.yookassa_secret_key = yookassaSecretKey.value
    }
    const updated = await api.updateShop(payload)
    if (authStore.shop) {
      authStore.shop.yookassa_shop_id = updated.yookassa_shop_id ?? yookassaShopId.value
    }
    yookassaSecretKey.value = ''
    yookassaSuccess.value = true
    setTimeout(() => yookassaSuccess.value = false, 3000)
  } catch (e: unknown) {
    yookassaError.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    savingYookassa.value = false
  }
}

// ── Widget customization ──────────────────────────────────────
const hasWidgetFeature = computed(() =>
  ['business', 'pro'].includes(authStore.shop?.subscription_plan ?? '')
)

const hasWidgetProFeature = computed(() =>
  authStore.shop?.subscription_plan === 'pro'
)

const widgetColor        = ref('#6366f1')
const widgetBorderRadius = ref<4 | 8 | 16>(8)
const widgetShowPrice       = ref(true)
const widgetShowDuration    = ref(true)
const widgetShowMasterName  = ref(true)
const widgetShowDescription = ref(true)
const widgetLogoUrl      = ref<string | null>(null)
const widgetWhiteLabel   = ref(false)
const widgetCustomCss    = ref('')
const savingWidget       = ref(false)
const widgetSuccess      = ref(false)
const widgetError        = ref('')
const uploadingLogo      = ref(false)

onMounted(() => {
  const wc = authStore.shop?.widget_config
  if (wc) {
    if (wc.primary_color)   widgetColor.value = wc.primary_color
    if (wc.border_radius != null) widgetBorderRadius.value = wc.border_radius as 4 | 8 | 16
    if (wc.show_price       != null) widgetShowPrice.value       = wc.show_price
    if (wc.show_duration    != null) widgetShowDuration.value    = wc.show_duration
    if (wc.show_master_name != null) widgetShowMasterName.value  = wc.show_master_name
    if (wc.show_description != null) widgetShowDescription.value = wc.show_description
    widgetLogoUrl.value = wc.logo_url ?? null
    widgetWhiteLabel.value = wc.white_label ?? false
    widgetCustomCss.value  = wc.custom_css ?? ''
  }
})

async function uploadLogo(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return

  const ALLOWED = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']
  const MAX_MB  = 5
  if (!ALLOWED.includes(file.type)) { widgetError.value = 'Только JPG, PNG или WEBP'; return }
  if (file.size > MAX_MB * 1024 * 1024) { widgetError.value = `Файл слишком большой (макс. ${MAX_MB} МБ, у вас ${(file.size / 1024 / 1024).toFixed(1)} МБ)`; return }

  uploadingLogo.value = true
  widgetError.value   = ''
  const oldUrl = widgetLogoUrl.value
  try {
    const res = await api.uploadImage(file)
    widgetLogoUrl.value = res.url
    if (oldUrl) await api.deleteImage(oldUrl).catch(() => {})
  } catch {
    widgetError.value = 'Ошибка загрузки логотипа'
  } finally {
    uploadingLogo.value = false
  }
}

async function removeLogo() {
  const url = widgetLogoUrl.value
  widgetLogoUrl.value = null
  if (url) {
    await api.deleteImage(url).catch(() => {})
  }
}

async function saveWidgetConfig() {
  savingWidget.value = true
  widgetError.value  = ''
  widgetSuccess.value = false
  try {
    const updated = await api.updateShop({
      widget_config: {
        primary_color:    widgetColor.value,
        border_radius:    widgetBorderRadius.value,
        logo_url:         widgetLogoUrl.value,
        show_price:       widgetShowPrice.value,
        show_duration:    widgetShowDuration.value,
        show_master_name: widgetShowMasterName.value,
        show_description: widgetShowDescription.value,
        white_label:      widgetWhiteLabel.value,
        custom_css:       widgetCustomCss.value || null,
      },
    })
    if (authStore.shop) authStore.shop.widget_config = updated.widget_config
    widgetSuccess.value = true
    setTimeout(() => widgetSuccess.value = false, 3000)
  } catch (e: unknown) {
    widgetError.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    savingWidget.value = false
  }
}

async function saveWorkHours() {
  if (workStart.value >= workEnd.value) {
    hoursError.value = 'Время начала должно быть раньше времени окончания'
    return
  }
  savingHours.value = true
  hoursError.value = ''
  hoursSuccess.value = false
  try {
    const updated = await api.updateShop({
      work_start: workStart.value,
      work_end: workEnd.value,
      slot_duration: Number(slotDuration.value),
      timezone: timezone.value,
    })
    if (authStore.shop) {
      authStore.shop.work_start = updated.work_start
      authStore.shop.work_end = updated.work_end
      authStore.shop.slot_duration = updated.slot_duration
      authStore.shop.timezone = updated.timezone
    }
    hoursSuccess.value = true
    setTimeout(() => hoursSuccess.value = false, 3000)
  } catch (e: unknown) {
    hoursError.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    savingHours.value = false
  }
}

// ── Widget analytics funnel ───────────────────────────────────
type FunnelItem = { event: string; label: string; sessions: number; pct_top: number }
const funnelDays    = ref<7 | 30 | 90>(30)
const funnelData    = ref<FunnelItem[]>([])
const funnelLoading = ref(false)
const funnelError   = ref('')

async function loadFunnel() {
  if (!hasWidgetProFeature.value) return
  funnelLoading.value = true
  funnelError.value = ''
  try {
    const res = await api.getWidgetAnalytics(funnelDays.value)
    funnelData.value = res.funnel
  } catch (e: unknown) {
    funnelError.value = e instanceof Error ? e.message : 'Ошибка загрузки'
  } finally {
    funnelLoading.value = false
  }
}

watch(funnelDays, loadFunnel)

onMounted(() => {
  if (hasWidgetProFeature.value) loadFunnel()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Настройки</h1>
      <p class="text-gray-500 dark:text-gray-400 mt-1">Управление магазином</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <!-- Левая колонка -->
      <div class="flex flex-col gap-6">
        <!-- Shop info -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Информация о магазине</h2>
          <div class="space-y-4">
            <div>
              <label class="label">Название магазина</label>
              <input v-model="shopName" type="text" class="input" placeholder="Название магазина" />
            </div>
            <div>
              <label class="label">Сайт магазина <span class="text-gray-400 font-normal">(используется в Telegram-уведомлениях)</span></label>
              <input v-model="shopDomain" type="url" class="input" placeholder="https://example.com" />
            </div>
            <div>
              <button @click="saveShopName" :disabled="savingName" class="btn-primary">
                {{ savingName ? 'Сохраняем…' : nameSuccess ? 'Сохранено!' : 'Сохранить' }}
              </button>
              <p v-if="nameError" class="text-red-500 text-xs mt-1">{{ nameError }}</p>
            </div>
            <div>
              <label class="label">API ключ</label>
              <input type="text" :value="authStore.shop?.api_key" class="input font-mono text-sm" disabled />
            </div>
            <div>
              <label class="label">Тарифный план</label>
              <div class="flex items-center gap-3">
                <span class="badge bg-primary-100 dark:bg-primary-900/30 text-primary-800 dark:text-primary-300 text-sm px-3 py-1">
                  {{ authStore.shop?.subscription_plan?.toUpperCase() || 'MICRO' }}
                </span>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                  Действует до: {{ authStore.shop?.subscription_expires_at
                    ? new Date(authStore.shop.subscription_expires_at).toLocaleDateString('ru-RU')
                    : 'бессрочно' }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Embed code generator -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Код виджета</h2>
          <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">Настройте режим и скопируйте код на сайт</p>

          <!-- Mode tabs -->
          <div class="flex gap-1 p-1 bg-gray-100 dark:bg-gray-800 rounded-lg mb-4">
            <button
              v-for="m in embedModes" :key="m.value"
              @click="embedMode = m.value"
              :class="['flex-1 text-sm py-1.5 px-2 rounded-md transition-all font-medium',
                embedMode === m.value
                  ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
                  : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300']"
            >{{ m.label }}</button>
          </div>
          <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ embedModes.find(m => m.value === embedMode)?.desc }}</p>

          <!-- Optional service deep link -->
          <div class="mb-3">
            <label class="label">Открыть сразу на услуге <span class="text-gray-400 font-normal">(опционально)</span></label>
            <CustomSelect
              v-model="embedServiceId"
              :options="embedProductOptions"
              placeholder="Весь каталог (без deep link)"
              searchable
            />
          </div>

          <!-- Prefill -->
          <div class="grid grid-cols-2 gap-3 mb-4">
            <div>
              <label class="label">Имя клиента <span class="text-gray-400 font-normal">(prefill)</span></label>
              <input v-model="embedPrefillName" type="text" class="input text-sm" placeholder="Иван" />
            </div>
            <div>
              <label class="label">Телефон <span class="text-gray-400 font-normal">(prefill)</span></label>
              <input v-model="embedPrefillPhone" type="text" class="input text-sm" placeholder="+7..." />
            </div>
          </div>

          <!-- Generated code -->
          <div class="relative">
            <pre class="bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto text-sm whitespace-pre-wrap break-all">{{ generatedEmbedCode }}</pre>
            <button @click="copyCode" class="absolute top-2 right-2 btn-ghost btn-sm bg-gray-800 text-white hover:bg-gray-700">
              <svg v-if="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
              <svg v-else class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              {{ copied ? 'Скопировано!' : 'Копировать' }}
            </button>
          </div>

          <!-- QR code (only if shop domain is set) -->
          <div v-if="qrImgSrc" class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
            <div class="label mb-2">QR-код</div>
            <div class="flex items-start gap-4">
              <img :src="qrImgSrc" width="120" height="120" class="rounded border border-gray-200 dark:border-gray-700 bg-white" alt="QR-код виджета" />
              <div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Ведёт на:</p>
                <p class="text-xs font-mono text-gray-700 dark:text-gray-300 break-all">{{ qrCodeUrl }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Клиент сканирует QR → попадает на сайт → виджет открывается автоматически</p>
              </div>
            </div>
          </div>
          <p v-else class="mt-3 text-xs text-gray-400 dark:text-gray-500">
            Чтобы получить QR-код, укажите домен сайта в разделе «Информация о магазине»
          </p>
        </div>

        <!-- Change password -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Смена пароля</h2>
          <div class="space-y-4">
            <PasswordInput label="Текущий пароль" v-model="currentPassword" placeholder="Введите текущий пароль" autocomplete="current-password" />

            <div>
              <PasswordInput label="Новый пароль" v-model="newPassword" placeholder="Минимум 8 символов" autocomplete="new-password" with-generate @generate="v => { newPasswordConfirm = v; newPasswordConfirmTouched = true }" @blur="newPasswordTouched = true" />
              <p v-if="newPasswordLengthError" class="mt-1 text-sm text-red-500">{{ newPasswordLengthError }}</p>
            </div>

            <div>
              <PasswordInput label="Подтверждение нового пароля" v-model="newPasswordConfirm" placeholder="Повторите новый пароль" autocomplete="new-password" :status="newPasswordConfirmStatus" @blur="newPasswordConfirmTouched = true" />
              <p v-if="newPasswordConfirmStatus === 'error'"   class="mt-1 text-sm text-red-500">Пароли не совпадают</p>
              <p v-if="newPasswordConfirmStatus === 'success'" class="mt-1 text-sm text-green-600">Пароли совпадают</p>
            </div>
            <div v-if="passwordError" class="text-sm text-red-600 dark:text-red-400">{{ passwordError }}</div>
            <div v-if="passwordSuccess" class="text-sm text-green-600 dark:text-green-400">Пароль успешно изменён!</div>
            <button @click="savePassword" :disabled="savingPassword" class="btn-primary">
              {{ savingPassword ? 'Сохранение...' : 'Сменить пароль' }}
            </button>
          </div>
        </div>

        <!-- Telegram -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Telegram уведомления</h2>

          <!-- Plan gate: Micro -->
          <div v-if="!hasTelegramFeature" class="space-y-3">
            <div class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
              <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m2-6a4 4 0 100-8 4 4 0 000 8z" />
              </svg>
              <div>
                <div class="font-medium text-gray-700 dark:text-gray-300 text-sm">Недоступно на тарифе Micro</div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Telegram-уведомления о новых записях и заказах доступны на тарифах <span class="font-medium">Start и выше</span>.</div>
              </div>
            </div>
          </div>

          <!-- Connected -->
          <div v-else-if="authStore.shop?.telegram_bot_connected" class="space-y-3">
            <div class="flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
              <svg class="w-6 h-6 text-green-600 dark:text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <div class="flex-1">
                <div class="font-medium text-green-800 dark:text-green-300">Telegram подключён</div>
                <div class="text-sm text-green-600 dark:text-green-400">Уведомления о новых записях и заказах активны</div>
              </div>
            </div>
            <button @click="showDisconnectConfirm = true" :disabled="disconnecting" class="btn-secondary text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 border-red-200 dark:border-red-800">
              {{ disconnecting ? 'Отключение...' : 'Отключить Telegram' }}
            </button>
            <div v-if="telegramError" class="text-red-600 text-sm">{{ telegramError }}</div>
          </div>

          <!-- Not connected -->
          <div v-else class="space-y-4">
            <p class="text-gray-500 dark:text-gray-400 text-sm">
              Подключите Telegram для получения уведомлений о новых заказах и записях.
            </p>
            <div v-if="telegramCode" class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
              <div class="text-sm text-blue-800 dark:text-blue-300 mb-2">1. Откройте бота <a href="https://t.me/sb_widget_bot" target="_blank" class="font-semibold underline">@sb_widget_bot</a></div>
              <div class="text-sm text-blue-800 dark:text-blue-300 mb-1">2. Отправьте код:</div>
              <div class="font-mono text-lg font-bold text-blue-900 dark:text-blue-200 tracking-widest">{{ telegramCode }}</div>
              <div class="text-xs text-blue-600 dark:text-blue-400 mt-2">Код действителен 10 минут</div>
            </div>
            <button v-if="!telegramCode" @click="generateTelegramCode" :disabled="generatingCode" class="btn-primary">
              {{ generatingCode ? 'Генерация...' : 'Получить код подключения' }}
            </button>
            <div v-if="telegramError" class="text-red-600 text-sm">{{ telegramError }}</div>
          </div>
        </div>

        <!-- MAX -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">MAX уведомления</h2>

          <div v-if="!hasTelegramFeature" class="space-y-3">
            <div class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
              <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m2-6a4 4 0 100-8 4 4 0 000 8z" />
              </svg>
              <div>
                <div class="font-medium text-gray-700 dark:text-gray-300 text-sm">Недоступно на тарифе Micro</div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">MAX-уведомления доступны на тарифах <span class="font-medium">Start и выше</span>.</div>
              </div>
            </div>
          </div>

          <div v-else-if="maxConnected" class="space-y-3">
            <div class="flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
              <svg class="w-6 h-6 text-green-600 dark:text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <div class="flex-1">
                <div class="font-medium text-green-800 dark:text-green-300">MAX подключён</div>
                <div class="text-sm text-green-600 dark:text-green-400">Уведомления о новых записях и заказах активны</div>
              </div>
            </div>
            <button @click="showMaxDisconnectConfirm = true" :disabled="disconnectingMax" class="btn-secondary text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 border-red-200 dark:border-red-800">
              {{ disconnectingMax ? 'Отключение...' : 'Отключить MAX' }}
            </button>
            <div v-if="maxError" class="text-red-600 text-sm">{{ maxError }}</div>
          </div>

          <div v-else class="space-y-4">
            <p class="text-gray-500 dark:text-gray-400 text-sm">
              Подключите MAX для получения уведомлений о новых заказах и записях.
            </p>
            <div v-if="maxCode" class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
              <div class="text-sm text-blue-800 dark:text-blue-300 mb-2">1. Откройте бота в мессенджере MAX</div>
              <div class="text-sm text-blue-800 dark:text-blue-300 mb-1">2. Отправьте код:</div>
              <div class="font-mono text-lg font-bold text-blue-900 dark:text-blue-200 tracking-widest">{{ maxCode }}</div>
              <div class="text-xs text-blue-600 dark:text-blue-400 mt-2">Код действителен 10 минут</div>
            </div>
            <button v-if="!maxCode" @click="generateMaxCode" :disabled="generatingMaxCode" class="btn-primary">
              {{ generatingMaxCode ? 'Генерация...' : 'Получить код подключения' }}
            </button>
            <div v-if="maxError" class="text-red-600 text-sm">{{ maxError }}</div>
          </div>
        </div>
      </div>

      <!-- Правая колонка -->
      <div class="flex flex-col gap-6">
        <!-- Work hours -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Рабочие часы</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Время работы и шаг слотов для записи</p>

          <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
              <label class="label">Начало рабочего дня</label>
              <TimeInput v-model="workStart" />
            </div>
            <div>
              <label class="label">Конец рабочего дня</label>
              <TimeInput v-model="workEnd" />
            </div>
          </div>

          <div class="mb-4">
            <label class="label">Шаг слота (интервал записи)</label>
            <CustomSelect
                v-model="slotDuration"
                :options="slotOptions"
                label="Шаг слота (интервал записи)"
                placeholder="Выберите интервал"
            />
          </div>

          <div class="mb-4">
            <label class="label">Часовой пояс</label>
            <CustomSelect
                v-model="timezone"
                :options="timezoneOptions"
                label="Часовой пояс"
                placeholder="Выберите часовой пояс"
                searchable
            />
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
              Используется для корректного отображения слотов записи
            </p>
          </div>

          <div v-if="hoursError" class="mb-3 text-sm text-red-600 dark:text-red-400">{{ hoursError }}</div>
          <div v-if="hoursSuccess" class="mb-3 text-sm text-green-600 dark:text-green-400">Сохранено!</div>

          <button @click="saveWorkHours" :disabled="savingHours" class="btn-primary">
            {{ savingHours ? 'Сохранение...' : 'Сохранить' }}
          </button>
        </div>

        <!-- Payment settings -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">ЮКасса — приём оплаты</h2>

          <div v-if="authStore.shop?.yookassa_shop_id" class="mb-4 flex items-center gap-2 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
            <svg class="w-4 h-4 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-sm text-green-700 dark:text-green-400">Подключено · Shop ID: <span class="font-mono font-medium">{{ authStore.shop.yookassa_shop_id }}</span></span>
          </div>

          <div class="space-y-4">
            <div>
              <label class="label">Shop ID <span class="text-gray-400 font-normal">(из личного кабинета ЮКасса)</span></label>
              <input v-model="yookassaShopId" type="text" class="input font-mono" placeholder="123456" />
            </div>
            <div>
              <label class="label">Секретный ключ <span class="text-gray-400 font-normal">(оставьте пустым чтобы не менять)</span></label>
              <PasswordInput v-model="yookassaSecretKey" placeholder="live_xxxxxxxxxxxxxxxxxxxx" />
            </div>

            <div v-if="yookassaError" class="text-sm text-red-600">{{ yookassaError }}</div>
            <div v-if="yookassaSuccess" class="text-sm text-green-600">Сохранено!</div>

            <button @click="saveYookassa" :disabled="savingYookassa" class="btn-primary">
              {{ savingYookassa ? 'Сохранение...' : 'Сохранить' }}
            </button>

            <p class="text-xs text-gray-400 dark:text-gray-500">
              Настройки из раздела «Интеграции → API» в
              <a href="https://yookassa.ru/my" target="_blank" rel="noopener" class="text-primary-500 hover:underline">личном кабинете ЮКасса</a>.
              Секретный ключ нужен для приёма платежей в виджете магазина.
            </p>
          </div>
        </div>

        <!-- Widget customization -->
        <div class="card">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Внешний вид виджета</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Цвет, логотип и отображение элементов</p>

          <!-- Plan gate -->
          <div v-if="!hasWidgetFeature" class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m2-6a4 4 0 100-8 4 4 0 000 8z" />
            </svg>
            <div>
              <div class="font-medium text-gray-700 dark:text-gray-300 text-sm">Недоступно на текущем тарифе</div>
              <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Кастомизация виджета доступна на тарифе <span class="font-medium">Business и выше</span>.</div>
            </div>
          </div>

          <!-- Settings -->
          <div v-else class="space-y-5">

            <!-- Color -->
            <div>
              <label class="label">Основной цвет</label>
              <div class="flex items-center gap-3">
                <input type="color" v-model="widgetColor" class="w-10 h-10 rounded cursor-pointer border border-gray-200 dark:border-gray-700 p-0.5 bg-white dark:bg-gray-800" />
                <input type="text" v-model="widgetColor" class="input w-32 font-mono text-sm" placeholder="#6366f1" />
                <div class="flex-1 h-10 rounded-lg transition-colors" :style="{ background: widgetColor }" />
              </div>
              <p class="text-xs text-gray-400 mt-1">Кнопки, ссылки, акценты в виджете</p>
            </div>

            <!-- Border radius -->
            <div>
              <label class="label">Скругление кнопок</label>
              <div class="flex gap-2">
                <button v-for="opt in ([{ v: 4, label: 'Острые' }, { v: 8, label: 'Средние' }, { v: 16, label: 'Круглые' }] as const)"
                  :key="opt.v"
                  @click="widgetBorderRadius = opt.v"
                  :class="['btn-secondary text-sm flex-1 transition-all', widgetBorderRadius === opt.v ? 'ring-2 ring-indigo-500' : '']"
                  :style="{ borderRadius: opt.v + 'px' }">
                  {{ opt.label }}
                </button>
              </div>
            </div>

            <!-- Logo -->
            <div>
              <label class="label">Логотип магазина</label>
              <div v-if="widgetLogoUrl" class="flex items-center gap-3 mb-2">
                <img :src="widgetLogoUrl" class="h-12 w-12 object-contain rounded border border-gray-200 dark:border-gray-700 bg-white p-1" />
                <button @click="removeLogo" class="text-sm text-red-500 hover:text-red-700">Удалить</button>
              </div>
              <label class="flex items-center gap-2 cursor-pointer w-fit">
                <span class="btn-secondary text-sm">{{ uploadingLogo ? 'Загрузка...' : widgetLogoUrl ? 'Изменить логотип' : 'Загрузить логотип' }}</span>
                <input type="file" accept="image/*" @change="uploadLogo" class="hidden" :disabled="uploadingLogo" />
              </label>
              <p class="text-xs text-gray-400 mt-1">PNG или SVG, рекомендуется квадратный</p>
            </div>

            <!-- Show/hide elements -->
            <div>
              <label class="label mb-2">Показывать в карточке услуги</label>
              <div class="space-y-2">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <input type="checkbox" v-model="widgetShowPrice" class="w-4 h-4 rounded text-indigo-600" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">Цену</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <input type="checkbox" v-model="widgetShowDuration" class="w-4 h-4 rounded text-indigo-600" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">Длительность</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <input type="checkbox" v-model="widgetShowMasterName" class="w-4 h-4 rounded text-indigo-600" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">Имя мастера</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer select-none">
                  <input type="checkbox" v-model="widgetShowDescription" class="w-4 h-4 rounded text-indigo-600" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">Описание</span>
                </label>
              </div>
            </div>

            <!-- Pro: White label + Custom CSS -->
            <div v-if="hasWidgetProFeature" class="pt-4 border-t border-gray-200 dark:border-gray-700 space-y-4">
              <div class="flex items-center justify-between">
                <div>
                  <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Убрать «Powered by ServiceBox»</div>
                  <div class="text-xs text-gray-400 mt-0.5">Виджет полностью под вашим брендом</div>
                </div>
                <button
                  type="button"
                  @click="widgetWhiteLabel = !widgetWhiteLabel"
                  :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                    widgetWhiteLabel ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700']"
                >
                  <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                    widgetWhiteLabel ? 'translate-x-6' : 'translate-x-1']" />
                </button>
              </div>
              <div>
                <label class="label">Свой CSS <span class="text-gray-400 font-normal">(переопределяет стили виджета)</span></label>
                <textarea
                  v-model="widgetCustomCss"
                  class="input font-mono text-xs"
                  rows="6"
                  placeholder=".sb-catalog-card { border-radius: 12px; }"
                />
                <p class="text-xs text-gray-400 mt-1">CSS применяется внутри Shadow DOM виджета</p>
              </div>
            </div>
            <div v-else class="pt-4 border-t border-gray-200 dark:border-gray-700">
              <p class="text-xs text-gray-400">White label и Custom CSS доступны на тарифе <span class="font-medium">Pro</span></p>
            </div>

            <div v-if="widgetSuccess" class="text-sm text-green-600 dark:text-green-400">Сохранено!</div>
            <div v-if="widgetError" class="text-sm text-red-600">{{ widgetError }}</div>
            <button @click="saveWidgetConfig" :disabled="savingWidget" class="btn-primary">
              {{ savingWidget ? 'Сохранение...' : 'Сохранить внешний вид' }}
            </button>
          </div>
        </div>
        <!-- Widget analytics funnel -->
        <div class="card">
          <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Аналитика виджета</h2>
            <div v-if="hasWidgetProFeature" class="flex gap-1">
              <button
                v-for="d in ([7, 30, 90] as const)" :key="d"
                @click="funnelDays = d"
                :class="['text-xs px-2 py-1 rounded transition-colors',
                  funnelDays === d
                    ? 'bg-indigo-600 text-white'
                    : 'bg-gray-100 dark:bg-gray-800 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300']"
              >{{ d }}д</button>
            </div>
          </div>
          <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Воронка — от открытия виджета до записи</p>

          <div v-if="!hasWidgetProFeature" class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m2-6a4 4 0 100-8 4 4 0 000 8z" />
            </svg>
            <div>
              <div class="font-medium text-gray-700 dark:text-gray-300 text-sm">Только на тарифе Pro</div>
              <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Отслеживайте каждый шаг клиента — уникальная функция в СНГ.</div>
            </div>
          </div>

          <div v-else-if="funnelLoading" class="text-sm text-gray-400 py-6 text-center">Загрузка...</div>
          <div v-else-if="funnelError" class="text-sm text-red-600">{{ funnelError }}</div>
          <div v-else-if="funnelData.length" class="space-y-4">
            <div v-for="item in funnelData" :key="item.event" class="space-y-1">
              <div class="flex items-center justify-between text-sm">
                <span class="text-gray-700 dark:text-gray-300">{{ item.label }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">
                  {{ item.sessions.toLocaleString('ru-RU') }}
                  <span class="text-xs text-gray-400 font-normal ml-1">{{ item.pct_top }}%</span>
                </span>
              </div>
              <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2">
                <div class="h-2 rounded-full bg-indigo-500 transition-all duration-500" :style="{ width: item.pct_top + '%' }" />
              </div>
            </div>
          </div>
          <div v-else class="text-sm text-gray-400 py-6 text-center">Нет данных за выбранный период</div>
        </div>
      </div>
    </div>
  </div>

  <UiConfirmDialog
    v-model="showDisconnectConfirm"
    title="Отключить Telegram?"
    confirm-label="Отключить"
    :danger="true"
    @confirm="showDisconnectConfirm = false; disconnectTelegram()"
  >
    Уведомления о новых записях и заказах перестанут приходить. Подключить заново можно в любой момент.
  </UiConfirmDialog>

  <UiConfirmDialog
    v-model="showMaxDisconnectConfirm"
    title="Отключить MAX?"
    confirm-label="Отключить"
    :danger="true"
    @confirm="showMaxDisconnectConfirm = false; disconnectMax()"
  >
    Уведомления через MAX перестанут приходить. Подключить заново можно в любой момент.
  </UiConfirmDialog>
</template>
