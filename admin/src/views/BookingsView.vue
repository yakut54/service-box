<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed, watch } from 'vue'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
import { RouterLink, useRouter } from 'vue-router'
import { useBookingsStore } from '@/stores/bookings'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'
import CustomSelect from '@/components/CustomSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import DatePicker from '@/components/DatePicker.vue'
import BookingCreateModal from '@/components/modals/BookingCreateModal.vue'
import UiSpinner from '@/shared/ui/UiSpinner.vue'
import UiEmptyState from '@/shared/ui/UiEmptyState.vue'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import { BOOKING_STATUS_LABELS } from '@/shared/lib/labels'
import type { Booking, Product } from '@/types'

const bookingsStore = useBookingsStore()
const authStore = useAuthStore()
const router = useRouter()

const shopTz = computed(() => {
  const tz = authStore.shop?.timezone
  if (!tz) return 'Europe/Moscow'
  try {
    Intl.DateTimeFormat(undefined, { timeZone: tz })
    return tz
  } catch {
    return 'Europe/Moscow'
  }
})

// Извлекает компоненты даты/времени в timezone магазина (не браузера)
function shopParts(dateStr: string) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: shopTz.value,
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(new Date(dateStr))
  const get = (t: string) => parseInt(parts.find(p => p.type === t)?.value ?? '0')
  return { year: get('year'), month: get('month') - 1, day: get('day'), hour: get('hour') % 24, minute: get('minute') }
}

// ── Filters ─────────────────────────────────────────────────
const filterStatus = ref('')
const filterMaster = ref('')
const filterSearch = ref('')
let   searchDebounce: ReturnType<typeof setTimeout> | null = null

function onSearchInput() {
  if (searchDebounce) clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => applyFilters(), 400)
}
const viewMode = ref<'list' | 'calendar'>((localStorage.getItem('bookings_viewMode') as 'list' | 'calendar') || 'list')

// ── Unified date anchor ──────────────────────────────────────
// Single source of truth for "which date/week we're viewing"
const anchorDateStr = ref(toYMD(new Date()))

function getAnchorDate(): Date {
  return anchorDateStr.value ? new Date(anchorDateStr.value + 'T12:00:00') : new Date()
}

const isAnchorToday = computed(() => anchorDateStr.value === toYMD(new Date()))

// Mobile detection (< 640px = Tailwind sm)
const windowWidth = ref(window.innerWidth)
const isMobile = computed(() => windowWidth.value < 640)
function _onResize() { windowWidth.value = window.innerWidth }
onMounted(() => window.addEventListener('resize', _onResize))
onUnmounted(() => window.removeEventListener('resize', _onResize))

function navigate(delta: number) {
  const d = getAnchorDate()
  // Desktop calendar → week step; everything else → day step
  const step = (viewMode.value === 'calendar' && !isMobile.value) ? 7 : 1
  d.setDate(d.getDate() + delta * step)
  anchorDateStr.value = toYMD(d)
  applyFilters()
}

function goToday() {
  anchorDateStr.value = toYMD(new Date())
  applyFilters()
}

const navLabel = computed(() => {
  if (viewMode.value === 'list') {
    return getAnchorDate().toLocaleDateString('ru-RU', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' })
  }
  if (isMobile.value) {
    return getAnchorDate().toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })
  }
  return formatMonthHeader()
})

watch(viewMode, (v) => {
  localStorage.setItem('bookings_viewMode', v)
  applyFilters()
})

const bookingStatusOptions = [
  { value: '', label: 'Все статусы' },
  { value: 'pending', label: 'Ожидают' },
  { value: 'confirmed', label: 'Подтверждены' },
  { value: 'completed', label: 'Завершены' },
  { value: 'cancelled', label: 'Отменены' },
  { value: 'no_show', label: 'Неявки' },
]

const masterOptions = computed(() => [
  { value: '', label: 'Все мастера' },
  ...bookingsStore.masters.map(m => ({ value: m.id, label: m.name }))
])

// ── Calendar state ──────────────────────────────────────────
const weekDays = computed(() => {
  const anchor = getAnchorDate()
  const monday = new Date(anchor)
  monday.setDate(anchor.getDate() - ((anchor.getDay() + 6) % 7))
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(monday)
    d.setDate(monday.getDate() + i)
    return d
  })
})

const CAL_START_H = 8
const CAL_END_H   = 23
const HOUR_H      = 56

const calendarHours = Array.from({ length: CAL_END_H - CAL_START_H + 1 }, (_, i) => i + CAL_START_H)

function isToday(date: Date) {
  const t = new Date()
  return date.getFullYear() === t.getFullYear() && date.getMonth() === t.getMonth() && date.getDate() === t.getDate()
}

function isWeekend(date: Date) { return date.getDay() === 0 || date.getDay() === 6 }
function formatWeekDay(date: Date) { return date.toLocaleDateString('ru-RU', { weekday: 'short' }) }
function formatDayNum(date: Date) { return date.getDate() }

const RU_SHORT_MONTHS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек']

function formatMonthHeader() {
  const first = weekDays.value[0]
  const last  = weekDays.value[6]
  const fmt = (d: Date) => `${d.getDate()} ${RU_SHORT_MONTHS[d.getMonth()]}`
  return `${fmt(first)} – ${fmt(last)} ${last.getFullYear()}`
}

function bookingsForDay(date: Date) {
  const y = date.getFullYear(), m = date.getMonth(), d = date.getDate()
  return bookingsStore.bookings.filter(b => {
    const p = shopParts(b.start_time)
    return p.year === y && p.month === m && p.day === d
  })
}

function bookingStyle(b: Booking) {
  const s = shopParts(b.start_time)
  const startMin = s.hour * 60 + s.minute
  let endMin = startMin + 60
  if (b.end_time) {
    const e = shopParts(b.end_time)
    endMin = e.hour * 60 + e.minute
  }
  const topMin    = Math.max(startMin - CAL_START_H * 60, 0)
  const heightMin = Math.max(endMin - startMin, 20)
  return {
    top:    (topMin    * HOUR_H / 60) + 'px',
    height: Math.max(heightMin * HOUR_H / 60, 22) + 'px',
  }
}

function calBookingBg(status: string) {
  const map: Record<string, string> = {
    pending:   'bg-amber-400 dark:bg-amber-500',
    confirmed: 'bg-primary-500 dark:bg-primary-400',
    completed: 'bg-emerald-500 dark:bg-emerald-400',
    cancelled: 'bg-gray-400 dark:bg-gray-500',
    no_show:   'bg-orange-400 dark:bg-orange-500',
  }
  return map[status] || 'bg-gray-400'
}

// If a booking is still pending/confirmed but its time has passed — show it as no_show visually
function effectiveStatus(b: Booking): string {
  if (['pending', 'confirmed'].includes(b.status) && new Date(b.start_time) < new Date()) {
    return 'no_show'
  }
  return b.status
}

// ── Calendar date picker (programmatic open) ─────────────────────────────────
const calPickerRef = ref<{ openAt: (el: HTMLElement) => void } | null>(null)
const navLabelBtnEl = ref<HTMLButtonElement | null>(null)

function openCalPicker() {
  if (navLabelBtnEl.value) calPickerRef.value?.openAt(navLabelBtnEl.value)
}

const nowTop = computed(() => {
  if (!weekDays.value.some(d => isToday(d))) return null
  const p = shopParts(new Date().toISOString())
  const min    = p.hour * 60 + p.minute
  const topMin = min - CAL_START_H * 60
  if (topMin < 0 || topMin > (CAL_END_H - CAL_START_H) * 60) return null
  return (topMin * HOUR_H / 60) + 'px'
})

// ── Create modal ────────────────────────────────────────────
const showModal    = ref(false)
const prefillDate  = ref<string | undefined>(undefined)
const services     = ref<Product[]>([])

function shopToday(): string {
  return new Date().toLocaleDateString('sv', { timeZone: shopTz.value })
}

function openModal(date?: string) {
  const today = shopToday()
  prefillDate.value = date ?? (anchorDateStr.value >= today ? anchorDateStr.value : undefined)
  showModal.value = true
}

// ── Delete booking ───────────────────────────────────────────
const deleteConfirm = ref<string | null>(null)
const deleting = ref(false)

async function handleDelete(id: string) {
  deleting.value = true
  await bookingsStore.deleteBooking(id)
  deleteConfirm.value = null
  deleting.value = false
}

// ── Quick status change ──────────────────────────────────────
const updatingStatus = ref(false)

async function changeStatus(id: string, status: string) {
  updatingStatus.value = true
  try { await bookingsStore.updateStatus(id, status) } catch { /* ignore */ }
  updatingStatus.value = false
}

// ── Formatting helpers ──────────────────────────────────────
function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: shopTz.value })
}
function formatTime(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: shopTz.value })
}
function formatTimeRange(start: string, end: string) {
  return `${formatTime(start)} – ${formatTime(end)}`
}

// ── Helpers ──────────────────────────────────────────────────
function toYMD(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// ── Data loading ────────────────────────────────────────────
function buildParams() {
  const params: Record<string, string> = {}
  if (filterStatus.value) params.status = filterStatus.value
  if (filterMaster.value) params.master_id = filterMaster.value
  if (filterSearch.value) {
    params.search = filterSearch.value
  } else if (viewMode.value === 'list') {
    params.date = anchorDateStr.value
  } else {
    params.date_from = toYMD(weekDays.value[0])
    params.date_to   = toYMD(weekDays.value[6])
  }
  return params
}

async function applyFilters() {
  await bookingsStore.fetchBookings(buildParams())
}

useAutoRefresh(() => bookingsStore.fetchBookings(buildParams(), { silent: true }))

onMounted(async () => {
  applyFilters()
  bookingsStore.fetchMasters()
  try {
    const resp = await api.getProducts({ type: 'service' })
    services.value = resp.data
  } catch { /* ignore */ }
})
</script>

<template>
  <div class="flex flex-col flex-1 min-h-0">
    <!-- Header -->
    <PageHeader
      class="mb-6"
      title="Записи"
      :subtitle="`${bookingsStore.bookings.length} ${plural(bookingsStore.bookings.length, 'запись', 'записи', 'записей')}`"
    >
      <div class="flex bg-gray-100 dark:bg-gray-700 rounded-lg p-1">
        <button :class="['px-3 py-1.5 text-sm font-medium rounded-md transition-colors', viewMode === 'list' ? 'bg-white dark:bg-gray-600 shadow-sm text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200']" @click="viewMode = 'list'">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <button :class="['px-3 py-1.5 text-sm font-medium rounded-md transition-colors', viewMode === 'calendar' ? 'bg-white dark:bg-gray-600 shadow-sm text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200']" @click="viewMode = 'calendar'">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </button>
      </div>
      <button class="btn-primary shrink-0" @click="openModal()">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        <span class="hidden sm:inline">Новая запись</span>
      </button>
    </PageHeader>

    <!-- Filters -->
    <div class="card mb-6">
      <div class="flex flex-wrap items-center gap-2">

        <!-- Nav + date: always on one line, full width on mobile -->
        <div class="flex items-center gap-2 w-full sm:w-auto">
          <!-- ← Today → -->
          <div class="flex items-center rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
            <button @click="navigate(-1)" class="px-2.5 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors text-gray-500 dark:text-gray-400 border-r border-gray-200 dark:border-gray-700" :title="viewMode === 'list' ? 'Предыдущий день' : 'Предыдущая неделя'">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button @click="goToday" :class="['px-3 py-1.5 text-sm font-medium transition-colors', isAnchorToday ? 'bg-primary-600 text-white' : 'hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-300']">Сегодня</button>
            <button @click="navigate(1)" class="px-2.5 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors text-gray-500 dark:text-gray-400 border-l border-gray-200 dark:border-gray-700" :title="viewMode === 'list' ? 'Следующий день' : 'Следующая неделя'">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
          </div>
          <!-- List: DatePicker fills remaining space in the row -->
          <DatePicker v-if="viewMode === 'list'" v-model="anchorDateStr" @change="applyFilters" placeholder="Дата" class="flex-1 sm:w-44 sm:flex-none" />
          <!-- Calendar: clickable label → opens date picker -->
          <template v-else>
            <button
              ref="navLabelBtnEl"
              @click="openCalPicker"
              class="flex items-center gap-1.5 text-sm font-semibold text-gray-900 dark:text-white whitespace-nowrap hover:text-primary-600 dark:hover:text-primary-400 transition-colors px-1.5 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
            >
              <span class="capitalize">{{ navLabel }}</span>
              <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
              </svg>
            </button>
            <DatePicker ref="calPickerRef" v-model="anchorDateStr" @change="applyFilters" class="hidden" />
          </template>
        </div>

        <!-- Spacer (desktop) -->
        <div class="flex-1 min-w-0 hidden sm:block"></div>

        <!-- Status -->
        <CustomSelect v-model="filterStatus" @change="applyFilters" :options="bookingStatusOptions" class="w-full sm:w-44" />
        <!-- Master -->
        <CustomSelect v-model="filterMaster" @change="applyFilters" :options="masterOptions" class="w-full sm:w-48" searchable>
          <template #footer="{ close }">
            <button type="button" @click="close(); router.push('/masters?create=1')" class="flex items-center gap-2 px-4 py-2.5 text-sm text-primary-600 dark:text-primary-400 hover:bg-gray-50 dark:hover:bg-gray-700 w-full">
              <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
              Добавить мастера
            </button>
          </template>
        </CustomSelect>
        <!-- Search -->
        <div class="relative w-full sm:w-56">
          <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
          </svg>
          <input
            v-model="filterSearch"
            type="text"
            placeholder="Клиент или телефон"
            class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
            @input="onSearchInput"
          />
        </div>

        <!-- Reset -->
        <button v-if="filterStatus || filterMaster || filterSearch" @click="filterStatus = ''; filterMaster = ''; filterSearch = ''; applyFilters()" class="btn-ghost btn-sm text-gray-400 whitespace-nowrap shrink-0">Сбросить</button>

      </div>
    </div>

    <!-- Loading -->
    <div v-if="bookingsStore.loading" class="card py-12 flex justify-center">
      <UiSpinner />
    </div>

    <!-- Empty (list mode only — calendar shows an empty grid) -->
    <UiEmptyState
      v-else-if="bookingsStore.bookings.length === 0 && viewMode === 'list'"
      title="Нет записей"
      description="Записи появятся, когда клиенты начнут бронировать"
    >
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      </template>
      <button class="btn-primary" @click="openModal()">Создать запись</button>
    </UiEmptyState>

    <!-- ══════════ LIST VIEW ══════════ -->
    <div v-else-if="viewMode === 'list'">
      <!-- Desktop table -->
      <div class="card overflow-hidden p-0 hidden sm:block">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Услуга</th><th>Клиент</th><th>Мастер</th><th>Дата / Время</th><th>Статус</th><th>Действия</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in bookingsStore.bookings" :key="b.id">
                <td>
                  <RouterLink :to="`/bookings/${b.id}`" class="font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">{{ b.service?.name || '—' }}</RouterLink>
                </td>
                <td>
                  <RouterLink v-if="b.customer_id" :to="`/customers/${b.customer_id}`" class="font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">{{ b.customer_name }}</RouterLink>
                  <div v-else class="font-medium text-gray-900 dark:text-gray-100">{{ b.customer_name }}</div>
                  <div class="text-sm text-gray-500 dark:text-gray-400">{{ b.customer_phone }}</div>
                </td>
                <td>
                  <RouterLink v-if="b.master" :to="`/masters/${b.master.id}`" class="text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400">{{ b.master.name }}</RouterLink>
                  <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                </td>
                <td>
                  <div class="text-sm dark:text-gray-200">{{ formatDate(b.start_time) }}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">{{ formatTimeRange(b.start_time, b.end_time) }}</div>
                </td>
                <td><span :class="`badge-${effectiveStatus(b)}`">{{ BOOKING_STATUS_LABELS[effectiveStatus(b)] || effectiveStatus(b) }}</span></td>
                <td>
                  <div class="flex items-center gap-1">
                    <button v-if="b.status === 'pending'" @click="changeStatus(b.id, 'confirmed')" :disabled="updatingStatus" class="btn-ghost btn-sm text-blue-600 hover:text-blue-700" title="Подтвердить">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </button>
                    <button v-if="b.status === 'confirmed'" @click="changeStatus(b.id, 'completed')" :disabled="updatingStatus" class="btn-ghost btn-sm text-green-600 hover:text-green-700" title="Завершить">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </button>
                    <button v-if="b.status !== 'cancelled' && b.status !== 'completed'" @click="changeStatus(b.id, 'cancelled')" :disabled="updatingStatus" class="btn-ghost btn-sm text-red-500 hover:text-red-700" title="Отменить">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                    <RouterLink :to="`/bookings/${b.id}`" class="btn-ghost btn-sm" title="Открыть">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </RouterLink>
                    <button @click="deleteConfirm = b.id" class="btn-ghost btn-sm text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20" title="Удалить">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile cards -->
      <div class="sm:hidden card overflow-hidden p-0">
        <RouterLink
          v-for="b in bookingsStore.bookings" :key="b.id" :to="`/bookings/${b.id}`"
          class="flex items-start justify-between gap-3 p-4 border-b border-gray-100 dark:border-gray-800 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
        >
          <div class="min-w-0 flex-1">
            <div class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">{{ b.service?.name || '—' }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ b.customer_name }} · {{ formatTimeRange(b.start_time, b.end_time) }}</div>
            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ formatDate(b.start_time) }}</div>
          </div>
          <div class="flex flex-col items-end gap-1.5 shrink-0">
            <span :class="`badge-${effectiveStatus(b)}`">{{ BOOKING_STATUS_LABELS[effectiveStatus(b)] || effectiveStatus(b) }}</span>
            <span v-if="b.master" class="text-xs text-gray-400 dark:text-gray-500 truncate max-w-[90px]">{{ b.master.name }}</span>
          </div>
        </RouterLink>
      </div>
    </div>

    <!-- ══════════ CALENDAR VIEW ══════════ -->
    <div v-else-if="viewMode === 'calendar'" class="flex flex-col flex-1 min-h-0">

      <!-- Mobile: day view -->
      <div class="sm:hidden card p-0 overflow-hidden">
        <!-- Day header -->
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-900/60 text-center">
          <div :class="['text-sm font-semibold capitalize', isAnchorToday ? 'text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-200']">
            {{ getAnchorDate().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' }) }}
          </div>
        </div>
        <!-- No bookings -->
        <div v-if="bookingsForDay(getAnchorDate()).length === 0" class="py-10 flex flex-col items-center gap-4">
          <span class="text-sm text-gray-400 dark:text-gray-500">Нет записей на этот день</span>
          <button class="btn-primary btn-sm" @click="openModal()">Создать запись</button>
        </div>
        <!-- Bookings by time -->
        <div v-else>
          <div
            v-for="b in bookingsForDay(getAnchorDate())" :key="b.id"
            class="flex items-start gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-800 last:border-0 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
            @click="router.push(`/bookings/${b.id}`)"
          >
            <div class="text-sm font-mono font-semibold text-gray-500 dark:text-gray-400 w-12 shrink-0 pt-0.5">{{ formatTime(b.start_time) }}</div>
            <div class="flex-1 min-w-0">
              <div class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">{{ b.service?.name || '—' }}</div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ b.customer_name }}</div>
            </div>
            <span :class="`badge-${effectiveStatus(b)} shrink-0`">{{ BOOKING_STATUS_LABELS[effectiveStatus(b)] || effectiveStatus(b) }}</span>
          </div>
        </div>
      </div>

      <!-- Desktop calendar -->
      <div class="card p-0 overflow-x-hidden hidden sm:flex flex-col flex-1 min-h-0">
        <!-- Calendar header -->
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800">
          <span class="font-semibold text-gray-700 dark:text-gray-300 capitalize text-sm">{{ navLabel }}</span>
        </div>

        <!-- Day header row -->
        <div class="grid grid-cols-[56px_repeat(7,1fr)] border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-900/60">
          <div></div>
          <div v-for="day in weekDays" :key="day.toISOString()" :class="['text-center py-2.5 border-l border-gray-100 dark:border-gray-800', isToday(day) ? 'bg-primary-50 dark:bg-primary-900/20' : '']">
            <div :class="['text-[11px] font-semibold uppercase tracking-wide', isToday(day) ? 'text-primary-500' : isWeekend(day) ? 'text-rose-400' : 'text-gray-400 dark:text-gray-500']">
              {{ formatWeekDay(day) }}
            </div>
            <div class="mt-0.5">
              <span :class="isToday(day) ? 'bg-primary-600 text-white rounded-full w-7 h-7 inline-flex items-center justify-center text-sm font-bold shadow-sm' : ['text-sm font-semibold', isWeekend(day) ? 'text-rose-500 dark:text-rose-400' : 'text-gray-800 dark:text-gray-200']">
                {{ formatDayNum(day) }}
              </span>
            </div>
          </div>
        </div>

        <!-- Scrollable time grid -->
        <div class="overflow-y-auto overflow-x-hidden flex-1">
          <div class="grid grid-cols-[56px_repeat(7,1fr)]" :style="`height: ${(CAL_END_H - CAL_START_H + 1) * HOUR_H}px`">
            <!-- Time labels -->
            <div class="relative bg-gray-50 dark:bg-gray-900/60 border-r border-gray-100 dark:border-gray-800">
              <div v-for="h in calendarHours" :key="h" class="absolute w-full flex items-start justify-end pr-2.5" :style="`top: ${(h - CAL_START_H) * HOUR_H}px; height: ${HOUR_H}px`">
                <span class="text-[11px] text-gray-400 dark:text-gray-600 leading-none mt-1">{{ String(h).padStart(2, '0') }}:00</span>
              </div>
            </div>
            <!-- Day columns -->
            <div v-for="day in weekDays" :key="day.toISOString()" :class="['relative border-l border-gray-100 dark:border-gray-800', isToday(day) ? 'bg-primary-50/40 dark:bg-primary-900/10' : 'bg-white dark:bg-gray-900']" :style="`height: ${(CAL_END_H - CAL_START_H + 1) * HOUR_H}px`">
              <div v-for="h in calendarHours" :key="h" class="absolute w-full border-t border-gray-100 dark:border-gray-800" :style="`top: ${(h - CAL_START_H) * HOUR_H}px`" />
              <div v-for="h in calendarHours" :key="`hh-${h}`" class="absolute w-full border-t border-dashed border-gray-50 dark:border-gray-800/50" :style="`top: ${(h - CAL_START_H) * HOUR_H + HOUR_H / 2}px`" />
              <template v-if="isToday(day) && nowTop">
                <div class="absolute w-full z-10 pointer-events-none flex items-center" :style="`top: ${nowTop!}`">
                  <div class="w-2.5 h-2.5 rounded-full bg-red-500 shadow-sm -ml-1.5 flex-shrink-0"/>
                  <div class="flex-1 h-px bg-red-400"/>
                </div>
              </template>
              <div v-for="b in bookingsForDay(day)" :key="b.id" class="absolute left-1 right-1 rounded-lg px-2 py-1 cursor-pointer overflow-hidden transition-all duration-100 hover:opacity-90 hover:shadow-md" :class="calBookingBg(effectiveStatus(b))" :style="bookingStyle(b)" @click="router.push(`/bookings/${b.id}`)">
                <div class="text-[11px] font-bold text-white leading-tight truncate">{{ formatTime(b.start_time) }} · {{ b.service?.name || 'Запись' }}</div>
                <div class="text-[10px] text-white/80 truncate mt-0.5">{{ b.customer_name }}</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Legend -->
        <div class="flex items-center gap-4 px-5 py-2.5 border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-900/40">
          <span class="text-xs text-gray-400 dark:text-gray-500">Статусы:</span>
          <div v-for="(label, key) in { pending: 'Ожидает', confirmed: 'Подтверждена', completed: 'Завершена', cancelled: 'Отменена' }" :key="key" class="flex items-center gap-1.5">
            <div class="w-2.5 h-2.5 rounded-sm flex-shrink-0" :class="calBookingBg(key)"/>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ label }}</span>
          </div>
        </div>
      </div>
    </div>

    <BookingCreateModal v-model="showModal" :prefill-date="prefillDate" :services="services" @created="applyFilters" />

    <!-- Delete confirm -->
    <UiConfirmDialog
      :modelValue="!!deleteConfirm"
      @update:modelValue="!$event && (deleteConfirm = null)"
      title="Удалить запись?"
      :loading="deleting"
      @confirm="handleDelete(deleteConfirm!)"
    >
      Запись будет удалена безвозвратно.
    </UiConfirmDialog>
  </div>
</template>
