<script setup lang="ts">
import { ref } from 'vue'
import { useShopStore } from '@/stores/shop'
import SbPhoneAuth from '@/components/SbPhoneAuth.vue'

const emit = defineEmits<{ back: [] }>()

const shopStore = useShopStore()
const sbAuth    = ref<InstanceType<typeof SbPhoneAuth>>()

const bookings  = ref<any[]>([])
const loading   = ref(false)
const searched  = ref(false)
const error     = ref('')

const statusLabels: Record<string, string> = {
  pending:   'Ожидает',
  confirmed: 'Подтверждена',
  completed: 'Завершена',
  cancelled: 'Отменена',
  no_show:   'Неявка',
}

const statusBadge: Record<string, string> = {
  pending:   'sb-badge-warning',
  confirmed: 'sb-badge-info',
  completed: 'sb-badge-success',
  cancelled: 'sb-badge-danger',
  no_show:   'sb-badge-danger',
}

function shopTz() { return shopStore.shop?.timezone || 'Europe/Moscow' }

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', timeZone: shopTz() })
}
function formatTime(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: shopTz() })
}
function formatTimeRange(start: string, end: string) {
  return `${formatTime(start)} – ${formatTime(end)}`
}
function isPast(dateStr: string) {
  return new Date(dateStr) < new Date()
}

// ── Auth-gated data load ──────────────────────────────────────
let _phone = ''
let _token = ''

async function loadBookings(phone: string, token: string) {
  _phone = phone
  _token = token

  loading.value  = true
  error.value    = ''
  searched.value = true

  try {
    const resp = await shopStore.getApi().getBookingsByPhone(phone, token)
    bookings.value = resp.data
  } catch (e: any) {
    if (e.status === 401) {
      bookings.value = []
      searched.value = false
      sbAuth.value?.reset('Сессия истекла. Подтвердите телефон заново.')
    } else {
      error.value = e.message || 'Ошибка загрузки'
    }
  }
  loading.value = false
}

function handleLogout() {
  bookings.value = []
  searched.value = false
  error.value    = ''
  _phone = ''
  _token = ''
}

// ── Cancel booking ────────────────────────────────────────────
const cancelTarget = ref<any | null>(null)
const cancelling   = ref(false)
const cancelError  = ref('')

function canCancel(booking: any) {
  return ['pending', 'confirmed'].includes(booking.status) && !isPast(booking.start_time)
}

function openCancelConfirm(booking: any) {
  cancelTarget.value = booking
  cancelError.value  = ''
}

async function doCancel() {
  if (!cancelTarget.value) return
  cancelling.value  = true
  cancelError.value = ''
  try {
    await shopStore.getApi().cancelBooking(cancelTarget.value.id, _phone, _token)
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

    <SbPhoneAuth
      ref="sbAuth"
      hint="Введите номер телефона, чтобы найти свои записи"
      @authenticated="loadBookings"
      @logout="handleLogout"
    >
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
    </SbPhoneAuth>

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
