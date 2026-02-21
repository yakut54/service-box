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
  return new Date(dateStr).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: shopTz.value })
}

function formatDateShort(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('ru-RU', {
    day: 'numeric', month: 'short', year: 'numeric',
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

  // Use store as instant cache
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
      if (err instanceof ApiError) {
        errorMsg.value = `Ошибка ${err.status}: ${err.message}`
      } else {
        errorMsg.value = 'Не удалось загрузить запись'
      }
    }
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="max-w-3xl">
    <!-- Loading -->
    <div v-if="loading" class="card py-12 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <!-- Not found -->
    <div v-else-if="!booking" class="card py-12 text-center">
      <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Запись не найдена</h2>
      <p v-if="errorMsg" class="text-sm text-red-500 dark:text-red-400 mb-4">{{ errorMsg }}</p>
      <RouterLink to="/bookings" class="btn-primary">Все записи</RouterLink>
    </div>

    <template v-else>
      <!-- Header -->
      <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ booking.service?.name || 'Запись' }}
          </h1>
          <p class="text-gray-500 dark:text-gray-400 mt-0.5">{{ formatDate(booking.start_time) }}</p>
        </div>
        <span :class="`badge-${booking.status} text-base px-3 py-1`">
          {{ statusLabels[booking.status] || booking.status }}
        </span>
      </div>

      <!-- Main info grid -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">

        <!-- Client -->
        <div class="card space-y-1">
          <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Клиент</div>
          <div class="font-semibold text-gray-900 dark:text-white">{{ booking.customer_name || '—' }}</div>
          <a
            v-if="booking.customer_phone"
            :href="`tel:${booking.customer_phone}`"
            class="flex items-center gap-1.5 text-primary-600 dark:text-primary-400 text-sm"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
            {{ booking.customer_phone }}
          </a>
          <a
            v-if="booking.customer_email"
            :href="`mailto:${booking.customer_email}`"
            class="flex items-center gap-1.5 text-primary-600 dark:text-primary-400 text-sm truncate"
          >
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            {{ booking.customer_email }}
          </a>
          <RouterLink
            v-if="booking.customer_id"
            :to="`/customers/${booking.customer_id}`"
            class="text-xs text-gray-400 dark:text-gray-500 hover:text-primary-600 dark:hover:text-primary-400 mt-1 inline-block"
          >
            Профиль клиента →
          </RouterLink>
        </div>

        <!-- Master -->
        <div class="card space-y-1">
          <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Мастер</div>
          <RouterLink
            v-if="booking.master"
            :to="`/masters/${booking.master_id}`"
            class="font-semibold text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400"
          >{{ booking.master.name }}</RouterLink>
          <div v-if="booking.master?.specialization" class="text-sm text-gray-500 dark:text-gray-400">{{ booking.master.specialization }}</div>
          <div v-if="!booking.master" class="text-gray-400 dark:text-gray-500 italic text-sm">Не назначен</div>
          <RouterLink
            v-if="booking.master_id"
            :to="`/masters/${booking.master_id}`"
            class="text-xs text-gray-400 dark:text-gray-500 hover:text-primary-600 dark:hover:text-primary-400 mt-1 inline-block"
          >
            Профиль мастера →
          </RouterLink>
        </div>

        <!-- Time -->
        <div class="card">
          <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Время</div>
          <div class="font-semibold text-gray-900 dark:text-white">
            {{ formatDateShort(booking.start_time) }}
          </div>
          <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            {{ formatTime(booking.start_time) }}
            <span v-if="booking.end_time"> — {{ formatTime(booking.end_time) }}</span>
          </div>
          <div v-if="booking.service?.duration_minutes" class="text-xs text-gray-400 dark:text-gray-500 mt-1">
            Длительность: {{ booking.service.duration_minutes }} мин
          </div>
        </div>

        <!-- Service -->
        <div class="card space-y-1">
          <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Услуга</div>
          <div class="font-semibold text-gray-900 dark:text-white">{{ booking.service?.name || '—' }}</div>
          <div v-if="booking.service?.description" class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            {{ booking.service.description }}
          </div>
          <RouterLink
            v-if="booking.service_id"
            :to="`/products/${booking.service_id}/edit`"
            class="text-xs text-gray-400 dark:text-gray-500 hover:text-primary-600 dark:hover:text-primary-400 mt-1 inline-block"
          >
            Карточка услуги →
          </RouterLink>
        </div>
      </div>

      <!-- Notes -->
      <div v-if="booking.notes" class="card mb-4">
        <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Примечание</div>
        <p class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed">{{ booking.notes }}</p>
      </div>

      <!-- Status actions -->
      <div v-if="booking.status !== 'completed' && booking.status !== 'cancelled'" class="card">
        <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Изменить статус</div>
        <div v-if="statusError" class="mb-3 text-sm text-red-500">{{ statusError }}</div>
        <div class="flex flex-wrap gap-2">
          <button
            v-if="booking.status === 'pending'"
            @click="changeStatus('confirmed')"
            :disabled="updatingStatus"
            class="btn-primary btn-sm"
          >
            Подтвердить
          </button>
          <button
            v-if="booking.status === 'confirmed'"
            @click="changeStatus('completed')"
            :disabled="updatingStatus"
            class="btn btn-sm bg-emerald-600 text-white hover:bg-emerald-700"
          >
            Завершить
          </button>
          <button
            v-if="booking.status === 'confirmed'"
            @click="changeStatus('no_show')"
            :disabled="updatingStatus"
            class="btn btn-sm bg-orange-500 text-white hover:bg-orange-600"
          >
            Неявка
          </button>
          <button
            @click="changeStatus('cancelled')"
            :disabled="updatingStatus"
            class="btn-danger btn-sm"
          >
            Отменить
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
