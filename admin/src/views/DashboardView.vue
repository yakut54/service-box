<script setup lang="ts">
import { onMounted, onBeforeUnmount, computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useOrdersStore } from '@/stores/orders'
import { useProductsStore } from '@/stores/products'
import { api } from '@/lib/api'

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
  today: 7,   // show last 7 days when in "today" mode
  week: 14,
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

// ── Today's bookings ─────────────────────────────────────────
const todayBookings = ref<any[]>([])

async function loadTodayBookings() {
  try {
    const today = new Date().toISOString().split('T')[0]
    const res = await api.getBookings({ date: today })
    todayBookings.value = (res.data || []).slice(0, 8)
  } catch { /* ignore */ }
}

// ── SVG Chart ────────────────────────────────────────────────
const CHART_W   = 600
const PADDING   = { top: 16, right: 10, bottom: 28, left: 48 }

// Dynamic height — fills the card's available space via ResizeObserver
const chartContainer    = ref<HTMLElement | null>(null)
const CHART_H           = ref(160)
let chartResizeObserver: ResizeObserver | null = null

const chartPoints = computed(() => {
  const data = chartData.value
  if (!data.length) return { bars: [], labels: [], maxRevenue: 0 }

  const maxRevenue = Math.max(...data.map(d => d.revenue), 1)
  const innerW = CHART_W - PADDING.left - PADDING.right
  const innerH = CHART_H.value - PADDING.top - PADDING.bottom
  const barW = Math.max(2, innerW / data.length - 2)

  const bars = data.map((d, i) => {
    const x = PADDING.left + (i / data.length) * innerW + 1
    const h = (d.revenue / maxRevenue) * innerH
    const y = PADDING.top + innerH - h
    return { x, y, w: barW, h: Math.max(h, d.revenue > 0 ? 2 : 0), date: d.date, revenue: d.revenue, orders: d.orders }
  })

  // Show labels every N days
  const step = data.length <= 14 ? 2 : data.length <= 30 ? 5 : 10
  const labels = data
    .map((d, i) => ({ i, date: d.date }))
    .filter((_, i) => i % step === 0 || i === data.length - 1)
    .map(({ i, date }) => ({
      x: PADDING.left + (i / data.length) * innerW + barW / 2,
      label: new Date(date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }),
    }))

  return { bars, labels, maxRevenue }
})

// Y-axis labels
const yLabels = computed(() => {
  const max = chartPoints.value.maxRevenue
  if (!max) return []
  const innerH = CHART_H.value - PADDING.top - PADDING.bottom
  return [0, 0.25, 0.5, 0.75, 1].map(frac => ({
    y: PADDING.top + innerH * (1 - frac),
    label: formatPrice(max * frac),
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

// Tooltip
const tooltip = ref<{ x: number; y: number; bar: any } | null>(null)

function showTooltip(e: MouseEvent, bar: any) {
  tooltip.value = { x: e.offsetX, y: e.offsetY, bar }
}

function hideTooltip() {
  tooltip.value = null
}

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

onMounted(async () => {
  await Promise.all([
    ordersStore.fetchOrders(),
    productsStore.fetchProducts(),
    loadStats(),
    loadChart(),
    loadTodayBookings(),
  ])

  // Start observing chart container height after data loads
  if (chartContainer.value) {
    chartResizeObserver = new ResizeObserver(entries => {
      for (const entry of entries) {
        const h = entry.contentRect.height
        if (h > 60) CHART_H.value = h
      }
    })
    chartResizeObserver.observe(chartContainer.value)
  }
})

onBeforeUnmount(() => {
  chartResizeObserver?.disconnect()
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
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

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

        <!-- Order status breakdown -->
        <div class="card flex-1">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Статусы заказов</h2>

          <div v-if="loadingStats" class="space-y-3">
            <div v-for="i in 4" :key="i" class="h-5 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
          </div>

          <div v-else-if="!stats.total_orders" class="py-4 text-sm text-gray-400 text-center">
            Нет заказов за период
          </div>

          <div v-else class="space-y-3">
            <div v-for="s in statusBreakdown" :key="s.key" class="flex items-center gap-3">
              <div :class="['w-2.5 h-2.5 rounded-full flex-shrink-0', s.color]"/>
              <span class="text-sm text-gray-600 dark:text-gray-400 w-24 flex-shrink-0">{{ s.label }}</span>
              <div class="flex-1 h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div
                  :class="['h-full rounded-full transition-all duration-500', s.color]"
                  :style="`width: ${s.pct}%`"
                />
              </div>
              <span class="text-sm font-semibold text-gray-900 dark:text-white w-6 text-right flex-shrink-0">{{ s.count }}</span>
            </div>

            <!-- Total -->
            <div class="pt-2 mt-1 border-t border-gray-100 dark:border-gray-800 flex justify-between text-xs text-gray-400">
              <span>Всего за период</span>
              <span class="font-medium text-gray-700 dark:text-gray-300">{{ stats.total_orders }}</span>
            </div>
          </div>
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
      <div class="lg:col-span-3 card flex flex-col">
        <div class="flex items-center justify-between mb-3 flex-shrink-0">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Выручка по дням</h2>
          <span class="text-xs text-gray-400">последние {{ chartDays[period] }} дней</span>
        </div>

        <div v-if="loadingChart" class="flex-1 flex items-center justify-center text-gray-400">
          Загрузка...
        </div>

        <div v-else-if="chartData.every(d => d.revenue === 0)" class="flex-1 flex items-center justify-center text-gray-400 text-sm">
          Нет данных за период
        </div>

        <div v-else class="flex-1 flex flex-col min-h-0">
          <!-- Chart area — grows to fill available space -->
          <div ref="chartContainer" class="flex-1 relative min-h-[120px]" @mouseleave="hideTooltip">
            <svg
              :viewBox="`0 0 ${CHART_W} ${CHART_H}`"
              class="w-full h-full"
              preserveAspectRatio="none"
              @mousemove.stop
            >
              <line
                v-for="yl in yLabels" :key="yl.y"
                :x1="PADDING.left" :y1="yl.y"
                :x2="CHART_W - PADDING.right" :y2="yl.y"
                stroke="currentColor" class="text-gray-100 dark:text-gray-800" stroke-width="1"
              />
              <text
                v-for="yl in yLabels" :key="`yl-${yl.y}`"
                :x="PADDING.left - 4" :y="yl.y + 4"
                text-anchor="end" class="fill-gray-400" style="font-size: 7px"
                vector-effect="non-scaling-stroke"
              >{{ yl.label }}</text>
              <rect
                v-for="bar in chartPoints.bars" :key="bar.date"
                :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h" rx="2"
                class="fill-primary-500 dark:fill-primary-400 hover:fill-primary-600 dark:hover:fill-primary-300 transition-colors cursor-pointer"
                @mouseenter="showTooltip($event, bar)"
              />
              <text
                v-for="xl in chartPoints.labels" :key="`xl-${xl.x}`"
                :x="xl.x" :y="CHART_H.valueOf() - 4"
                text-anchor="middle" class="fill-gray-400" style="font-size: 7px"
                vector-effect="non-scaling-stroke"
              >{{ xl.label }}</text>
            </svg>

            <div
              v-if="tooltip"
              class="absolute pointer-events-none z-10 bg-gray-900 dark:bg-gray-700 text-white text-xs rounded-lg px-3 py-2 shadow-lg"
              :style="`left: ${Math.min(tooltip.x, 400)}px; top: ${Math.max(tooltip.y - 60, 4)}px; transform: translateX(-50%)`"
            >
              <div class="font-medium">{{ new Date(tooltip.bar.date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) }}</div>
              <div class="text-primary-300">{{ formatPriceFull(tooltip.bar.revenue) }}</div>
              <div class="text-gray-300">{{ tooltip.bar.orders }} заказов</div>
            </div>
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

        <div v-else class="space-y-2">
          <div
            v-for="b in todayBookings"
            :key="b.id"
            class="flex items-center justify-between py-2 border-b border-gray-50 dark:border-gray-800 last:border-0"
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
          </div>
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

        <div v-else class="space-y-2">
          <div
            v-for="order in recentOrders"
            :key="order.id"
            class="flex items-center justify-between py-2 border-b border-gray-50 dark:border-gray-800 last:border-0"
          >
            <div>
              <RouterLink :to="`/orders/${order.id}`" class="text-sm font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700">
                #{{ order.id.slice(0, 8) }}
              </RouterLink>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ order.customer_name }}</div>
            </div>
            <div class="text-right">
              <div class="text-sm font-medium text-gray-900 dark:text-white">{{ formatPriceFull(order.total_price) }}</div>
              <span :class="`badge-${order.status}`">
                {{ order.status === 'pending' ? 'Ожидает' : order.status === 'paid' ? 'Оплачен' : order.status === 'processing' ? 'В работе' : order.status === 'completed' ? 'Завершён' : 'Отменён' }}
              </span>
            </div>
          </div>
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
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ ordersStore.pendingOrders.length }} ожидают</div>
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
