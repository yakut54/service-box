<script setup lang="ts">
import { onMounted, computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useOrdersStore } from '@/stores/orders'
import { useProductsStore } from '@/stores/products'
import { useReviewsStore } from '@/stores/reviews'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'
import KpiCard from '@/components/KpiCard.vue'
import RevenueChart from '@/components/RevenueChart.vue'
import type { OrderStats } from '@/types'

const authStore = useAuthStore()
const ordersStore = useOrdersStore()
const productsStore = useProductsStore()
const reviewsStore = useReviewsStore()

const todayStats = ref<Partial<OrderStats>>({})
const weekStats  = ref<Partial<OrderStats>>({})
const loadingStats = ref(false)
const chartData = ref<Array<{ date: string; orders: number; revenue: number }>>([])
const loadingChart = ref(false)

async function loadStats() {
  loadingStats.value = true
  try {
    const [today, week] = await Promise.all([
      api.getOrderStats({ period: 'today' }),
      api.getOrderStats({ period: 'week' }),
    ])
    todayStats.value = today
    weekStats.value  = week
  } catch { /* ignore */ }
  loadingStats.value = false
}

function pct(current: number, prev: number): number | null {
  if (!prev) return null
  return Math.round(((current - prev) / prev) * 100)
}

async function loadChart() {
  loadingChart.value = true
  try { chartData.value = (await api.getOrderChart(7)).data }
  catch { /* ignore */ }
  loadingChart.value = false
}

const pendingOrders = computed(() => ordersStore.pendingOrders)
const pendingReviewsCount = computed(() => reviewsStore.pendingCount)

const recentOrders = computed(() => ordersStore.orders.slice(0, 5))

function formatPriceFull(kopecks: number) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(Math.round(kopecks / 100))
}

function formatPrice(kopecks: number) {
  const rubles = kopecks / 100
  if (rubles >= 1_000_000) return (rubles / 1_000_000).toFixed(1) + 'M ₽'
  if (rubles >= 1_000) return Math.round(rubles / 1_000) + 'K ₽'
  return Math.round(rubles) + ' ₽'
}

const orderStatusLabel: Record<string, string> = {
  pending: 'Ожидает', paid: 'Оплачен', processing: 'В работе', completed: 'Завершён', cancelled: 'Отменён',
}

onMounted(() => Promise.all([
  ordersStore.fetchOrders(),
  productsStore.fetchProducts(),
  loadStats(),
  loadChart(),
  reviewsStore.fetchPendingCount(),
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
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
      <KpiCard title="Выручка сегодня" :value="formatPrice(todayStats.total_revenue ?? 0)" :loading="loadingStats"
        color="green" icon="revenue" />
      <KpiCard title="Заказов сегодня" :value="String(todayStats.total_orders ?? 0)" :loading="loadingStats"
        color="blue" icon="orders" />
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

    <!-- Pending reviews alert -->
    <RouterLink v-if="pendingReviewsCount > 0" to="/reviews"
      class="flex items-center gap-3 p-4 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-xl hover:bg-purple-100 dark:hover:bg-purple-900/30 transition-colors">
      <span class="relative flex h-3 w-3 flex-shrink-0">
        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-purple-400 opacity-75" />
        <span class="relative inline-flex rounded-full h-3 w-3 bg-purple-500" />
      </span>
      <p class="text-sm font-medium text-purple-800 dark:text-purple-300">
        {{ pendingReviewsCount }} {{ plural(pendingReviewsCount, 'отзыв ждёт', 'отзыва ждут', 'отзывов ждут') }}
        модерации
      </p>
      <span class="ml-auto text-xs text-purple-600 dark:text-purple-400">Открыть →</span>
    </RouterLink>

    <!-- Upcoming bookings + Chart -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Left: Week vs prev week -->
      <div class="card flex flex-col gap-5">
        <div class="flex items-center justify-between">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Эта неделя</h2>
          <RouterLink to="/analytics" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700">Аналитика →</RouterLink>
        </div>
        <div v-if="loadingStats" class="space-y-4">
          <div v-for="i in 2" :key="i" class="h-16 bg-gray-100 dark:bg-gray-800 rounded-lg animate-pulse" />
        </div>
        <template v-else>
          <!-- Revenue -->
          <div class="flex items-center justify-between py-3 border-b border-gray-100 dark:border-gray-800">
            <div>
              <div class="text-xs text-gray-400 dark:text-gray-500 mb-0.5">Выручка</div>
              <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">{{ formatPrice(weekStats.total_revenue ?? 0) }}</div>
              <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">прошлая: {{ formatPrice(weekStats.prev_revenue ?? 0) }}</div>
            </div>
            <div v-if="pct(weekStats.total_revenue ?? 0, weekStats.prev_revenue ?? 0) !== null"
              :class="['text-sm font-semibold px-2.5 py-1 rounded-full', (weekStats.total_revenue ?? 0) >= (weekStats.prev_revenue ?? 0) ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400']">
              {{ (weekStats.total_revenue ?? 0) >= (weekStats.prev_revenue ?? 0) ? '+' : '' }}{{ pct(weekStats.total_revenue ?? 0, weekStats.prev_revenue ?? 0) }}%
            </div>
          </div>
          <!-- Orders -->
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-gray-400 dark:text-gray-500 mb-0.5">Заказов</div>
              <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">{{ weekStats.total_orders ?? 0 }}</div>
              <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">прошлая: {{ weekStats.prev_orders ?? 0 }}</div>
            </div>
            <div v-if="pct(weekStats.total_orders ?? 0, weekStats.prev_orders ?? 0) !== null"
              :class="['text-sm font-semibold px-2.5 py-1 rounded-full', (weekStats.total_orders ?? 0) >= (weekStats.prev_orders ?? 0) ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400']">
              {{ (weekStats.total_orders ?? 0) >= (weekStats.prev_orders ?? 0) ? '+' : '' }}{{ pct(weekStats.total_orders ?? 0, weekStats.prev_orders ?? 0) }}%
            </div>
          </div>
        </template>
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

    <!-- Recent orders -->
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
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ order.customer?.name ?? order.customer_name }}</div>
          </div>
          <div class="text-right">
            <div class="text-sm font-semibold text-gray-900 dark:text-white tabular-nums">{{
              formatPriceFull(order.total_price) }}</div>
            <span :class="`badge-${order.status}`">{{ orderStatusLabel[order.status] || order.status }}</span>
          </div>
        </RouterLink>
      </div>
    </div>

    <!-- Quick actions -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
    </div>

  </div>
</template>
