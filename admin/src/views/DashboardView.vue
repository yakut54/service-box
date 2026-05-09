<script setup lang="ts">
import { onMounted, computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useOrdersStore } from '@/stores/orders'
import { useProductsStore } from '@/stores/products'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'
import KpiCard from '@/components/KpiCard.vue'
import RevenueChart from '@/components/RevenueChart.vue'
import type { Booking, OrderStats } from '@/types'

const authStore = useAuthStore()
const ordersStore = useOrdersStore()
const productsStore = useProductsStore()
const shopTz = computed(() => authStore.shop?.timezone || 'Europe/Moscow')

const todayStats = ref<Partial<OrderStats>>({})
const loadingStats = ref(false)
const chartData = ref<Array<{ date: string; orders: number; revenue: number }>>([])
const loadingChart = ref(false)
const todayBookings = ref<Booking[]>([])
const loadingBookings = ref(false)

async function loadStats() {
  loadingStats.value = true
  try { todayStats.value = await api.getOrderStats({ period: 'today' }) }
  catch { /* ignore */ }
  loadingStats.value = false
}

async function loadChart() {
  loadingChart.value = true
  try { chartData.value = (await api.getOrderChart(7)).data }
  catch { /* ignore */ }
  loadingChart.value = false
}

async function loadBookings() {
  loadingBookings.value = true
  try {
    const now = new Date()
    const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
    todayBookings.value = (await api.getBookings({ date: today })).data ?? []
  } catch { /* ignore */ }
  loadingBookings.value = false
}

const pendingOrders = computed(() => ordersStore.pendingOrders)

const upcomingBookings = computed(() => {
  const now = new Date()
  return todayBookings.value
    .filter(b => new Date(b.start_time) >= now && !['cancelled', 'no_show'].includes(b.status))
    .sort((a, b) => new Date(a.start_time).getTime() - new Date(b.start_time).getTime())
    .slice(0, 4)
})
const recentOrders = computed(() => ordersStore.orders.slice(0, 5))

function formatPriceFull(rubles: number) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(rubles)
}

function formatPrice(rubles: number) {
  if (rubles >= 1_000_000) return (rubles / 1_000_000).toFixed(1) + 'M ₽'
  if (rubles >= 1_000) return Math.round(rubles / 1_000) + 'K ₽'
  return Math.round(rubles) + ' ₽'
}

const bookingStatusLabel: Record<string, string> = {
  pending: 'Ожидает', confirmed: 'Подтверждена', completed: 'Завершена', cancelled: 'Отменена', no_show: 'Неявка',
}
const orderStatusLabel: Record<string, string> = {
  pending: 'Ожидает', paid: 'Оплачен', processing: 'В работе', completed: 'Завершён', cancelled: 'Отменён',
}

function formatTime(d: string) {
  return new Date(d).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: shopTz.value })
}

onMounted(() => Promise.all([
  ordersStore.fetchOrders(),
  productsStore.fetchProducts(),
  loadStats(),
  loadChart(),
  loadBookings(),
]))
</script>

<template>
  <div class="space-y-6">

    <!-- Header -->
    <div>
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ authStore.shop?.name }}</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
        {{ new Date().toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long' }) }}
      </p>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
      <KpiCard title="Выручка сегодня" :value="formatPrice(todayStats.total_revenue ?? 0)" :loading="loadingStats"
        color="green" icon="revenue" />
      <KpiCard title="Заказов сегодня" :value="String(todayStats.total_orders ?? 0)" :loading="loadingStats"
        color="blue" icon="orders" />
      <KpiCard title="Записей сегодня" :value="String(todayBookings.length)" :loading="loadingBookings" color="violet"
        icon="bookings" />
      <KpiCard title="Ожидают оплаты" :value="String(todayStats.pending_orders ?? 0)" :loading="loadingStats"
        color="yellow" icon="pending" />
    </div>

    <!-- Pending alert -->
    <RouterLink v-if="pendingOrders.length > 0" to="/orders"
      class="flex items-center gap-3 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl hover:bg-yellow-100 dark:hover:bg-yellow-900/30 transition-colors">
      <span class="relative flex h-3 w-3 flex-shrink-0">
        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-yellow-400 opacity-75" />
        <span class="relative inline-flex rounded-full h-3 w-3 bg-yellow-500" />
      </span>
      <p class="text-sm font-medium text-yellow-800 dark:text-yellow-300">
        {{ pendingOrders.length }} {{ plural(pendingOrders.length, 'заказ ожидает', 'заказа ожидают', 'заказов ожидают')
        }} обработки
      </p>
      <span class="ml-auto text-xs text-yellow-600 dark:text-yellow-400">Открыть →</span>
    </RouterLink>

    <!-- Upcoming bookings + Chart -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Left: Upcoming bookings -->
      <div class="card flex flex-col">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Ближайшие записи</h2>
          <RouterLink to="/bookings" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700">Все →</RouterLink>
        </div>
        <div v-if="loadingBookings" class="space-y-3">
          <div v-for="i in 3" :key="i" class="h-14 bg-gray-100 dark:bg-gray-800 rounded-lg animate-pulse" />
        </div>
        <template v-else-if="upcomingBookings.length">
          <div class="space-y-1 flex-1">
            <RouterLink
              v-for="b in upcomingBookings" :key="b.id"
              :to="`/bookings/${b.id}`"
              class="flex items-center gap-3 py-2.5 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
            >
              <div class="w-12 text-center flex-shrink-0">
                <div class="text-sm font-semibold text-gray-900 dark:text-white tabular-nums">{{ formatTime(b.start_time) }}</div>
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ b.customer_name }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ b.service?.name || '—' }}<template v-if="b.master"> · {{ b.master.name }}</template></div>
              </div>
              <span :class="`badge-${b.status} flex-shrink-0`">{{ bookingStatusLabel[b.status] || b.status }}</span>
            </RouterLink>
          </div>
        </template>
        <div v-else class="flex-1 flex flex-col items-center justify-center py-8 text-center">
          <svg class="w-10 h-10 text-gray-200 dark:text-gray-700 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
          <p class="text-sm text-gray-400 dark:text-gray-500">Ближайших записей нет</p>
        </div>
      </div>
      <!-- Right: Chart -->
      <div class="card flex flex-col min-h-[280px]">
        <div class="flex items-center justify-between mb-4 flex-shrink-0">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Выручка за 7 дней</h2>
          <RouterLink to="/analytics" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700">
            Подробная аналитика →
          </RouterLink>
        </div>
        <RevenueChart :data="chartData" :loading="loadingChart" />
      </div>
    </div>

    <!-- Bookings + Orders -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <div class="card">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Записи на сегодня</h2>
          <RouterLink to="/bookings" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700">Все →
          </RouterLink>
        </div>
        <div v-if="loadingBookings" class="space-y-2">
          <div v-for="i in 4" :key="i" class="h-11 bg-gray-100 dark:bg-gray-800 rounded-lg animate-pulse" />
        </div>
        <div v-else-if="todayBookings.length === 0" class="py-10 text-center text-gray-400 text-sm">Нет записей на
          сегодня</div>
        <div v-else class="space-y-0.5">
          <RouterLink v-for="b in todayBookings.slice(0, 5)" :key="b.id" :to="`/bookings/${b.id}`" :class="['flex items-center justify-between py-2.5 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors',
            (b.status === 'cancelled' || b.status === 'no_show') ? 'opacity-40' : '']">
            <div class="flex items-center gap-3">
              <span class="text-sm font-medium text-gray-600 dark:text-gray-400 w-12 tabular-nums shrink-0">{{
                formatTime(b.start_time) }}</span>
              <div>
                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ b.customer_name }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ b.service?.name || '—' }}</div>
              </div>
            </div>
            <span :class="`badge-${b.status}`">{{ bookingStatusLabel[b.status] || b.status }}</span>
          </RouterLink>
        </div>
      </div>

      <div class="card">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Последние заказы</h2>
          <RouterLink to="/orders" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700">Все →
          </RouterLink>
        </div>
        <div v-if="ordersStore.loading" class="space-y-2">
          <div v-for="i in 4" :key="i" class="h-11 bg-gray-100 dark:bg-gray-800 rounded-lg animate-pulse" />
        </div>
        <div v-else-if="recentOrders.length === 0" class="py-10 text-center text-gray-400 text-sm">Нет заказов</div>
        <div v-else class="space-y-0.5">
          <RouterLink v-for="order in recentOrders" :key="order.id" :to="`/orders/${order.id}`" :class="['flex items-center justify-between py-2.5 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors',
            order.status === 'cancelled' ? 'opacity-40' : '']">
            <div>
              <div class="text-sm font-medium text-primary-600 dark:text-primary-400 tabular-nums">#{{ order.id.slice(0,
                8) }}</div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ order.customer_name }}</div>
            </div>
            <div class="text-right">
              <div class="text-sm font-semibold text-gray-900 dark:text-white tabular-nums">{{
                formatPriceFull(order.total_price) }}</div>
              <span :class="`badge-${order.status}`">{{ orderStatusLabel[order.status] || order.status }}</span>
            </div>
          </RouterLink>
        </div>
      </div>
    </div>

    <!-- Quick actions -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <RouterLink to="/products/new"
        class="card hover:border-primary-300 dark:hover:border-primary-700 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
          <div
            class="w-10 h-10 bg-primary-100 dark:bg-primary-900/30 rounded-xl flex items-center justify-center group-hover:bg-primary-200 transition-colors flex-shrink-0">
            <svg class="w-5 h-5 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor"
              viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
          </div>
          <div class="min-w-0">
            <div class="font-medium text-gray-900 dark:text-white text-sm">Добавить товар</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ productsStore.activeProducts.length }} активных
            </div>
          </div>
        </div>
      </RouterLink>

      <RouterLink to="/analytics"
        class="card hover:border-violet-300 dark:hover:border-violet-700 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
          <div
            class="w-10 h-10 bg-violet-100 dark:bg-violet-900/30 rounded-xl flex items-center justify-center group-hover:bg-violet-200 transition-colors flex-shrink-0">
            <svg class="w-5 h-5 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor"
              viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
          </div>
          <div class="min-w-0">
            <div class="font-medium text-gray-900 dark:text-white text-sm">Аналитика</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Выручка и статистика</div>
          </div>
        </div>
      </RouterLink>

      <RouterLink to="/masters"
        class="card hover:border-gray-300 dark:hover:border-gray-600 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
          <div
            class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-xl flex items-center justify-center group-hover:bg-gray-200 transition-colors flex-shrink-0">
            <svg class="w-5 h-5 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
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
