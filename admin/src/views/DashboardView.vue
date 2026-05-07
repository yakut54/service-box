<script setup lang="ts">
import { onMounted, computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useOrdersStore } from '@/stores/orders'
import { useProductsStore } from '@/stores/products'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'
import type { Booking } from '@/types'

const authStore     = useAuthStore()
const ordersStore   = useOrdersStore()
const productsStore = useProductsStore()

// ── Today's bookings ─────────────────────────────────────────
const todayBookings        = ref<Booking[]>([])
const loadingTodayBookings = ref(false)

async function loadTodayBookings() {
  loadingTodayBookings.value = true
  try {
    const now   = new Date()
    const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
    const res   = await api.getBookings({ date: today })
    todayBookings.value = res.data ?? []
  } catch { /* ignore */ }
  loadingTodayBookings.value = false
}

// ── Derived ───────────────────────────────────────────────────
const pendingOrders  = computed(() => ordersStore.pendingOrders)
const recentOrders   = computed(() => ordersStore.orders.slice(0, 5))

function formatTime(dateStr: string) {
  return new Date(dateStr).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function formatPriceFull(kopecks: number) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(kopecks / 100)
}

const bookingStatusLabel: Record<string, string> = {
  pending:   'Ожидает',
  confirmed: 'Подтверждена',
  completed: 'Завершена',
  cancelled: 'Отменена',
  no_show:   'Неявка',
}

const orderStatusLabel: Record<string, string> = {
  pending:    'Ожидает',
  paid:       'Оплачен',
  processing: 'В работе',
  completed:  'Завершён',
  cancelled:  'Отменён',
}

onMounted(() => Promise.all([
  ordersStore.fetchOrders(),
  productsStore.fetchProducts(),
  loadTodayBookings(),
]))
</script>

<template>
  <div class="space-y-6">

    <!-- Header -->
    <div>
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ authStore.shop?.name }}</h1>
      <p class="text-gray-500 dark:text-gray-400 mt-0.5 text-sm">
        {{ new Date().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' }) }}
      </p>
    </div>

    <!-- Pending orders alert -->
    <RouterLink
      v-if="pendingOrders.length > 0"
      to="/orders"
      class="flex items-center gap-3 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl hover:bg-yellow-100 dark:hover:bg-yellow-900/30 transition-colors"
    >
      <span class="relative flex h-3 w-3 flex-shrink-0">
        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75"/>
        <span class="relative inline-flex rounded-full h-3 w-3 bg-yellow-500"/>
      </span>
      <p class="text-sm font-medium text-yellow-800 dark:text-yellow-300">
        {{ pendingOrders.length }} {{ plural(pendingOrders.length, 'заказ ожидает', 'заказа ожидают', 'заказов ожидают') }} обработки
      </p>
      <span class="ml-auto text-xs text-yellow-600 dark:text-yellow-400">Открыть →</span>
    </RouterLink>

    <!-- Main content -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Today's bookings -->
      <div class="card">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Записи на сегодня</h2>
          <RouterLink to="/bookings" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700">
            Все записи →
          </RouterLink>
        </div>

        <div v-if="loadingTodayBookings" class="space-y-2">
          <div v-for="i in 4" :key="i" class="h-11 bg-gray-100 dark:bg-gray-800 rounded-lg animate-pulse"/>
        </div>

        <div v-else-if="todayBookings.length === 0" class="py-10 text-center text-gray-400 text-sm">
          Нет записей на сегодня
        </div>

        <div v-else class="space-y-0.5">
          <RouterLink
            v-for="b in todayBookings"
            :key="b.id"
            :to="`/bookings/${b.id}`"
            :class="[
              'flex items-center justify-between py-2.5 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors',
              (b.status === 'cancelled' || b.status === 'no_show') ? 'opacity-40' : '',
            ]"
          >
            <div class="flex items-center gap-3">
              <span class="text-sm font-medium text-gray-600 dark:text-gray-400 w-12 tabular-nums shrink-0">
                {{ formatTime(b.start_time) }}
              </span>
              <div>
                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ b.customer_name }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ b.service?.name || '—' }}</div>
              </div>
            </div>
            <span :class="`badge-${b.status}`">{{ bookingStatusLabel[b.status] || b.status }}</span>
          </RouterLink>
        </div>
      </div>

      <!-- Recent orders -->
      <div class="card">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Последние заказы</h2>
          <RouterLink to="/orders" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700">
            Все заказы →
          </RouterLink>
        </div>

        <div v-if="ordersStore.loading" class="space-y-2">
          <div v-for="i in 4" :key="i" class="h-11 bg-gray-100 dark:bg-gray-800 rounded-lg animate-pulse"/>
        </div>

        <div v-else-if="recentOrders.length === 0" class="py-10 text-center text-gray-400 text-sm">Нет заказов</div>

        <div v-else class="space-y-0.5">
          <RouterLink
            v-for="order in recentOrders"
            :key="order.id"
            :to="`/orders/${order.id}`"
            :class="[
              'flex items-center justify-between py-2.5 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors',
              order.status === 'cancelled' ? 'opacity-40' : '',
            ]"
          >
            <div>
              <div class="text-sm font-medium text-primary-600 dark:text-primary-400 tabular-nums">#{{ order.id.slice(0, 8) }}</div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ order.customer_name }}</div>
            </div>
            <div class="text-right">
              <div class="text-sm font-semibold text-gray-900 dark:text-white tabular-nums">{{ formatPriceFull(order.total_price) }}</div>
              <span :class="`badge-${order.status}`">{{ orderStatusLabel[order.status] || order.status }}</span>
            </div>
          </RouterLink>
        </div>
      </div>
    </div>

    <!-- Quick actions -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <RouterLink to="/products/new" class="card hover:border-primary-300 dark:hover:border-primary-700 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-primary-100 dark:bg-primary-900/30 rounded-xl flex items-center justify-center group-hover:bg-primary-200 dark:group-hover:bg-primary-800/50 transition-colors flex-shrink-0">
            <svg class="w-5 h-5 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
          </div>
          <div class="min-w-0">
            <div class="font-medium text-gray-900 dark:text-white text-sm">Добавить товар</div>
            <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ productsStore.activeProducts.length }} активных</div>
          </div>
        </div>
      </RouterLink>

      <RouterLink to="/analytics" class="card hover:border-violet-300 dark:hover:border-violet-700 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-violet-100 dark:bg-violet-900/30 rounded-xl flex items-center justify-center group-hover:bg-violet-200 dark:group-hover:bg-violet-900/50 transition-colors flex-shrink-0">
            <svg class="w-5 h-5 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
          </div>
          <div class="min-w-0">
            <div class="font-medium text-gray-900 dark:text-white text-sm">Аналитика</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Выручка и статистика</div>
          </div>
        </div>
      </RouterLink>

      <RouterLink to="/masters" class="card hover:border-gray-300 dark:hover:border-gray-600 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-xl flex items-center justify-center group-hover:bg-gray-200 dark:group-hover:bg-gray-600 transition-colors flex-shrink-0">
            <svg class="w-5 h-5 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
          </div>
          <div class="min-w-0">
            <div class="font-medium text-gray-900 dark:text-white text-sm">Мастера</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Управление персоналом</div>
          </div>
        </div>
      </RouterLink>
    </div>

  </div>
</template>
