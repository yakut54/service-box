<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useShopStore } from '@/stores/shop'
import { formatPhone, cleanPhone, isPhoneValid } from '@/lib/utils'

const emit = defineEmits<{ back: [] }>()

const shopStore = useShopStore()
const bookings = ref<any[]>([])
const loading = ref(false)
const phone = ref('')
const searched = ref(false)
const error = ref('')

// OTP state
type Step = 'phone' | 'code' | 'results'
const step = ref<Step>('phone')
const otpCode = ref('')
const otpSending = ref(false)
const otpError = ref('')
const devCode = ref('')
const phoneToken = ref('')

const PHONE_KEY = 'sb-customer-phone'
const TOKEN_KEY = 'sb-phone-token'

const statusLabels: Record<string, string> = {
  pending: 'Ожидает',
  confirmed: 'Подтверждена',
  completed: 'Завершена',
  cancelled: 'Отменена',
  no_show: 'Неявка',
}

const statusBadge: Record<string, string> = {
  pending: 'sb-badge-warning',
  confirmed: 'sb-badge-info',
  completed: 'sb-badge-success',
  cancelled: 'sb-badge-danger',
  no_show: 'sb-badge-danger',
}

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

function formatTime(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function formatTimeRange(start: string, end: string) {
  return `${formatTime(start)} – ${formatTime(end)}`
}

function isPast(dateStr: string) {
  return new Date(dateStr) < new Date()
}

const phoneValid = ref(false)

function onPhoneInput(e: Event) {
  const input = e.target as HTMLInputElement
  const cursor = input.selectionStart ?? 0
  const oldLen = input.value.length
  phone.value = formatPhone(input.value)
  phoneValid.value = isPhoneValid(phone.value)
  const newLen = phone.value.length
  const pos = Math.max(0, cursor + (newLen - oldLen))
  requestAnimationFrame(() => input.setSelectionRange(pos, pos))
}

function rawPhone(): string {
  return '+' + cleanPhone(phone.value)
}

onMounted(() => {
  const saved = localStorage.getItem(`${PHONE_KEY}:${shopStore.shopId}`)
  const savedToken = localStorage.getItem(`${TOKEN_KEY}:${shopStore.shopId}`)

  if (saved) {
    phone.value = formatPhone(saved)
    phoneValid.value = isPhoneValid(phone.value)
  }

  if (saved && savedToken) {
    phoneToken.value = savedToken
    step.value = 'results'
    loadBookings()
  }
})

async function requestCode() {
  if (!isPhoneValid(phone.value)) return

  otpSending.value = true
  otpError.value = ''
  devCode.value = ''

  try {
    const resp = await shopStore.getApi().requestPhoneCode(rawPhone())
    devCode.value = resp._dev_code || ''
    step.value = 'code'
    otpCode.value = devCode.value // dev: auto-fill
  } catch (e: any) {
    otpError.value = e.message || 'Не удалось отправить код'
  }
  otpSending.value = false
}

async function verifyCode() {
  if (otpCode.value.length !== 4) return

  otpSending.value = true
  otpError.value = ''

  try {
    const resp = await shopStore.getApi().verifyPhoneCode(rawPhone(), otpCode.value)
    phoneToken.value = resp.token

    localStorage.setItem(`${PHONE_KEY}:${shopStore.shopId}`, rawPhone())
    localStorage.setItem(`${TOKEN_KEY}:${shopStore.shopId}`, resp.token)

    step.value = 'results'
    loadBookings()
  } catch (e: any) {
    otpError.value = e.message || 'Неверный код'
  }
  otpSending.value = false
}

async function loadBookings() {
  loading.value = true
  error.value = ''
  searched.value = true

  try {
    const resp = await shopStore.getApi().getBookingsByPhone(rawPhone(), phoneToken.value)
    bookings.value = resp.data
  } catch (e: any) {
    if (e.status === 401) {
      phoneToken.value = ''
      localStorage.removeItem(`${TOKEN_KEY}:${shopStore.shopId}`)
      step.value = 'phone'
      error.value = 'Сессия истекла. Подтвердите телефон заново.'
    } else {
      error.value = e.message || 'Ошибка загрузки'
    }
  }
  loading.value = false
}

function resetPhone() {
  step.value = 'phone'
  phoneToken.value = ''
  bookings.value = []
  searched.value = false
  error.value = ''
  otpError.value = ''
  localStorage.removeItem(`${TOKEN_KEY}:${shopStore.shopId}`)
}

// ── Cancel booking ────────────────────────────────────────────
const cancelTarget = ref<any | null>(null)
const cancelling = ref(false)
const cancelError = ref('')

function canCancel(booking: any) {
  return ['pending', 'confirmed'].includes(booking.status) && !isPast(booking.start_time)
}

function openCancelConfirm(booking: any) {
  cancelTarget.value = booking
  cancelError.value = ''
}

async function doCancel() {
  if (!cancelTarget.value) return
  cancelling.value = true
  cancelError.value = ''
  try {
    await shopStore.getApi().cancelBooking(cancelTarget.value.id, rawPhone(), phoneToken.value)
    // Update locally
    const idx = bookings.value.findIndex(b => b.id === cancelTarget.value.id)
    if (idx !== -1) bookings.value[idx] = { ...bookings.value[idx], status: 'cancelled' }
    cancelTarget.value = null
  } catch (e: any) {
    cancelError.value = e.message || 'Ошибка отмены'
  } finally {
    cancelling.value = false
  }
}
</script>

<template>
  <div>
    <div class="sb-flex sb-items-center sb-gap-3 sb-mb-4">
      <button class="sb-btn sb-btn-ghost" @click="emit('back')">
        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
      </button>
      <h2 class="sb-title" style="margin-bottom: 0;">Мои записи</h2>
    </div>

    <!-- Step 1: Phone input -->
    <div v-if="step === 'phone'" class="sb-card sb-mb-4">
      <p class="sb-subtitle sb-mb-2">Введите номер телефона, чтобы найти свои записи</p>
      <div class="sb-flex sb-gap-2">
        <div class="sb-field-wrap" style="flex: 1;">
          <input
            :value="phone"
            type="tel"
            class="sb-input"
            :class="{ 'sb-input-success': phoneValid }"
            placeholder="+7 (___) ___-__-__"
            @input="onPhoneInput"
            @keyup.enter="requestCode"
          />
          <svg v-if="phoneValid" class="sb-field-check" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
        </div>
        <button class="sb-btn sb-btn-primary" @click="requestCode" :disabled="otpSending || !phoneValid">
          {{ otpSending ? '...' : 'Получить код' }}
        </button>
      </div>
      <p v-if="otpError" class="sb-error-text sb-mt-2">{{ otpError }}</p>
      <p v-if="error" class="sb-error-text sb-mt-2">{{ error }}</p>
      <p style="font-size: 12px; color: var(--sb-text-muted); margin-top: 8px;">
        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: inline; vertical-align: -1px; margin-right: 4px;">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
        Мы отправим SMS-код для подтверждения
      </p>
    </div>

    <!-- Step 2: OTP code input -->
    <div v-else-if="step === 'code'" class="sb-card sb-mb-4">
      <p style="font-size: 15px; font-weight: 600; color: var(--sb-text); margin-bottom: 4px;">Введите код подтверждения</p>
      <p class="sb-subtitle sb-mb-4">Код отправлен на {{ phone }}</p>

      <div v-if="devCode" style="padding: 8px 12px; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: var(--sb-radius); margin-bottom: 12px; font-size: 13px; color: var(--sb-warning);">
        DEV: код — <strong>{{ devCode }}</strong>
      </div>

      <div class="sb-flex sb-gap-2">
        <input
          v-model="otpCode"
          type="text"
          inputmode="numeric"
          maxlength="4"
          class="sb-input"
          :class="{ 'sb-input-success': otpCode.length === 4 }"
          placeholder="0000"
          style="text-align: center; font-size: 24px; letter-spacing: 8px; font-weight: 700; max-width: 180px;"
          @keyup.enter="verifyCode"
        />
        <button class="sb-btn sb-btn-primary" @click="verifyCode" :disabled="otpSending || otpCode.length !== 4">
          {{ otpSending ? '...' : 'Подтвердить' }}
        </button>
      </div>
      <p v-if="otpError" class="sb-error-text sb-mt-2">{{ otpError }}</p>
      <div class="sb-flex sb-items-center sb-gap-3 sb-mt-4">
        <button class="sb-btn sb-btn-ghost" style="padding: 4px 8px; font-size: 13px;" @click="requestCode" :disabled="otpSending">
          Отправить повторно
        </button>
        <button class="sb-btn sb-btn-ghost" style="padding: 4px 8px; font-size: 13px;" @click="step = 'phone'">
          Изменить номер
        </button>
      </div>
    </div>

    <!-- Step 3: Results -->
    <template v-else-if="step === 'results'">
      <!-- Verified phone bar -->
      <div class="sb-card sb-mb-4" style="padding: 12px 16px;">
        <div class="sb-flex sb-items-center sb-justify-between">
          <div class="sb-flex sb-items-center sb-gap-2">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--sb-success);">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <span style="font-size: 14px; color: var(--sb-text);">{{ phone }}</span>
            <span class="sb-badge sb-badge-success" style="font-size: 11px;">Подтверждён</span>
          </div>
          <button class="sb-btn sb-btn-ghost" style="padding: 4px 8px; font-size: 12px;" @click="resetPhone">
            Сменить
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="sb-empty">
        <div class="sb-skeleton" style="width: 40px; height: 40px; border-radius: 50%; margin: 0 auto 16px;"></div>
        <p class="sb-empty-text">Загрузка записей...</p>
      </div>

      <!-- Error -->
      <div v-else-if="error" class="sb-empty">
        <p class="sb-empty-title">Ошибка</p>
        <p class="sb-empty-text">{{ error }}</p>
      </div>

      <!-- No bookings -->
      <div v-else-if="searched && bookings.length === 0" class="sb-empty">
        <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <p class="sb-empty-title">Записей не найдено</p>
        <p class="sb-empty-text">По этому номеру телефона записей пока нет</p>
      </div>

      <!-- Bookings list -->
      <div v-else-if="bookings.length > 0" class="sb-flex sb-flex-col sb-gap-3">
        <div
          v-for="booking in bookings"
          :key="booking.id"
          class="sb-card"
          :style="isPast(booking.end_time) && booking.status !== 'completed' && booking.status !== 'cancelled' ? 'opacity: 0.6' : ''"
        >
          <div class="sb-flex sb-items-center sb-justify-between sb-mb-2">
            <span style="font-size: 15px; font-weight: 600; color: var(--sb-text);">{{ booking.service?.name || 'Услуга' }}</span>
            <span :class="['sb-badge', statusBadge[booking.status] || 'sb-badge-info']">{{ statusLabels[booking.status] || booking.status }}</span>
          </div>

          <div style="font-size: 14px; color: var(--sb-text); margin-bottom: 4px;">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: inline; vertical-align: -2px; margin-right: 4px; color: var(--sb-text-muted);">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            {{ formatDate(booking.start_time) }}
          </div>

          <div style="font-size: 14px; color: var(--sb-text); margin-bottom: 8px;">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: inline; vertical-align: -2px; margin-right: 4px; color: var(--sb-text-muted);">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ formatTimeRange(booking.start_time, booking.end_time) }}
          </div>

          <div v-if="booking.master" class="sb-subtitle">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: inline; vertical-align: -2px; margin-right: 4px;">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            {{ booking.master.name }}
          </div>

          <div v-if="booking.notes" class="sb-subtitle sb-mt-2" style="font-style: italic;">{{ booking.notes }}</div>

          <!-- Cancel button -->
          <div v-if="canCancel(booking)" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--sb-border);">
            <button
              class="sb-btn sb-btn-ghost"
              style="color: var(--sb-danger); border: 1px solid var(--sb-danger); padding: 6px 14px; font-size: 13px;"
              @click="openCancelConfirm(booking)"
            >
              Отменить запись
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- Cancel confirm modal -->
    <div v-if="cancelTarget" style="position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 16px;">
      <div style="position: absolute; inset: 0; background: rgba(0,0,0,0.5);" @click="cancelTarget = null" />
      <div style="position: relative; background: var(--sb-bg); border-radius: var(--sb-radius-lg); padding: 24px; max-width: 360px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <p style="font-size: 17px; font-weight: 700; color: var(--sb-text); margin-bottom: 8px;">Отменить запись?</p>
        <p style="font-size: 14px; color: var(--sb-text-muted); margin-bottom: 20px;">
          {{ cancelTarget.service?.name }} — {{ formatDate(cancelTarget.start_time) }}, {{ formatTime(cancelTarget.start_time) }}
        </p>
        <p v-if="cancelError" style="font-size: 13px; color: var(--sb-danger); margin-bottom: 12px;">{{ cancelError }}</p>
        <div class="sb-flex sb-gap-2">
          <button class="sb-btn sb-btn-ghost" style="flex: 1;" @click="cancelTarget = null">Назад</button>
          <button
            class="sb-btn"
            style="flex: 1; background: var(--sb-danger); color: #fff;"
            :disabled="cancelling"
            @click="doCancel"
          >
            {{ cancelling ? 'Отмена...' : 'Да, отменить' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
