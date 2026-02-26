<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useBookingsStore } from '@/stores/bookings'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const bookingsStore = useBookingsStore()
const authStore = useAuthStore()
const shopTz = computed(() => authStore.shop?.timezone || 'Europe/Moscow')

const booking = ref<any>(null)
const loading = ref(true)
const updatingStatus = ref(false)
const statusError = ref('')
const errorMsg = ref<string | null>(null)

const statusLabels: Record<string, string> = {
  pending:   'Ожидает',
  confirmed: 'Подтверждена',
  completed: 'Завершена',
  cancelled: 'Отменена',
  no_show:   'Неявка',
}

function formatDate(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleString('ru-RU', {
    day: 'numeric', month: 'long', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
    timeZone: shopTz.value,
  })
}

function formatTime(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleTimeString('ru-RU', {
    hour: '2-digit', minute: '2-digit',
    timeZone: shopTz.value,
  })
}

function formatDateLong(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('ru-RU', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    timeZone: shopTz.value,
  })
}

async function changeStatus(status: string) {
  if (!booking.value) return
  updatingStatus.value = true
  statusError.value = ''
  try {
    const res = await api.updateBookingStatus(booking.value.id, status)
    booking.value = res.data
  } catch (e: any) {
    statusError.value = e.message || 'Не удалось изменить статус'
  }
  updatingStatus.value = false
}

onMounted(async () => {
  const id = route.params.id as string

  const cached = bookingsStore.bookings.find(b => b.id === id)
  if (cached) {
    booking.value = cached
    loading.value = false
  }

  try {
    const res = await api.getBooking(id)
    booking.value = res.data
    errorMsg.value = null
  } catch (err) {
    console.error('[BookingDetail] fetch failed:', err)
    if (!booking.value) {
      errorMsg.value = err instanceof ApiError
        ? `Ошибка ${err.status}: ${err.message}`
        : 'Не удалось загрузить запись'
    }
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="max-w-5xl">

    <!-- Loading -->
    <div v-if="loading" class="card py-16 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <!-- Not found -->
    <div v-else-if="!booking" class="card py-16 text-center">
      <svg class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
      </svg>
      <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Запись не найдена</h2>
      <p v-if="errorMsg" class="text-sm text-red-500 dark:text-red-400 mb-4">{{ errorMsg }}</p>
      <RouterLink to="/bookings" class="btn-primary">Все записи</RouterLink>
    </div>

    <template v-else>
      <!-- Header -->
      <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ booking.service?.name || 'Запись' }}</h1>
          <p class="text-gray-500 dark:text-gray-400 mt-0.5 text-sm font-mono">{{ booking.id.slice(0, 8) }}</p>
        </div>
        <span :class="`badge-${booking.status} text-sm px-3 py-1.5`">{{ statusLabels[booking.status] || booking.status }}</span>
      </div>

      <!-- Status actions -->
      <div v-if="!['completed', 'cancelled'].includes(booking.status)" class="card mb-6 flex flex-wrap items-center gap-3">
        <span class="text-sm text-gray-500 dark:text-gray-400 mr-1">Действия:</span>
        <button
          v-if="booking.status === 'pending'"
          @click="changeStatus('confirmed')"
          :disabled="updatingStatus"
          class="btn-primary btn-sm"
        >Подтвердить</button>
        <button
          v-if="booking.status === 'confirmed'"
          @click="changeStatus('completed')"
          :disabled="updatingStatus"
          class="btn btn-sm bg-emerald-600 text-white hover:bg-emerald-700"
        >Завершить</button>
        <button
          v-if="booking.status === 'confirmed'"
          @click="changeStatus('no_show')"
          :disabled="updatingStatus"
          class="btn btn-sm bg-orange-500 text-white hover:bg-orange-600"
        >Неявка</button>
        <button
          @click="changeStatus('cancelled')"
          :disabled="updatingStatus"
          class="btn-danger btn-sm"
        >Отменить</button>
        <div v-if="updatingStatus" class="animate-spin w-4 h-4 border-2 border-primary-600 border-t-transparent rounded-full ml-1"></div>
        <p v-if="statusError" class="text-sm text-red-500 ml-2">{{ statusError }}</p>
      </div>

      <!-- Body -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left: time + service + notes -->
        <div class="lg:col-span-2 space-y-6">

          <!-- Time card — hero block -->
          <div class="card p-0 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-2">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
              </svg>
              <h2 class="font-semibold text-gray-900 dark:text-white">Дата и время</h2>
            </div>
            <div class="px-5 py-5 flex items-center gap-6 flex-wrap">
              <!-- Date -->
              <div>
                <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1">Дата</div>
                <div class="text-lg font-bold text-gray-900 dark:text-white capitalize">{{ formatDateLong(booking.start_time) }}</div>
              </div>
              <!-- Divider -->
              <div class="h-10 w-px bg-gray-200 dark:bg-gray-700 hidden sm:block"></div>
              <!-- Time range -->
              <div>
                <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1">Время</div>
                <div class="text-lg font-bold text-gray-900 dark:text-white tabular-nums">
                  {{ formatTime(booking.start_time) }}
                  <span class="text-gray-400 dark:text-gray-500 font-normal mx-1">—</span>
                  {{ formatTime(booking.end_time) }}
                </div>
              </div>
              <!-- Duration -->
              <div v-if="booking.service?.service?.duration_minutes">
                <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1">Длительность</div>
                <div class="text-base font-semibold text-gray-700 dark:text-gray-300">{{ booking.service.service.duration_minutes }} мин</div>
              </div>
            </div>
          </div>

          <!-- Service card -->
          <div class="card p-0 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-2">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
              <h2 class="font-semibold text-gray-900 dark:text-white">Услуга</h2>
            </div>
            <div class="px-5 py-4">
              <div class="font-semibold text-gray-900 dark:text-white text-base">{{ booking.service?.name || '—' }}</div>
              <p v-if="booking.service?.description" class="text-sm text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">{{ booking.service.description }}</p>
              <RouterLink
                v-if="booking.service_id"
                :to="`/products/${booking.service_id}/edit`"
                class="inline-flex items-center gap-1 text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 mt-3 transition-colors"
              >
                Карточка услуги
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
              </RouterLink>
            </div>
          </div>

          <!-- Notes -->
          <div v-if="booking.notes" class="card">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-2 flex items-center gap-2">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
              </svg>
              Примечание
            </h3>
            <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">{{ booking.notes }}</p>
          </div>
        </div>

        <!-- Right sidebar -->
        <div class="space-y-4">

          <!-- Customer card -->
          <div class="card">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">Клиент</div>
            <div class="flex items-center gap-3 mb-4">
              <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 flex items-center justify-center font-bold text-sm flex-shrink-0 select-none">
                {{ booking.customer_name?.charAt(0)?.toUpperCase() || '?' }}
              </div>
              <div class="min-w-0">
                <RouterLink
                  v-if="booking.customer_id"
                  :to="`/customers/${booking.customer_id}`"
                  class="font-semibold text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 transition-colors truncate block"
                >{{ booking.customer_name }}</RouterLink>
                <div v-else class="font-semibold text-gray-900 dark:text-white truncate">{{ booking.customer_name || '—' }}</div>
              </div>
            </div>
            <div class="space-y-2 text-sm">
              <a
                v-if="booking.customer_phone"
                :href="`tel:${booking.customer_phone}`"
                class="flex items-center gap-2 text-primary-600 dark:text-primary-400 hover:text-primary-700 transition-colors"
              >
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                {{ booking.customer_phone }}
              </a>
              <a
                v-if="booking.customer_email"
                :href="`mailto:${booking.customer_email}`"
                class="flex items-center gap-2 text-primary-600 dark:text-primary-400 hover:text-primary-700 transition-colors truncate"
              >
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                {{ booking.customer_email }}
              </a>
            </div>
            <RouterLink
              v-if="booking.customer_id"
              :to="`/customers/${booking.customer_id}`"
              class="btn-secondary btn-sm w-full mt-4 text-center"
            >
              Профиль клиента →
            </RouterLink>
          </div>

          <!-- Master card -->
          <div class="card">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">Мастер</div>
            <div v-if="booking.master" class="flex items-center gap-3 mb-3">
              <div class="w-10 h-10 rounded-full bg-violet-100 dark:bg-violet-900/30 text-violet-600 dark:text-violet-400 flex items-center justify-center font-bold text-sm flex-shrink-0 select-none">
                {{ booking.master.name?.charAt(0)?.toUpperCase() || '?' }}
              </div>
              <div class="min-w-0">
                <RouterLink
                  :to="`/masters/${booking.master_id}`"
                  class="font-semibold text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 transition-colors truncate block"
                >{{ booking.master.name }}</RouterLink>
                <div v-if="booking.master.specialization" class="text-xs text-gray-400 dark:text-gray-500 truncate">{{ booking.master.specialization }}</div>
              </div>
            </div>
            <div v-else class="flex items-center gap-2 text-sm text-gray-400 dark:text-gray-500 italic mb-3">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
              Мастер не назначен
            </div>
            <RouterLink
              v-if="booking.master_id"
              :to="`/masters/${booking.master_id}`"
              class="btn-secondary btn-sm w-full text-center"
            >
              Профиль мастера →
            </RouterLink>
          </div>

          <!-- Booking created at -->
          <div v-if="booking.created_at" class="card">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Создана</div>
            <div class="text-sm text-gray-700 dark:text-gray-300">{{ formatDate(booking.created_at) }}</div>
          </div>

        </div>
      </div>
    </template>
  </div>
</template>
