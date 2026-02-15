<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import { useBookingsStore } from '@/stores/bookings'
import { api } from '@/lib/api'

const bookingsStore = useBookingsStore()

// ── Filters ─────────────────────────────────────────────────
const filterStatus = ref('')
const filterMaster = ref('')
const filterDate = ref('')
const viewMode = ref<'list' | 'calendar'>('list')

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

const calendarHours = Array.from({ length: 12 }, (_, i) => i + 9) // 9–20

function isToday(date: Date) {
  const t = new Date()
  return date.getFullYear() === t.getFullYear() && date.getMonth() === t.getMonth() && date.getDate() === t.getDate()
}

function formatWeekDay(date: Date) {
  return date.toLocaleDateString('ru-RU', { weekday: 'short' })
}

function formatDayNum(date: Date) {
  return date.getDate()
}

function formatMonthHeader() {
  const first = weekDays.value[0]
  const last = weekDays.value[6]
  if (first.getMonth() === last.getMonth()) {
    return first.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' })
  }
  return `${first.toLocaleDateString('ru-RU', { month: 'short' })} – ${last.toLocaleDateString('ru-RU', { month: 'short', year: 'numeric' })}`
}

function dateStr(date: Date) {
  return date.toISOString().split('T')[0]
}

function bookingsForDayHour(date: Date, hour: number) {
  const ds = dateStr(date)
  return bookingsStore.bookings.filter(b => {
    const start = new Date(b.start_time)
    return start.toISOString().split('T')[0] === ds && start.getHours() === hour
  })
}

// ── Create modal ────────────────────────────────────────────
const showModal = ref(false)
const services = ref<any[]>([])
const modalForm = ref({
  service_id: '',
  master_id: '',
  date: '',
  slot: '',
  customer_name: '',
  customer_phone: '',
  customer_email: '',
  notes: '',
})
const availableSlots = ref<any[]>([])
const loadingSlots = ref(false)
const creating = ref(false)
const modalError = ref('')

async function openModal() {
  modalError.value = ''
  modalForm.value = { service_id: '', master_id: '', date: '', slot: '', customer_name: '', customer_phone: '', customer_email: '', notes: '' }
  availableSlots.value = []
  showModal.value = true

  if (services.value.length === 0) {
    try {
      const resp = await api.getProducts({ type: 'service' })
      services.value = resp.data
    } catch { /* ignore */ }
  }
}

watch([() => modalForm.value.service_id, () => modalForm.value.date, () => modalForm.value.master_id], async () => {
  if (!modalForm.value.service_id || !modalForm.value.date) {
    availableSlots.value = []
    return
  }
  loadingSlots.value = true
  try {
    const params: Record<string, string> = {
      service_id: modalForm.value.service_id,
      date: modalForm.value.date,
    }
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
  if (!modalForm.value.service_id || !modalForm.value.slot || !modalForm.value.customer_name || !modalForm.value.customer_phone) {
    modalError.value = 'Заполните все обязательные поля'
    return
  }
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

// ── Booking detail popup ────────────────────────────────────
const selectedBooking = ref<any>(null)
const updatingStatus = ref(false)

async function changeStatus(id: string, status: string) {
  updatingStatus.value = true
  try {
    await bookingsStore.updateStatus(id, status)
    if (selectedBooking.value?.id === id) {
      selectedBooking.value = bookingsStore.bookings.find(b => b.id === id) || null
    }
  } catch { /* ignore */ }
  updatingStatus.value = false
}

// ── Formatting helpers ──────────────────────────────────────
const statusLabels: Record<string, string> = {
  pending: 'Ожидает',
  confirmed: 'Подтверждена',
  completed: 'Завершена',
  cancelled: 'Отменена',
  no_show: 'Неявка',
}

const statusColors: Record<string, string> = {
  pending: 'bg-yellow-400',
  confirmed: 'bg-blue-400',
  completed: 'bg-gray-400',
  cancelled: 'bg-red-400',
  no_show: 'bg-orange-400',
}

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function formatTime(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function formatTimeRange(start: string, end: string) {
  return `${formatTime(start)} – ${formatTime(end)}`
}

// ── Data loading ────────────────────────────────────────────
async function applyFilters() {
  const params: Record<string, string> = {}
  if (filterStatus.value) params.status = filterStatus.value
  if (filterMaster.value) params.master_id = filterMaster.value
  if (filterDate.value) params.date = filterDate.value
  await bookingsStore.fetchBookings(params)
}

onMounted(() => {
  bookingsStore.fetchBookings()
  bookingsStore.fetchMasters()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Записи</h1>
        <p class="text-gray-500 mt-1">{{ bookingsStore.bookings.length }} записей</p>
      </div>
      <div class="flex items-center gap-3">
        <!-- View toggle -->
        <div class="flex bg-gray-100 rounded-lg p-1">
          <button
            :class="['px-3 py-1.5 text-sm font-medium rounded-md transition-colors', viewMode === 'list' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700']"
            @click="viewMode = 'list'"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
          <button
            :class="['px-3 py-1.5 text-sm font-medium rounded-md transition-colors', viewMode === 'calendar' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700']"
            @click="viewMode = 'calendar'"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          </button>
        </div>
        <button class="btn-primary" @click="openModal">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Новая запись
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="card mb-6">
      <div class="flex flex-col sm:flex-row gap-4">
        <select v-model="filterStatus" @change="applyFilters" class="input w-full sm:w-44">
          <option value="">Все статусы</option>
          <option value="pending">Ожидают</option>
          <option value="confirmed">Подтверждены</option>
          <option value="completed">Завершены</option>
          <option value="cancelled">Отменены</option>
          <option value="no_show">Неявки</option>
        </select>
        <select v-model="filterMaster" @change="applyFilters" class="input w-full sm:w-48">
          <option value="">Все мастера</option>
          <option v-for="m in bookingsStore.masters" :key="m.id" :value="m.id">{{ m.name }}</option>
        </select>
        <input v-model="filterDate" @change="applyFilters" type="date" class="input w-full sm:w-44" />
        <button v-if="filterStatus || filterMaster || filterDate" @click="filterStatus = ''; filterMaster = ''; filterDate = ''; applyFilters()" class="btn-ghost btn-sm whitespace-nowrap">
          Сбросить
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="bookingsStore.loading" class="card py-12 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <!-- Empty -->
    <div v-else-if="bookingsStore.bookings.length === 0" class="card py-12 text-center">
      <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
      </svg>
      <h3 class="text-lg font-medium text-gray-900 mb-2">Нет записей</h3>
      <p class="text-gray-500">Записи появятся, когда клиенты начнут бронировать</p>
      <button class="btn-primary mt-4" @click="openModal">Создать запись</button>
    </div>

    <!-- ══════════ LIST VIEW ══════════ -->
    <div v-else-if="viewMode === 'list'" class="card overflow-hidden p-0">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Услуга</th>
              <th>Клиент</th>
              <th>Мастер</th>
              <th>Дата / Время</th>
              <th>Статус</th>
              <th>Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in bookingsStore.bookings" :key="b.id">
              <td>
                <div class="font-medium text-gray-900">{{ b.service?.name || '—' }}</div>
              </td>
              <td>
                <div class="font-medium text-gray-900">{{ b.customer_name }}</div>
                <div class="text-sm text-gray-500">{{ b.customer_phone }}</div>
              </td>
              <td>
                <div class="text-gray-700">{{ b.master?.name || '—' }}</div>
              </td>
              <td>
                <div class="text-sm">{{ formatDate(b.start_time) }}</div>
                <div class="text-xs text-gray-500">{{ formatTimeRange(b.start_time, b.end_time) }}</div>
              </td>
              <td>
                <span :class="`badge-${b.status}`">{{ statusLabels[b.status] || b.status }}</span>
              </td>
              <td>
                <div class="flex items-center gap-1">
                  <button
                    v-if="b.status === 'pending'"
                    @click="changeStatus(b.id, 'confirmed')"
                    :disabled="updatingStatus"
                    class="btn-ghost btn-sm text-blue-600 hover:text-blue-700"
                    title="Подтвердить"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                  </button>
                  <button
                    v-if="b.status === 'confirmed'"
                    @click="changeStatus(b.id, 'completed')"
                    :disabled="updatingStatus"
                    class="btn-ghost btn-sm text-green-600 hover:text-green-700"
                    title="Завершить"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                  </button>
                  <button
                    v-if="b.status !== 'cancelled' && b.status !== 'completed'"
                    @click="changeStatus(b.id, 'cancelled')"
                    :disabled="updatingStatus"
                    class="btn-ghost btn-sm text-red-500 hover:text-red-700"
                    title="Отменить"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                  </button>
                  <button
                    @click="selectedBooking = b"
                    class="btn-ghost btn-sm"
                    title="Подробнее"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ══════════ CALENDAR VIEW ══════════ -->
    <div v-else class="card p-0 overflow-hidden">
      <!-- Calendar header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <button @click="weekOffset--" class="btn-ghost btn-sm">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <div class="flex items-center gap-3">
          <span class="font-semibold text-gray-900 capitalize">{{ formatMonthHeader() }}</span>
          <button v-if="weekOffset !== 0" @click="weekOffset = 0" class="text-xs text-primary-600 hover:text-primary-700 font-medium">Сегодня</button>
        </div>
        <button @click="weekOffset++" class="btn-ghost btn-sm">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>

      <!-- Calendar grid -->
      <div class="overflow-x-auto">
        <div class="min-w-[800px]">
          <!-- Day headers -->
          <div class="grid grid-cols-[60px_repeat(7,1fr)] border-b border-gray-100">
            <div></div>
            <div
              v-for="day in weekDays"
              :key="day.toISOString()"
              class="text-center py-2 border-l border-gray-100"
            >
              <div class="text-xs text-gray-500 uppercase">{{ formatWeekDay(day) }}</div>
              <div :class="['text-sm font-semibold', isToday(day) ? 'text-primary-600' : 'text-gray-900']">
                <span :class="isToday(day) ? 'bg-primary-600 text-white rounded-full w-7 h-7 inline-flex items-center justify-center' : ''">{{ formatDayNum(day) }}</span>
              </div>
            </div>
          </div>

          <!-- Time rows -->
          <div v-for="hour in calendarHours" :key="hour" class="grid grid-cols-[60px_repeat(7,1fr)] border-b border-gray-50 min-h-[60px]">
            <div class="text-xs text-gray-400 text-right pr-2 pt-1">{{ String(hour).padStart(2, '0') }}:00</div>
            <div
              v-for="day in weekDays"
              :key="day.toISOString() + hour"
              class="border-l border-gray-100 p-0.5 relative min-h-[60px]"
            >
              <div
                v-for="booking in bookingsForDayHour(day, hour)"
                :key="booking.id"
                :class="['rounded px-1.5 py-0.5 text-xs cursor-pointer mb-0.5 truncate text-white', statusColors[booking.status] || 'bg-gray-400']"
                :title="`${booking.customer_name} — ${booking.service?.name}`"
                @click="selectedBooking = booking"
              >
                <span class="font-medium">{{ formatTime(booking.start_time) }}</span>
                {{ booking.customer_name }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════ BOOKING DETAIL MODAL ══════════ -->
    <div v-if="selectedBooking" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="selectedBooking = null">
      <div class="fixed inset-0 bg-black/40" @click="selectedBooking = null"></div>
      <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 relative z-10 max-h-[90vh] overflow-y-auto">
        <div class="flex items-start justify-between mb-4">
          <div>
            <h2 class="text-lg font-bold text-gray-900">{{ selectedBooking.service?.name || 'Запись' }}</h2>
            <p class="text-sm text-gray-500">{{ formatDate(selectedBooking.start_time) }}</p>
          </div>
          <div class="flex items-center gap-2">
            <span :class="`badge-${selectedBooking.status}`">{{ statusLabels[selectedBooking.status] || selectedBooking.status }}</span>
            <button @click="selectedBooking = null" class="text-gray-400 hover:text-gray-600">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
        </div>

        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <div class="text-sm text-gray-500">Клиент</div>
              <div class="font-medium">{{ selectedBooking.customer_name }}</div>
              <a :href="`tel:${selectedBooking.customer_phone}`" class="text-sm text-primary-600">{{ selectedBooking.customer_phone }}</a>
              <div v-if="selectedBooking.customer_email" class="text-sm text-gray-500">{{ selectedBooking.customer_email }}</div>
            </div>
            <div>
              <div class="text-sm text-gray-500">Мастер</div>
              <div class="font-medium">{{ selectedBooking.master?.name || '—' }}</div>
            </div>
          </div>

          <div>
            <div class="text-sm text-gray-500">Время</div>
            <div class="font-medium">{{ formatTimeRange(selectedBooking.start_time, selectedBooking.end_time) }}</div>
          </div>

          <div v-if="selectedBooking.notes">
            <div class="text-sm text-gray-500">Примечание</div>
            <div class="text-gray-700">{{ selectedBooking.notes }}</div>
          </div>

          <!-- Status actions -->
          <div v-if="selectedBooking.status !== 'completed' && selectedBooking.status !== 'cancelled'" class="border-t border-gray-100 pt-4">
            <div class="text-sm font-medium text-gray-700 mb-2">Изменить статус</div>
            <div class="flex flex-wrap gap-2">
              <button
                v-if="selectedBooking.status === 'pending'"
                @click="changeStatus(selectedBooking.id, 'confirmed')"
                :disabled="updatingStatus"
                class="btn btn-primary btn-sm"
              >Подтвердить</button>
              <button
                v-if="selectedBooking.status === 'confirmed'"
                @click="changeStatus(selectedBooking.id, 'completed')"
                :disabled="updatingStatus"
                class="btn bg-green-600 text-white hover:bg-green-700 btn-sm"
              >Завершить</button>
              <button
                v-if="selectedBooking.status === 'confirmed'"
                @click="changeStatus(selectedBooking.id, 'no_show')"
                :disabled="updatingStatus"
                class="btn bg-orange-500 text-white hover:bg-orange-600 btn-sm"
              >Неявка</button>
              <button
                @click="changeStatus(selectedBooking.id, 'cancelled')"
                :disabled="updatingStatus"
                class="btn-danger btn-sm"
              >Отменить</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════ CREATE BOOKING MODAL ══════════ -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="showModal = false">
      <div class="fixed inset-0 bg-black/40" @click="showModal = false"></div>
      <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 relative z-10 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-lg font-bold text-gray-900">Новая запись</h2>
          <button @click="showModal = false" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <div v-if="modalError" class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">{{ modalError }}</div>

        <div class="space-y-4">
          <!-- Service -->
          <div>
            <label class="label">Услуга <span class="text-red-500">*</span></label>
            <select v-model="modalForm.service_id" class="input">
              <option value="">Выберите услугу</option>
              <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>

          <!-- Master -->
          <div>
            <label class="label">Мастер</label>
            <select v-model="modalForm.master_id" class="input">
              <option value="">Любой доступный</option>
              <option v-for="m in bookingsStore.masters" :key="m.id" :value="m.id">{{ m.name }}</option>
            </select>
          </div>

          <!-- Date -->
          <div>
            <label class="label">Дата <span class="text-red-500">*</span></label>
            <input v-model="modalForm.date" type="date" class="input" :min="new Date().toISOString().split('T')[0]" />
          </div>

          <!-- Slots -->
          <div v-if="modalForm.service_id && modalForm.date">
            <label class="label">Время <span class="text-red-500">*</span></label>
            <div v-if="loadingSlots" class="text-sm text-gray-500">Загрузка слотов...</div>
            <div v-else-if="availableSlots.length === 0" class="text-sm text-gray-500">Нет доступных слотов на эту дату</div>
            <div v-else class="grid grid-cols-4 gap-2">
              <button
                v-for="slot in availableSlots"
                :key="slot.time"
                :class="['px-3 py-2 rounded-lg border text-sm font-medium transition-colors',
                  modalForm.slot === slot.datetime
                    ? 'border-primary-600 bg-primary-50 text-primary-700'
                    : 'border-gray-200 text-gray-700 hover:border-primary-300']"
                @click="modalForm.slot = slot.datetime"
              >
                {{ slot.time }}
              </button>
            </div>
          </div>

          <!-- Customer -->
          <div class="border-t border-gray-100 pt-4">
            <div class="text-sm font-medium text-gray-700 mb-3">Клиент</div>
            <div class="space-y-3">
              <div>
                <label class="label">Имя <span class="text-red-500">*</span></label>
                <input v-model="modalForm.customer_name" type="text" class="input" placeholder="Иван Иванов" />
              </div>
              <div>
                <label class="label">Телефон <span class="text-red-500">*</span></label>
                <input v-model="modalForm.customer_phone" type="tel" class="input" placeholder="+7 999 123 45 67" />
              </div>
              <div>
                <label class="label">Email</label>
                <input v-model="modalForm.customer_email" type="email" class="input" placeholder="email@example.com" />
              </div>
              <div>
                <label class="label">Примечание</label>
                <textarea v-model="modalForm.notes" class="input" rows="2" placeholder="Дополнительная информация..."></textarea>
              </div>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-100">
          <button @click="showModal = false" class="btn-secondary">Отмена</button>
          <button @click="submitBooking" :disabled="creating" class="btn-primary">
            <template v-if="creating">
              <div class="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></div>
              Создание...
            </template>
            <template v-else>Создать запись</template>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
