<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
import { BOOKING_STATUS_LABELS } from '@/shared/lib/labels'
import { UiSpinner, UiEmptyState } from '@/shared/ui'
import type { Booking, BookingStatus } from '@/types'

const authStore = useAuthStore()
const toast = useToast()

// ── Timezone helpers ────────────────────────────────────────────
const shopTz = computed(() => {
  const tz = authStore.shop?.timezone
  if (!tz) return 'Europe/Moscow'
  try { Intl.DateTimeFormat(undefined, { timeZone: tz }); return tz } catch { return 'Europe/Moscow' }
})

function toYMD(d: Date): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: shopTz.value }).format(d)
}

function shopParts(dateStr: string) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: shopTz.value,
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(new Date(dateStr))
  const get = (t: string) => parseInt(parts.find(p => p.type === t)?.value ?? '0')
  return { hour: get('hour') % 24, minute: get('minute') }
}

function formatTime(dateStr: string): string {
  const { hour, minute } = shopParts(dateStr)
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`
}

// ── State ───────────────────────────────────────────────────────
const anchorDate  = ref(toYMD(new Date()))
const bookings    = ref<Booking[]>([])
const loading     = ref(false)
const updatingId  = ref<string | null>(null)

const isToday = computed(() => anchorDate.value === toYMD(new Date()))

const navLabel = computed(() => {
  const d = new Date(anchorDate.value + 'T12:00:00')
  return d.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' })
})

// ── Load ────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const res = await api.getBookings({ date: anchorDate.value })
    bookings.value = res.data
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось загрузить расписание')
  } finally {
    loading.value = false
  }
}

// ── Navigation ──────────────────────────────────────────────────
function navigate(delta: number) {
  const d = new Date(anchorDate.value + 'T12:00:00')
  d.setDate(d.getDate() + delta)
  anchorDate.value = toYMD(d)
  load()
}

// ── Status helpers ──────────────────────────────────────────────
function effectiveStatus(b: Booking): string {
  if (['pending', 'confirmed'].includes(b.status) && new Date(b.start_time) < new Date()) {
    return 'no_show'
  }
  return b.status
}

type StatusAction = { label: string; next: BookingStatus; style: string }

function statusActions(b: Booking): StatusAction[] {
  const isPast = new Date(b.start_time) < new Date()
  if (b.status === 'pending' && !isPast) return [
    { label: 'Подтвердить', next: 'confirmed', style: 'btn-primary btn-sm flex-1' },
    { label: 'Отменить',    next: 'cancelled', style: 'btn-danger btn-sm'         },
  ]
  if (b.status === 'confirmed') return [
    { label: '✓ Пришёл',    next: 'completed', style: 'btn-primary btn-sm flex-1' },
    { label: 'Не пришёл',   next: 'no_show',   style: 'btn-danger btn-sm'         },
  ]
  return []
}

async function changeStatus(booking: Booking, next: BookingStatus) {
  updatingId.value = booking.id
  try {
    const res = await api.updateBookingStatus(booking.id, next)
    const idx = bookings.value.findIndex(b => b.id === booking.id)
    if (idx !== -1) bookings.value[idx] = res.data
    toast.success('Статус обновлён')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Ошибка')
  } finally {
    updatingId.value = null
  }
}

// ── Sorted: active first, then cancelled/no_show ────────────────
const activeBookings   = computed(() =>
  bookings.value
    .filter(b => !['cancelled', 'no_show'].includes(b.status))
    .sort((a, b) => new Date(a.start_time).getTime() - new Date(b.start_time).getTime())
)
const inactiveBookings = computed(() =>
  bookings.value
    .filter(b => ['cancelled', 'no_show'].includes(b.status))
    .sort((a, b) => new Date(a.start_time).getTime() - new Date(b.start_time).getTime())
)

// ── Auto-refresh every 60s ──────────────────────────────────────
useAutoRefresh(load, 60_000)
onMounted(load)
</script>

<template>
  <div class="flex flex-col min-h-0">

    <!-- Date navigation -->
    <div class="sticky top-0 z-10 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 px-4 py-3">
      <div class="flex items-center gap-2">
        <!-- Prev -->
        <button
          @click="navigate(-1)"
          class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 active:scale-95 transition-all flex-shrink-0"
          aria-label="Предыдущий день"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </button>

        <!-- Date label -->
        <div class="flex-1 text-center">
          <div class="font-semibold text-gray-900 dark:text-white text-sm capitalize leading-tight">
            {{ navLabel }}
          </div>
          <div v-if="isToday" class="text-xs text-primary-600 dark:text-primary-400 font-medium">Сегодня</div>
        </div>

        <!-- Next -->
        <button
          @click="navigate(+1)"
          class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 active:scale-95 transition-all flex-shrink-0"
          aria-label="Следующий день"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </button>

        <!-- Today button (shows only when not today) -->
        <button
          v-if="!isToday"
          @click="anchorDate = toYMD(new Date()); load()"
          class="btn-ghost btn-sm flex-shrink-0 text-xs"
        >
          Сегодня
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading && bookings.length === 0" class="flex flex-col items-center justify-center py-20 gap-3">
      <UiSpinner />
      <p class="text-sm text-gray-500 dark:text-gray-400">Загрузка...</p>
    </div>

    <!-- Empty -->
    <UiEmptyState
      v-else-if="!loading && bookings.length === 0"
      title="Записей нет"
      :description="isToday ? 'На сегодня записей нет' : 'На этот день записей нет'"
    >
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      </template>
    </UiEmptyState>

    <!-- Booking list -->
    <div v-else class="flex flex-col gap-0 pb-6">

      <!-- Active bookings -->
      <div
        v-for="booking in activeBookings"
        :key="booking.id"
        class="mx-4 mt-3 rounded-xl border bg-white dark:bg-gray-900 shadow-sm overflow-hidden"
        :class="{
          'border-primary-200 dark:border-primary-800': booking.status === 'confirmed',
          'border-amber-200 dark:border-amber-800':     booking.status === 'pending',
          'border-emerald-200 dark:border-emerald-800': booking.status === 'completed',
          'border-gray-200 dark:border-gray-700':       !['confirmed','pending','completed'].includes(booking.status),
        }"
      >
        <!-- Top stripe by status -->
        <div
          class="h-1 w-full"
          :class="{
            'bg-primary-500': booking.status === 'confirmed',
            'bg-amber-400':   booking.status === 'pending',
            'bg-emerald-500': booking.status === 'completed',
          }"
        />

        <div class="p-4">
          <!-- Time + status -->
          <div class="flex items-start justify-between gap-2 mb-3">
            <div>
              <div class="text-xl font-bold text-gray-900 dark:text-white leading-none">
                {{ formatTime(booking.start_time) }}
              </div>
              <div v-if="booking.end_time" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                до {{ formatTime(booking.end_time) }}
              </div>
            </div>
            <span :class="`badge-${effectiveStatus(booking)}`">
              {{ BOOKING_STATUS_LABELS[effectiveStatus(booking)] || effectiveStatus(booking) }}
            </span>
          </div>

          <!-- Service -->
          <div class="text-sm font-medium text-gray-900 dark:text-white mb-1 truncate">
            {{ booking.service?.name ?? booking.service_name ?? '—' }}
          </div>

          <!-- Client name -->
          <div class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300 mb-1">
            <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
            <span class="truncate">{{ booking.customer_name }}</span>
          </div>

          <!-- Phone (tap to call) — hidden if owner enabled client base protection -->
          <a
            v-if="booking.customer_phone && !authStore.shop?.hide_customer_phone"
            :href="`tel:${booking.customer_phone}`"
            class="flex items-center gap-1.5 text-sm text-primary-600 dark:text-primary-400 hover:underline mb-3"
          >
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
            {{ booking.customer_phone }}
          </a>

          <!-- Notes -->
          <p v-if="booking.notes" class="text-xs text-gray-500 dark:text-gray-400 mb-3 bg-gray-50 dark:bg-gray-800 rounded-lg px-2.5 py-1.5 italic">
            {{ booking.notes }}
          </p>

          <!-- Action buttons -->
          <div v-if="statusActions(booking).length" class="flex gap-2 pt-1">
            <button
              v-for="action in statusActions(booking)"
              :key="action.next"
              :class="action.style"
              :disabled="updatingId === booking.id"
              @click="changeStatus(booking, action.next)"
            >
              <UiSpinner v-if="updatingId === booking.id" class="w-4 h-4" />
              <span v-else>{{ action.label }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Cancelled / no-show section -->
      <template v-if="inactiveBookings.length">
        <div class="mx-4 mt-6 mb-2 text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide">
          Отменено / Неявки
        </div>
        <div
          v-for="booking in inactiveBookings"
          :key="booking.id"
          class="mx-4 mt-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 opacity-60 overflow-hidden"
        >
          <div class="p-4">
            <div class="flex items-center justify-between gap-2">
              <div>
                <div class="font-semibold text-gray-700 dark:text-gray-300">{{ formatTime(booking.start_time) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ booking.service?.name ?? booking.service_name }}</div>
                <div class="text-sm text-gray-600 dark:text-gray-400">{{ booking.customer_name }}</div>
              </div>
              <span :class="`badge-${booking.status} flex-shrink-0`">
                {{ BOOKING_STATUS_LABELS[booking.status] || booking.status }}
              </span>
            </div>
          </div>
        </div>
      </template>

    </div>

  </div>
</template>
