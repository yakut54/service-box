<script setup lang="ts">
import { onMounted, onUnmounted, computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useOrdersStore } from '@/stores/orders'
import { useProductsStore } from '@/stores/products'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'

const authStore = useAuthStore()
const ordersStore = useOrdersStore()
const productsStore = useProductsStore()

// ── Period switcher ──────────────────────────────────────────
type Period = 'today' | 'week' | 'month'
const period = ref<Period>('month')

const periodLabels: Record<Period, string> = {
  today: 'Сегодня',
  week: 'Неделя',
  month: 'Месяц',
}

const chartDays: Record<Period, number> = {
  today: 7,
  week: 7,
  month: 30,
}

// ── Stats ────────────────────────────────────────────────────
const stats = ref<any>({})
const loadingStats = ref(false)

async function loadStats() {
  loadingStats.value = true
  try {
    stats.value = await api.getOrderStats({ period: period.value })
  } catch { /* ignore */ }
  loadingStats.value = false
}

// ── Chart ────────────────────────────────────────────────────
const chartData = ref<Array<{ date: string; orders: number; revenue: number }>>([])
const loadingChart = ref(false)

async function loadChart() {
  loadingChart.value = true
  try {
    const res = await api.getOrderChart(chartDays[period.value])
    chartData.value = res.data
  } catch { /* ignore */ }
  loadingChart.value = false
}

// ── Booking stats ─────────────────────────────────────────────
const bookingStats = ref<any>({})
const loadingBookingStats = ref(false)

async function loadBookingStats() {
  loadingBookingStats.value = true
  try {
    bookingStats.value = await api.getBookingStats()
  } catch { /* ignore */ }
  loadingBookingStats.value = false
}

const bookingStatusBreakdown = computed(() => {
  const total = bookingStats.value.total_bookings || 1
  return [
    { key: 'pending',   label: 'Ожидают',     color: 'bg-yellow-400', count: bookingStats.value.pending_bookings   || 0 },
    { key: 'confirmed', label: 'Подтверждены', color: 'bg-blue-500',   count: bookingStats.value.confirmed_bookings || 0 },
    { key: 'completed', label: 'Завершены',    color: 'bg-green-500',  count: bookingStats.value.completed_bookings || 0 },
    { key: 'cancelled', label: 'Отменены',     color: 'bg-red-400',    count: bookingStats.value.cancelled_bookings || 0 },
    { key: 'no_show',   label: 'Неявка',       color: 'bg-gray-400',   count: bookingStats.value.no_show_bookings   || 0 },
  ].map(s => ({ ...s, pct: Math.round((s.count / total) * 100) }))
})

// ── Today's bookings ─────────────────────────────────────────
const todayBookings = ref<any[]>([])

async function loadTodayBookings() {
  try {
    const now = new Date()
    const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
    const res = await api.getBookings({ date: today })
    todayBookings.value = (res.data || []).slice(0, 5)
  } catch { /* ignore */ }
}

// ── Chart container width for adaptive X-axis ────────────────
const chartContainerWidth = ref(400)
const chartContainerRef = ref<HTMLElement | null>(null)

function updateChartWidth() {
  if (chartContainerRef.value) {
    chartContainerWidth.value = chartContainerRef.value.offsetWidth
  }
}

// ── CSS Bar Chart ─────────────────────────────────────────────
const chartPoints = computed(() => {
  const data = chartData.value
  if (!data.length) return { bars: [], xLabels: [], maxRevenue: 0 }

  const maxRevenue = Math.max(...data.map(d => d.revenue), 1)

  const bars = data.map(d => ({
    date: d.date,
    revenue: d.revenue,
    orders: d.orders,
    heightPct: (d.revenue / maxRevenue) * 100,
    label: new Date(d.date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }),
  }))

  // Adaptive step: estimate how many labels fit based on container width
  // Narrow screens (≤480px): use compact "dd.mm" format (~32px/label)
  // Wide screens: use "20 февр." format (~44px/label)
  const width = chartContainerWidth.value
  const isNarrow = width < 480
  const labelWidth = isNarrow ? 32 : 44
  const maxLabels = Math.max(3, Math.floor(width / labelWidth))
  const step = Math.ceil(data.length / maxLabels)

  const indices = new Set<number>()
  for (let i = 0; i < data.length; i += step) indices.add(i)
  // Add last only if it won't collide with the previous label
  const lastIdx = data.length - 1
  const prevLastIdx = Math.floor(lastIdx / step) * step
  if (lastIdx - prevLastIdx >= Math.ceil(step / 2)) indices.add(lastIdx)

  const xLabels = [...indices].sort((a, b) => a - b).map(i => ({
    label: isNarrow
        ? new Date(data[i].date).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
        : new Date(data[i].date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }),
    leftPct: ((i + 0.5) / data.length) * 100,
  }))

  return { bars, xLabels, maxRevenue }
})

// Y-axis labels
const yLabels = computed(() => {
  const max = chartPoints.value.maxRevenue
  if (!max) return []
  return [1, 0.75, 0.5, 0.25, 0].map(frac => ({
    pct: (1 - frac) * 100,
    label: frac > 0 ? formatPrice(max * frac) : '0',
  }))
})

// ── Helpers ──────────────────────────────────────────────────
function formatPrice(kopecks: number): string {
  const rubles = kopecks / 100
  if (rubles >= 1_000_000) return (rubles / 1_000_000).toFixed(1) + 'M ₽'
  if (rubles >= 1_000) return Math.round(rubles / 1_000) + 'K ₽'
  return Math.round(rubles) + ' ₽'
}

function formatPriceFull(kopecks: number): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(kopecks / 100)
}

function growthIcon(cur: number, prev: number) {
  if (!prev) return null
  return cur >= prev ? 'up' : 'down'
}

function growthPct(cur: number, prev: number): string {
  if (!prev) return ''
  const pct = Math.round(((cur - prev) / prev) * 100)
  return (pct > 0 ? '+' : '') + pct + '%'
}

function formatTime(dateStr: string) {
  return new Date(dateStr).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

const bookingStatusLabel: Record<string, string> = {
  pending: 'Ожидает', confirmed: 'Подтверждена', completed: 'Завершена',
  cancelled: 'Отменена', no_show: 'Неявка',
}


// ── Axis label font size: 8px on narrow+month, else 10px ────
const axisLabelFontSize = computed(() =>
    chartContainerWidth.value < 480 && period.value === 'month' ? '8px' : '10px'
)

onUnmounted(() => {
  resizeObserver?.disconnect()
})

const statusTab = ref<'orders' | 'bookings'>('orders')

const statusBlockTitle = computed(() =>
    statusTab.value === 'orders' ? 'Статусы заказов' : 'Статусы записей'
)

// ── Order status breakdown ────────────────────────────────────
const statusBreakdown = computed(() => {
  const total = stats.value.total_orders || 1 // avoid div/0
  return [
    { key: 'pending',    label: 'Ожидают',   color: 'bg-yellow-400', count: stats.value.pending_orders   || 0 },
    { key: 'paid',       label: 'Оплачены',  color: 'bg-blue-500',   count: stats.value.paid_orders      || 0 },
    { key: 'completed',  label: 'Завершены', color: 'bg-green-500',  count: stats.value.completed_orders || 0 },
    { key: 'cancelled',  label: 'Отменены',  color: 'bg-red-400',    count: stats.value.cancelled_orders || 0 },
  ].map(s => ({ ...s, pct: Math.round((s.count / total) * 100) }))
})

// ── Top products by revenue ───────────────────────────────────
const topProducts = computed(() => {
  const map = new Map<string, { name: string; revenue: number; count: number }>()
  for (const order of ordersStore.orders) {
    if (order.status === 'cancelled') continue
    for (const item of (order.items || [])) {
      const key = item.product_name
      const existing = map.get(key) || { name: key, revenue: 0, count: 0 }
      existing.revenue += (item.price || 0) * (item.quantity || 1)
      existing.count   += item.quantity || 1
      map.set(key, existing)
    }
  }
  return [...map.values()]
      .sort((a, b) => b.revenue - a.revenue)
      .slice(0, 5)
})

// ── Init ─────────────────────────────────────────────────────
const recentOrders = computed(() => ordersStore.orders.slice(0, 5))

watch(period, () => {
  loadStats()
  loadChart()
})

let resizeObserver: ResizeObserver | null = null

onMounted(async () => {
  await Promise.all([
    ordersStore.fetchOrders(),
    productsStore.fetchProducts(),
    loadStats(),
    loadChart(),
    loadTodayBookings(),
    loadBookingStats(),
  ])

  // Observe chart container width for adaptive X-axis labels
  if (chartContainerRef.value) {
    updateChartWidth()
    resizeObserver = new ResizeObserver(updateChartWidth)
    resizeObserver.observe(chartContainerRef.value)
  }
})

</script>

<template>
  <div class="space-y-6">

    <!-- Header + period switcher -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ authStore.shop?.name }}</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-0.5 text-sm">Сводка по магазину</p>
      </div>
      <div class="flex items-center bg-gray-100 dark:bg-gray-800 rounded-lg p-1 gap-1">
        <button
            v-for="p in (['today', 'week', 'month'] as Period[])"
            :key="p"
            @click="period = p"
            :class="[
            'px-3 py-1.5 rounded-md text-sm font-medium transition-all',
            period === p
              ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
              : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'
          ]"
        >{{ periodLabels[p] }}</button>
      </div>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">

      <!-- Revenue -->
      <div class="card">
        <p class="text-sm text-gray-500 dark:text-gray-400">Выручка</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
          {{ loadingStats ? '...' : formatPriceFull(stats.total_revenue || 0) }}
        </p>
        <div v-if="stats.prev_revenue !== undefined" class="flex items-center gap-1 mt-1">
          <span :class="['text-xs font-medium', growthIcon(stats.total_revenue, stats.prev_revenue) === 'up' ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400']">
            {{ growthPct(stats.total_revenue, stats.prev_revenue) }}
          </span>
          <span class="text-xs text-gray-400">vs прошлый период</span>
        </div>
        <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center mt-3">
          <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
      </div>

      <!-- Orders -->
      <div class="card">
        <p class="text-sm text-gray-500 dark:text-gray-400">Заказов</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
          {{ loadingStats ? '...' : stats.total_orders || 0 }}
        </p>
        <div v-if="stats.prev_orders !== undefined" class="flex items-center gap-1 mt-1">
          <span :class="['text-xs font-medium', growthIcon(stats.total_orders, stats.prev_orders) === 'up' ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400']">
            {{ growthPct(stats.total_orders, stats.prev_orders) }}
          </span>
          <span class="text-xs text-gray-400">vs прошлый период</span>
        </div>
        <div class="w-10 h-10 bg-primary-100 dark:bg-primary-900/30 rounded-xl flex items-center justify-center mt-3">
          <svg class="w-5 h-5 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
          </svg>
        </div>
      </div>

      <!-- Pending -->
      <div class="card">
        <p class="text-sm text-gray-500 dark:text-gray-400">Ожидают</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
          {{ loadingStats ? '...' : stats.pending_orders || 0 }}
        </p>
        <p class="text-xs text-gray-400 mt-1">требуют обработки</p>
        <div class="w-10 h-10 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl flex items-center justify-center mt-3">
          <svg class="w-5 h-5 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
      </div>

      <!-- Products -->
      <div class="card">
        <p class="text-sm text-gray-500 dark:text-gray-400">Товаров/услуг</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ productsStore.activeProducts.length }}</p>
        <p class="text-xs text-gray-400 mt-1">активных</p>
        <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center mt-3">
          <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
        </div>
      </div>
    </div>

    <!-- Chart row: left panel + chart -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

      <!-- ── Left panel ───────────────────────────────────────── -->
      <div class="lg:col-span-2 flex flex-col gap-4">

        <!-- Status breakdown (orders / bookings) -->
        <div class="card flex-1">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white transition-all duration-200">{{ statusBlockTitle }}</h2>
            <div class="flex items-center bg-gray-100 dark:bg-gray-800 rounded-lg p-0.5 gap-0.5">
              <button
                  @click="statusTab = 'orders'"
                  :class="['px-2.5 py-1 rounded-md text-xs font-medium transition-all', statusTab === 'orders' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700']"
                  :title="statusTab === 'orders' ? 'Сейчас: Заказы' : 'Переключить на Заказы'"
              >Заказы</button>
              <button
                  @click="statusTab = 'bookings'"
                  :class="['px-2.5 py-1 rounded-md text-xs font-medium transition-all', statusTab === 'bookings' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700']"
                  :title="statusTab === 'bookings' ? 'Сейчас: Записи' : 'Переключить на Записи'"
              >Записи</button>
            </div>
          </div>

          <!-- Orders tab -->
          <template v-if="statusTab === 'orders'">
            <div v-if="loadingStats" class="space-y-3">
              <div v-for="i in 4" :key="i" class="h-5 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
            </div>
            <div v-else-if="!stats.total_orders" class="py-4 text-sm text-gray-400 text-center">Нет заказов за период</div>
            <div v-else class="space-y-3">
              <div v-for="s in statusBreakdown" :key="s.key" class="flex items-center gap-3">
                <div :class="['w-2.5 h-2.5 rounded-full flex-shrink-0', s.color]"/>
                <span class="text-sm text-gray-600 dark:text-gray-400 w-24 flex-shrink-0">{{ s.label }}</span>
                <div class="flex-1 h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                  <div :class="['h-full rounded-full transition-all duration-500', s.color]" :style="`width: ${s.pct}%`"/>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white w-6 text-right flex-shrink-0">{{ s.count }}</span>
              </div>
              <div class="pt-2 mt-1 border-t border-gray-100 dark:border-gray-800 flex justify-between text-xs text-gray-400">
                <span>Всего за период</span>
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ stats.total_orders }}</span>
              </div>
            </div>
          </template>

          <!-- Bookings tab -->
          <template v-else>
            <div v-if="loadingBookingStats" class="space-y-3">
              <div v-for="i in 5" :key="i" class="h-5 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
            </div>
            <div v-else-if="!bookingStats.total_bookings" class="py-4 text-sm text-gray-400 text-center">Нет записей</div>
            <div v-else class="space-y-3">
              <div v-for="s in bookingStatusBreakdown" :key="s.key" class="flex items-center gap-3">
                <div :class="['w-2.5 h-2.5 rounded-full flex-shrink-0', s.color]"/>
                <span class="text-sm text-gray-600 dark:text-gray-400 w-24 flex-shrink-0">{{ s.label }}</span>
                <div class="flex-1 h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                  <div :class="['h-full rounded-full transition-all duration-500', s.color]" :style="`width: ${s.pct}%`"/>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white w-6 text-right flex-shrink-0">{{ s.count }}</span>
              </div>
              <div class="pt-2 mt-1 border-t border-gray-100 dark:border-gray-800 flex justify-between text-xs text-gray-400">
                <span>Всего записей</span>
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ bookingStats.total_bookings }}</span>
              </div>
            </div>
          </template>
        </div>

        <!-- Top products -->
        <div class="card flex-1">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Топ товаров</h2>

          <div v-if="ordersStore.loading" class="space-y-3">
            <div v-for="i in 4" :key="i" class="h-5 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
          </div>

          <div v-else-if="topProducts.length === 0" class="py-4 text-sm text-gray-400 text-center">
            Нет данных
          </div>

          <div v-else class="space-y-3">
            <div
                v-for="(p, idx) in topProducts"
                :key="p.name"
                class="flex items-center gap-2"
            >
              <span class="text-xs font-semibold text-gray-300 dark:text-gray-600 w-4 text-right flex-shrink-0">{{ idx + 1 }}</span>
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between mb-0.5 gap-2">
                  <span class="text-sm text-gray-700 dark:text-gray-300 truncate">{{ p.name }}</span>
                  <span class="text-xs font-semibold text-gray-900 dark:text-white flex-shrink-0">{{ formatPrice(p.revenue) }}</span>
                </div>
                <div class="h-1 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                  <div
                      class="h-full bg-primary-500 dark:bg-primary-400 rounded-full transition-all duration-500"
                      :style="`width: ${Math.round((p.revenue / topProducts[0].revenue) * 100)}%`"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- ── Revenue Chart ─────────────────────────────────────── -->
      <div ref="chartContainerRef" class="lg:col-span-3 card flex flex-col">
        <div class="flex items-center justify-between mb-3 flex-shrink-0">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Выручка по дням</h2>
          <span class="text-xs text-gray-400">последние {{ chartDays[period] }} {{ plural(chartDays[period], 'день', 'дня', 'дней') }}</span>
        </div>

        <div v-if="loadingChart" class="flex-1 flex items-center justify-center text-gray-400">
          Загрузка...
        </div>

        <div v-else-if="chartData.every(d => d.revenue === 0)" class="flex-1 flex items-center justify-center text-gray-400 text-sm">
          Нет данных за период
        </div>

        <div v-else class="flex-1 flex flex-col min-h-0">
          <!-- Chart area -->
          <div class="flex gap-2 h-48">
            <!-- Y-axis labels -->
            <div class="w-8 sm:w-10 flex-shrink-0 relative">
              <span
                  v-for="yl in yLabels" :key="yl.pct"
                  class="absolute right-0 leading-none text-gray-400 dark:text-gray-600 pr-1"
                  :style="`top: ${yl.pct}%; transform: translateY(-50%); font-size: ${axisLabelFontSize}`"
              >{{ yl.label }}</span>
            </div>
            <!-- Bars + grid -->
            <div class="flex-1 relative">
              <!-- Grid lines -->
              <div
                  v-for="yl in yLabels" :key="`g-${yl.pct}`"
                  class="absolute left-0 right-0 border-t border-gray-100 dark:border-gray-800 pointer-events-none"
                  :style="`top: ${yl.pct}%`"
              />
              <!-- Bars: items-stretch (default) so each column is full height, bar grows from bottom via flex-col justify-end -->
              <div class="absolute inset-0 flex gap-px">
                <div
                    v-for="bar in chartPoints.bars" :key="bar.date"
                    class="flex-1 min-w-0 relative group cursor-default flex flex-col justify-end"
                >
                  <!-- CSS hover tooltip -->
                  <div class="absolute bottom-full mb-1.5 left-1/2 -translate-x-1/2 z-20 pointer-events-none
                    opacity-0 group-hover:opacity-100 transition-opacity duration-150
                    bg-gray-900 dark:bg-gray-700 text-white rounded-lg px-2.5 py-1.5 text-xs whitespace-nowrap shadow-lg">
                    <div class="font-medium mb-0.5">{{ bar.label }}</div>
                    <div class="text-primary-300">{{ formatPriceFull(bar.revenue) }}</div>
                    <div class="text-gray-400">{{ bar.orders }} {{ plural(bar.orders, 'заказ', 'заказа', 'заказов') }}</div>
                  </div>
                  <!-- Bar -->
                  <div
                      class="w-full rounded-t-sm bg-primary-500 dark:bg-primary-400 group-hover:bg-primary-600 dark:group-hover:bg-primary-300 transition-colors"
                      :style="`height: ${bar.heightPct}%; min-height: ${bar.revenue > 0 ? 3 : 0}px`"
                  />
                </div>
              </div>
            </div>
          </div>
          <!-- X-axis labels -->
          <div class="relative h-5 ml-8 sm:ml-12 mt-1 flex-shrink-0">
            <span
                v-for="xl in chartPoints.xLabels" :key="xl.leftPct"
                class="absolute leading-none text-gray-400 dark:text-gray-600 -translate-x-1/2"
                :style="`left: ${xl.leftPct}%; font-size: ${axisLabelFontSize}`"
            >{{ xl.label }}</span>
          </div>

          <!-- Summary row below the chart -->
          <div class="flex-shrink-0 mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 grid grid-cols-3 gap-4">
            <div>
              <p class="text-xs text-gray-400 mb-1">Всего выручка</p>
              <p class="text-base font-bold text-gray-900 dark:text-white">{{ formatPriceFull(stats.total_revenue || 0) }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-400 mb-1">Заказов</p>
              <p class="text-base font-bold text-gray-900 dark:text-white">{{ stats.total_orders || 0 }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-400 mb-1">Средний чек</p>
              <p class="text-base font-bold text-gray-900 dark:text-white">{{ formatPriceFull(stats.average_order_value || 0) }}</p>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- Bottom row: today bookings + recent orders -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Today's bookings -->
      <div class="card">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Записи на сегодня</h2>
          <RouterLink to="/bookings" class="text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700">
            Все записи &rarr;
          </RouterLink>
        </div>

        <div v-if="todayBookings.length === 0" class="py-6 text-center text-gray-400 text-sm">
          Нет записей на сегодня
        </div>

        <div v-else class="space-y-1">
          <RouterLink
              v-for="b in todayBookings"
              :key="b.id"
              :to="`/bookings/${b.id}`"
              :class="[
                'flex items-center justify-between py-2 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors border-b border-gray-50 dark:border-gray-800 last:border-0',
                (b.status === 'cancelled' || b.status === 'no_show') ? 'opacity-40' : ''
              ]"
          >
            <div class="flex items-center gap-3">
              <div class="text-sm font-medium text-gray-700 dark:text-gray-300 w-12 shrink-0">
                {{ formatTime(b.start_time) }}
              </div>
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
            Все заказы &rarr;
          </RouterLink>
        </div>

        <div v-if="ordersStore.loading" class="py-6 text-center text-gray-400 text-sm">Загрузка...</div>
        <div v-else-if="recentOrders.length === 0" class="py-6 text-center text-gray-400 text-sm">Нет заказов</div>

        <div v-else class="space-y-1">
          <RouterLink
              v-for="order in recentOrders"
              :key="order.id"
              :to="`/orders/${order.id}`"
              :class="[
                'flex items-center justify-between py-2 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors border-b border-gray-50 dark:border-gray-800 last:border-0',
                order.status === 'cancelled' ? 'opacity-40' : ''
              ]"
          >
            <div>
              <div class="text-sm font-medium text-primary-600 dark:text-primary-400">#{{ order.id.slice(0, 8) }}</div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ order.customer_name }}</div>
            </div>
            <div class="text-right">
              <div class="text-sm font-medium text-gray-900 dark:text-white">{{ formatPriceFull(order.total_price) }}</div>
              <span :class="`badge-${order.status}`">
                {{ order.status === 'pending' ? 'Ожидает' : order.status === 'paid' ? 'Оплачен' : order.status === 'processing' ? 'В работе' : order.status === 'completed' ? 'Завершён' : 'Отменён' }}
              </span>
            </div>
          </RouterLink>
        </div>
      </div>
    </div>

    <!-- Quick actions -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <RouterLink to="/products/new" class="card hover:border-primary-300 dark:hover:border-primary-700 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-primary-100 dark:bg-primary-900/30 rounded-xl flex items-center justify-center group-hover:bg-primary-200 dark:group-hover:bg-primary-800/50 transition-colors">
            <svg class="w-5 h-5 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
          </div>
          <div>
            <div class="font-medium text-gray-900 dark:text-white text-sm">Добавить товар</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Создайте товар или услугу</div>
          </div>
        </div>
      </RouterLink>

      <RouterLink to="/orders" class="card hover:border-yellow-300 dark:hover:border-yellow-700 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl flex items-center justify-center group-hover:bg-yellow-200 dark:group-hover:bg-yellow-900/50 transition-colors">
            <svg class="w-5 h-5 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
          </div>
          <div>
            <div class="font-medium text-gray-900 dark:text-white text-sm">Заказы</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ ordersStore.pendingOrders.length }} {{ ordersStore.pendingOrders.length === 1 ? 'ожидает' : 'ожидают' }}</div>
          </div>
        </div>
      </RouterLink>

      <RouterLink to="/masters" class="card hover:border-gray-300 dark:hover:border-gray-600 hover:shadow-md transition-all group">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-xl flex items-center justify-center group-hover:bg-gray-200 dark:group-hover:bg-gray-600 transition-colors">
            <svg class="w-5 h-5 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </div>
          <div>
            <div class="font-medium text-gray-900 dark:text-white text-sm">Мастера</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Управление персоналом</div>
          </div>
        </div>
      </RouterLink>
    </div>

  </div>
</template>
