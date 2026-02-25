<script setup lang="ts">
import { onMounted, ref, computed, watch, reactive } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useBookingsStore } from '@/stores/bookings'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'
import CustomSelect from '@/components/CustomSelect.vue'
import DatePicker from '@/components/DatePicker.vue'
import { handlePhoneInput, isValidPhone } from '@/composables/usePhoneInput'
import UiSpinner from '@/shared/ui/UiSpinner.vue'
import UiEmptyState from '@/shared/ui/UiEmptyState.vue'
import UiModal from '@/shared/ui/UiModal.vue'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import { BOOKING_STATUS_LABELS } from '@/shared/lib/labels'

const bookingsStore = useBookingsStore()
const authStore = useAuthStore()
const router = useRouter()

const shopTz = computed(() => authStore.shop?.timezone || 'Europe/Moscow')

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
const filterDate = ref('')
const viewMode = ref<'list' | 'calendar'>('list')

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

const serviceOptions = computed(() => [
  { value: '', label: 'Выберите услугу' },
  ...services.value.map((s: any) => ({ value: s.id, label: s.name }))
])

const masterModalOptions = computed(() => [
  { value: '', label: 'Любой доступный' },
  ...bookingsStore.masters.map(m => ({ value: m.id, label: m.name }))
])

// ── Calendar state ──────────────────────────────────────────
const weekOffset = ref(0)

const weekDays = computed(() => {
  const today = new Date()
  const monday = new Date(today)
  monday.setDate(today.getDate() - ((today.getDay() + 6) % 7) + weekOffset.value * 7)
  const days: Date[] = []
  for (let i = 0; i < 7; i++) {
    const d = new Date(monday)
    d.setDate(monday.getDate() + i)
    days.push(d)
  }
  return days
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

function formatMonthHeader() {
  const first = weekDays.value[0]
  const last  = weekDays.value[6]
  if (first.getMonth() === last.getMonth()) {
    return first.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' })
  }
  return `${first.toLocaleDateString('ru-RU', { month: 'short' })} – ${last.toLocaleDateString('ru-RU', { month: 'short', year: 'numeric' })}`
}

function bookingsForDay(date: Date) {
  const y = date.getFullYear(), m = date.getMonth(), d = date.getDate()
  return bookingsStore.bookings.filter(b => {
    const p = shopParts(b.start_time)
    return p.year === y && p.month === m && p.day === d
  })
}

function bookingStyle(b: any) {
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

const nowTop = computed(() => {
  if (weekOffset.value !== 0) return null
  const p = shopParts(new Date().toISOString())
  const min    = p.hour * 60 + p.minute
  const topMin = min - CAL_START_H * 60
  if (topMin < 0 || topMin > (CAL_END_H - CAL_START_H) * 60) return null
  return (topMin * HOUR_H / 60) + 'px'
})

// ── Create modal ────────────────────────────────────────────
const showModal = ref(false)
const services = ref<any[]>([])
const modalForm = ref({
  service_id: '', master_id: '', date: '', slot: '',
  customer_name: '', customer_phone: '', customer_email: '', notes: '',
})
const availableSlots = ref<any[]>([])
const loadingSlots = ref(false)
const creating = ref(false)
const modalError = ref('')

// ── Booking form live validation ─────────────────────────────
const bookingErrors = reactive({ customer_name: '', customer_phone: '', customer_email: '' })

function validateBookingName(v: string) { bookingErrors.customer_name = v.trim() ? '' : 'Введите имя клиента' }
function validateBookingPhone(v: string) {
  if (!v) { bookingErrors.customer_phone = 'Телефон обязателен'; return }
  bookingErrors.customer_phone = isValidPhone(v) ? '' : 'Введите полный номер: +7 (XXX) XXX-XX-XX'
}
function validateBookingEmail(v: string) {
  if (!v) { bookingErrors.customer_email = ''; return }
  bookingErrors.customer_email = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? '' : 'Некорректный email'
}
function resetBookingErrors() {
  bookingErrors.customer_name = ''
  bookingErrors.customer_phone = ''
  bookingErrors.customer_email = ''
}

const isBookingFormValid = computed(() =>
  !bookingErrors.customer_name && !bookingErrors.customer_phone && !bookingErrors.customer_email &&
  modalForm.value.customer_name.trim() && modalForm.value.customer_phone &&
  isValidPhone(modalForm.value.customer_phone) &&
  modalForm.value.service_id && modalForm.value.slot
)

function openModal() {
  modalError.value = ''
  modalForm.value = { service_id: '', master_id: '', date: '', slot: '', customer_name: '', customer_phone: '', customer_email: '', notes: '' }
  availableSlots.value = []
  resetBookingErrors()
  showModal.value = true
}

watch([() => modalForm.value.service_id, () => modalForm.value.date, () => modalForm.value.master_id], async () => {
  if (!modalForm.value.service_id || !modalForm.value.date) { availableSlots.value = []; return }
  loadingSlots.value = true
  try {
    const params: Record<string, string> = { service_id: modalForm.value.service_id, date: modalForm.value.date }
    if (modalForm.value.master_id) params.master_id = modalForm.value.master_id
    const resp = await api.getAvailableSlots(params)
    availableSlots.value = resp.slots || []
  } catch {
    availableSlots.value = []
  }
  loadingSlots.value = false
  modalForm.value.slot = ''
})

async function submitBooking() {
  validateBookingName(modalForm.value.customer_name)
  validateBookingPhone(modalForm.value.customer_phone)
  validateBookingEmail(modalForm.value.customer_email)
  if (!isBookingFormValid.value) { modalError.value = 'Заполните все обязательные поля корректно'; return }
  creating.value = true
  modalError.value = ''
  try {
    await bookingsStore.createBooking({
      service_id: modalForm.value.service_id,
      start_time: modalForm.value.slot,
      master_id: modalForm.value.master_id || undefined,
      customer: {
        name: modalForm.value.customer_name,
        phone: modalForm.value.customer_phone,
        email: modalForm.value.customer_email || undefined,
      },
      notes: modalForm.value.notes || undefined,
    })
    showModal.value = false
    applyFilters()
  } catch (e: any) {
    modalError.value = e.message || 'Не удалось создать запись'
  }
  creating.value = false
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

// ── Date navigation helpers ──────────────────────────────────
function toYMD(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function shiftDay(delta: number) {
  const base = filterDate.value ? new Date(filterDate.value + 'T12:00:00') : new Date()
  base.setDate(base.getDate() + delta)
  filterDate.value = toYMD(base)
  applyFilters()
}

function setTodayFilter() {
  filterDate.value = toYMD(new Date())
  applyFilters()
}

const isFilterToday = computed(() => filterDate.value === toYMD(new Date()))

// ── Data loading ────────────────────────────────────────────
async function applyFilters() {
  const params: Record<string, string> = {}
  if (filterStatus.value) params.status = filterStatus.value
  if (filterMaster.value) params.master_id = filterMaster.value
  if (filterDate.value) params.date = filterDate.value
  await bookingsStore.fetchBookings(params)
}

onMounted(async () => {
  bookingsStore.fetchBookings()
  bookingsStore.fetchMasters()
  try {
    const resp = await api.getProducts({ type: 'service' })
    services.value = resp.data
  } catch { /* ignore */ }
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between gap-3 mb-6">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Записи</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">{{ bookingsStore.bookings.length }} {{ plural(bookingsStore.bookings.length, 'запись', 'записи', 'записей') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <div class="hidden sm:flex bg-gray-100 dark:bg-gray-700 rounded-lg p-1">
          <button :class="['px-3 py-1.5 text-sm font-medium rounded-md transition-colors', viewMode === 'list' ? 'bg-white dark:bg-gray-600 shadow-sm text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200']" @click="viewMode = 'list'">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
          <button :class="['px-3 py-1.5 text-sm font-medium rounded-md transition-colors', viewMode === 'calendar' ? 'bg-white dark:bg-gray-600 shadow-sm text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200']" @click="viewMode = 'calendar'">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          </button>
        </div>
        <button class="btn-primary shrink-0" @click="openModal">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          <span class="hidden sm:inline">Новая запись</span>
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="card mb-6 space-y-3">
      <div class="flex flex-col sm:flex-row gap-3">
        <CustomSelect v-model="filterStatus" @change="applyFilters" :options="bookingStatusOptions" class="w-full sm:w-52" />
        <CustomSelect v-model="filterMaster" @change="applyFilters" :options="masterOptions" class="w-full sm:w-64" searchable>
          <template #footer="{ close }">
            <button
              type="button"
              @click="close(); router.push('/masters?create=1')"
              class="flex items-center gap-2 px-4 py-2.5 text-sm text-primary-600 dark:text-primary-400 hover:bg-gray-50 dark:hover:bg-gray-700 w-full"
            >
              <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Добавить мастера
            </button>
          </template>
        </CustomSelect>
      </div>

      <!-- Date navigation: mobile=2 rows, desktop=1 row -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-2">
        <!-- Quick day buttons -->
        <div class="flex items-center gap-2 shrink-0">
          <button @click="shiftDay(-1)" class="btn-ghost btn-sm px-2.5" title="Предыдущий день">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <button
            @click="setTodayFilter"
            :class="['btn-sm px-4 text-sm font-medium transition-colors flex-1 sm:flex-none', isFilterToday ? 'btn-primary' : 'btn-secondary']"
          >Сегодня</button>
          <button @click="shiftDay(1)" class="btn-ghost btn-sm px-2.5" title="Следующий день">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </button>
        </div>
        <!-- Arbitrary date + reset -->
        <div class="flex items-center gap-2 flex-1">
          <DatePicker v-model="filterDate" @change="applyFilters" placeholder="Любая дата" class="flex-1 sm:w-44 sm:flex-none" />
          <button v-if="filterStatus || filterMaster || filterDate" @click="filterStatus = ''; filterMaster = ''; filterDate = ''; applyFilters()" class="btn-ghost btn-sm whitespace-nowrap text-gray-400 shrink-0">
            Сбросить
          </button>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="bookingsStore.loading" class="card py-12 flex justify-center">
      <UiSpinner />
    </div>

    <!-- Empty -->
    <UiEmptyState
      v-else-if="bookingsStore.bookings.length === 0"
      title="Нет записей"
      description="Записи появятся, когда клиенты начнут бронировать"
    >
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      </template>
      <button class="btn-primary" @click="openModal">Создать запись</button>
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
                <td><span :class="`badge-${b.status}`">{{ BOOKING_STATUS_LABELS[b.status] || b.status }}</span></td>
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
            <span :class="`badge-${b.status}`">{{ BOOKING_STATUS_LABELS[b.status] || b.status }}</span>
            <span v-if="b.master" class="text-xs text-gray-400 dark:text-gray-500 truncate max-w-[90px]">{{ b.master.name }}</span>
          </div>
        </RouterLink>
      </div>
    </div>

    <!-- ══════════ CALENDAR VIEW ══════════ -->
    <div v-else-if="viewMode === 'calendar'">

      <!-- Mobile fallback -->
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
          <span :class="`badge-${b.status} shrink-0`">{{ BOOKING_STATUS_LABELS[b.status] || b.status }}</span>
        </RouterLink>
      </div>

      <!-- Desktop calendar -->
      <div class="card p-0 overflow-hidden hidden sm:block">
        <!-- Nav bar -->
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 dark:border-gray-800">
          <button @click="weekOffset--" class="btn-ghost btn-sm p-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </button>
          <div class="flex items-center gap-3">
            <span class="font-semibold text-gray-900 dark:text-white capitalize">{{ formatMonthHeader() }}</span>
            <button v-if="weekOffset !== 0" @click="weekOffset = 0" class="text-xs text-primary-600 dark:text-primary-400 font-medium px-2.5 py-1 rounded-full bg-primary-50 dark:bg-primary-900/30 hover:bg-primary-100 dark:hover:bg-primary-900/50 transition-colors">Сегодня</button>
          </div>
          <button @click="weekOffset++" class="btn-ghost btn-sm p-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
          </button>
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
        <div class="overflow-y-auto" style="max-height: 560px">
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
              <div v-for="b in bookingsForDay(day)" :key="b.id" class="absolute left-1 right-1 rounded-lg px-2 py-1 cursor-pointer overflow-hidden transition-all duration-100 hover:opacity-90 hover:shadow-md" :class="calBookingBg(b.status)" :style="bookingStyle(b)" @click="router.push(`/bookings/${b.id}`)">
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

    <!-- ══════════ CREATE BOOKING MODAL ══════════ -->
    <UiModal v-model="showModal">
      <!-- Header -->
      <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Новая запись</h2>
        <button @click="showModal = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <!-- Body -->
      <div class="p-4 space-y-4">
        <div v-if="modalError" class="p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm dark:bg-red-900/20 dark:border-red-800 dark:text-red-400">{{ modalError }}</div>

        <div>
          <label class="label">Услуга <span class="text-red-500">*</span></label>
          <CustomSelect v-model="modalForm.service_id" :options="serviceOptions" searchable />
          <p v-if="services.length === 0" class="mt-1 text-xs text-red-500">
            Нет активных услуг. <RouterLink to="/products" class="underline">Добавьте услугу</RouterLink>
          </p>
        </div>

        <div>
          <label class="label">Мастер</label>
          <CustomSelect v-model="modalForm.master_id" :options="masterModalOptions" searchable />
        </div>

        <div>
          <label class="label">Дата <span class="text-red-500">*</span></label>
          <DatePicker v-model="modalForm.date" :min="new Date().toISOString().split('T')[0]" placeholder="Выберите дату" />
        </div>

        <div v-if="modalForm.service_id && modalForm.date">
          <label class="label">Время <span class="text-red-500">*</span></label>
          <div v-if="loadingSlots" class="text-sm text-gray-500 dark:text-gray-400">Загрузка слотов...</div>
          <div v-else-if="availableSlots.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Нет доступных слотов на эту дату</div>
          <div v-else class="grid grid-cols-4 gap-2">
            <button
              v-for="slot in availableSlots" :key="slot.time"
              :class="['px-3 py-2 rounded-lg border text-sm font-medium transition-colors',
                modalForm.slot === slot.datetime
                  ? 'border-primary-600 bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
                  : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-primary-300 dark:hover:border-primary-500']"
              @click="modalForm.slot = slot.datetime"
            >{{ slot.time }}</button>
          </div>
        </div>

        <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
          <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Клиент</div>
          <div class="space-y-3">
            <div>
              <label class="label">Имя <span class="text-red-500">*</span></label>
              <input v-model="modalForm.customer_name" @input="validateBookingName(modalForm.customer_name)" @blur="validateBookingName(modalForm.customer_name)" type="text" :class="['input', bookingErrors.customer_name ? 'input-error' : '']" placeholder="Иван Иванов" />
              <p v-if="bookingErrors.customer_name" class="mt-1 text-xs text-red-500">{{ bookingErrors.customer_name }}</p>
            </div>
            <div>
              <label class="label">Телефон <span class="text-red-500">*</span></label>
              <input :value="modalForm.customer_phone" @input="handlePhoneInput($event, (v) => { modalForm.customer_phone = v; validateBookingPhone(v) })" @blur="validateBookingPhone(modalForm.customer_phone)" type="tel" :class="['input', bookingErrors.customer_phone ? 'input-error' : modalForm.customer_phone && !bookingErrors.customer_phone ? 'input-success' : '']" placeholder="+7 (999) 123-45-67" />
              <p v-if="bookingErrors.customer_phone" class="mt-1 text-xs text-red-500">{{ bookingErrors.customer_phone }}</p>
            </div>
            <div>
              <label class="label">Email</label>
              <input v-model="modalForm.customer_email" @input="validateBookingEmail(modalForm.customer_email)" @blur="validateBookingEmail(modalForm.customer_email)" type="email" :class="['input', bookingErrors.customer_email ? 'input-error' : modalForm.customer_email && !bookingErrors.customer_email ? 'input-success' : '']" placeholder="email@example.com" />
              <p v-if="bookingErrors.customer_email" class="mt-1 text-xs text-red-500">{{ bookingErrors.customer_email }}</p>
            </div>
            <div>
              <label class="label">Примечание</label>
              <textarea v-model="modalForm.notes" class="input" rows="2" placeholder="Дополнительная информация..."></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex gap-3 p-4 border-t border-gray-100 dark:border-gray-700 sticky bottom-0 bg-white dark:bg-gray-800 z-10">
        <button @click="showModal = false" class="btn-secondary flex-1">Отмена</button>
        <button @click="submitBooking" :disabled="creating || !isBookingFormValid" class="btn-primary flex-1">
          <template v-if="creating">
            <div class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></div>
            Создание...
          </template>
          <template v-else>Создать запись</template>
        </button>
      </div>
    </UiModal>

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
