<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import type { Master, Booking, Product } from '@/types'

const route = useRoute()

const master = ref<Master | null>(null)
const bookings = ref<Booking[]>([])
const masterServices = ref<Product[]>([])
const loading = ref(true)

function formatDate(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleString('ru-RU', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function formatDateShort(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('ru-RU', {
    day: 'numeric', month: 'short', year: 'numeric',
  })
}

const bookingStatusLabels: Record<string, string> = {
  pending: 'Ожидает',
  confirmed: 'Подтверждена',
  completed: 'Завершена',
  cancelled: 'Отменена',
  no_show: 'Неявка',
}

const totalCount = computed(() => bookings.value.length)
const completedCount = computed(() => bookings.value.filter(b => b.status === 'completed').length)
const upcomingCount = computed(() => {
  const now = new Date()
  return bookings.value.filter(b => {
    return (b.status === 'pending' || b.status === 'confirmed') && new Date(b.start_time) > now
  }).length
})

function initials(name: string) {
  return name.split(' ').slice(0, 2).map((w: string) => w[0]).join('').toUpperCase()
}

onMounted(async () => {
  const id = route.params.id as string
  try {
    const [masterRes, bookingsRes, servicesRes, allProductsRes] = await Promise.all([
      api.getMaster(id),
      api.getBookings({ master_id: id, per_page: '200' }),
      api.getMasterServices(id),
      api.getProducts({ type: 'service', per_page: '100' }),
    ])
    master.value = masterRes.data
    bookings.value = bookingsRes.data
    const ids = new Set(servicesRes.data)
    masterServices.value = ids.size > 0
      ? allProductsRes.data.filter((p: Product) => ids.has(p.id))
      : []
  } catch { /* not found */ }
  loading.value = false
})
</script>

<template>
  <div class="max-w-4xl">
    <!-- Loading -->
    <div v-if="loading" class="card py-12 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <!-- Not found -->
    <div v-else-if="!master" class="card py-12 text-center">
      <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Мастер не найден</h2>
      <RouterLink to="/masters" class="btn-primary">Все мастера</RouterLink>
    </div>

    <template v-else>
      <!-- Header -->
      <div class="flex items-start gap-4 mb-6">
        <!-- Avatar -->
        <div class="flex-shrink-0">
          <img
              v-if="master.avatar_url"
              :src="master.avatar_url"
              :alt="master.name"
              class="w-16 h-16 rounded-full object-cover border border-gray-200 dark:border-gray-700"
          />
          <div
              v-else
              class="w-16 h-16 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-primary-600 dark:text-primary-400 font-bold text-2xl"
          >
            {{ initials(master.name) }}
          </div>
        </div>

        <!-- Name + spec + status -->
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-3 flex-wrap">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ master.name }}</h1>
            <span :class="master.is_active ? 'badge-paid' : 'badge-cancelled'">
              {{ master.is_active ? 'Активен' : 'Неактивен' }}
            </span>
          </div>
          <p v-if="master.specialization" class="text-gray-500 dark:text-gray-400 mt-0.5">
            {{ master.specialization }}
          </p>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
            Добавлен {{ formatDateShort(master.created_at) }}
          </p>
        </div>
      </div>

      <!-- Stats + Contacts -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Contacts -->
        <div class="card sm:col-span-2 lg:col-span-1">
          <div class="text-sm text-gray-500 dark:text-gray-400 mb-2">Контакты</div>
          <div class="space-y-2">
            <div v-if="master.phone">
              <div class="text-xs text-gray-400 dark:text-gray-500">Телефон</div>
              <a :href="`tel:${master.phone}`" class="font-medium text-primary-600 text-sm">{{ master.phone }}</a>
            </div>
            <div v-if="master.email">
              <div class="text-xs text-gray-400 dark:text-gray-500">Email</div>
              <a :href="`mailto:${master.email}`" class="font-medium text-primary-600 text-sm truncate block">{{ master.email }}</a>
            </div>
            <div v-if="!master.phone && !master.email" class="text-xs text-gray-400 italic">Нет контактов</div>
          </div>
        </div>

        <!-- Total bookings -->
        <div class="card">
          <div class="text-sm text-gray-500 dark:text-gray-400">Всего записей</div>
          <div class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ totalCount }}</div>
        </div>

        <!-- Completed -->
        <div class="card">
          <div class="text-sm text-gray-500 dark:text-gray-400">Завершено</div>
          <div class="text-3xl font-bold text-emerald-600 mt-1">{{ completedCount }}</div>
        </div>

        <!-- Upcoming -->
        <div class="card">
          <div class="text-sm text-gray-500 dark:text-gray-400">Предстоит</div>
          <div class="text-3xl font-bold text-primary-600 mt-1">{{ upcomingCount }}</div>
        </div>
      </div>

      <!-- Services -->
      <div class="card mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-3">Услуги мастера</h3>
        <div v-if="masterServices.length === 0" class="text-sm text-gray-400 dark:text-gray-500 italic">
          Не ограничено — выполняет все услуги
        </div>
        <div v-else class="flex flex-wrap gap-2">
          <span
            v-for="svc in masterServices"
            :key="svc.id"
            class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 border border-primary-200 dark:border-primary-800"
          >
            {{ svc.name }}
          </span>
        </div>
      </div>

      <!-- Bookings list -->
      <div class="card">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">
          Записи
          <span class="text-gray-400 dark:text-gray-500 font-normal text-sm ml-1">({{ totalCount }})</span>
        </h3>

        <div v-if="!bookings.length" class="text-gray-500 dark:text-gray-400 text-sm py-4 text-center">
          Нет записей
        </div>

        <div v-else class="divide-y divide-gray-100 dark:divide-gray-700">
          <RouterLink
              v-for="booking in bookings"
              :key="booking.id"
              :to="`/bookings/${booking.id}`"
              class="py-3 flex items-center justify-between gap-3 hover:bg-gray-50 dark:hover:bg-gray-700/30 -mx-6 px-6 transition-colors"
          >
            <div class="min-w-0 flex-1">
              <div class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">
                {{ booking.service?.name || booking.service_name || '—' }}
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {{ formatDate(booking.start_time) }}
                <span v-if="booking.customer_name"> &middot; {{ booking.customer_name }}</span>
              </div>
            </div>
            <span :class="`badge-${booking.status}`">
              {{ bookingStatusLabels[booking.status] || booking.status }}
            </span>
          </RouterLink>
        </div>
      </div>
    </template>
  </div>
</template>
