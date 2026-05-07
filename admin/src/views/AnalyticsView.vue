<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'
import type { OrderStats, BookingStats, Customer } from '@/types'

// ── Period ───────────────────────────────────────────────────
type Period = '7d' | '30d'
const period = ref<Period>('30d')

const periodLabels: Record<Period, string> = { '7d': '7 дней', '30d': '30 дней' }
const periodToStats: Record<Period, string> = { '7d': 'week', '30d': 'month' }
const periodToDays:  Record<Period, number>  = { '7d': 7, '30d': 30 }

// ── Data ─────────────────────────────────────────────────────
const stats        = ref<Partial<OrderStats>>({})
const bookingStats = ref<Partial<BookingStats>>({})
const chartData    = ref<Array<{ date: string; orders: number; revenue: number }>>([])
const topProducts  = ref<Array<{ name: string; revenue: number; count: number }>>([])
const topCustomers = ref<Customer[]>([])

const loadingStats    = ref(false)
const loadingChart    = ref(false)
const loadingCustomers= ref(false)

async function loadAll() {
  loadingStats.value = true
  loadingChart.value = true
  loadingCustomers.value = true

  const [statsRes, chartRes, bookingRes, customersRes] = await Promise.allSettled([
    api.getOrderStats({ period: periodToStats[period.value] }),
    api.getOrderChart(periodToDays[period.value]),
    api.getBookingStats(),
    api.getCustomers(),
  ])

  if (statsRes.status    === 'fulfilled') stats.value        = statsRes.value
  if (chartRes.status    === 'fulfilled') chartData.value    = chartRes.value.data
  if (bookingRes.status  === 'fulfilled') bookingStats.value = bookingRes.value
  if (customersRes.status === 'fulfilled') {
    const all = customersRes.value.data ?? []
    topCustomers.value = [...all].sort((a, b) => b.total_spent - a.total_spent).slice(0, 5)

    // Build top products from orders endpoint data — re-derive from chart-period orders
    // We compute top products from all customer order history client-side
    // (already loaded via getCustomers which has total_spent; products need orders)
  }

  loadingStats.value = false
  loadingChart.value = false
  loadingCustomers.value = false

  // Top products — from a separate orders call
  try {
    const ordersRes = await api.getOrders()
    const map = new Map<string, { name: string; revenue: number; count: number }>()
    for (const order of ordersRes.data) {
      if (order.status === 'cancelled') continue
      for (const item of order.items ?? []) {
        const e = map.get(item.product_name) ?? { name: item.product_name, revenue: 0, count: 0 }
        e.revenue += (item.price ?? 0) * (item.quantity ?? 1)
        e.count   += item.quantity ?? 1
        map.set(item.product_name, e)
      }
    }
    topProducts.value = [...map.values()].sort((a, b) => b.revenue - a.revenue).slice(0, 5)
  } catch { /* ignore */ }
}

watch(period, loadAll)

// ── Chart ─────────────────────────────────────────────────────
const chartContainerRef   = ref<HTMLElement | null>(null)
const chartContainerWidth = ref(600)

function updateChartWidth() {
  if (chartContainerRef.value) chartContainerWidth.value = chartContainerRef.value.offsetWidth
}

const chartHasData = computed(() => chartData.value.some(d => d.revenue > 0))

const chartPoints = computed(() => {
  const data = chartData.value
  if (!data.length) return { bars: [], xLabels: [], maxRevenue: 0 }
  const maxRevenue = Math.max(...data.map(d => d.revenue), 1)
  const bars = data.map((d, i) => ({
    date: d.date, revenue: d.revenue, orders: d.orders,
    heightPct: (d.revenue / maxRevenue) * 100,
    label: new Date(d.date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }),
    idx: i, total: data.length,
  }))
  const width = chartContainerWidth.value
  const step  = Math.ceil(data.length / Math.max(3, Math.floor(width / 44)))
  const indices = new Set<number>()
  for (let i = 0; i < data.length; i += step) indices.add(i)
  if (data.length - 1 - Math.floor((data.length - 1) / step) * step >= Math.ceil(step / 2))
    indices.add(data.length - 1)
  const xLabels = [...indices].sort((a, b) => a - b).map(i => ({
    label: new Date(data[i].date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }),
    leftPct: ((i + 0.5) / data.length) * 100,
  }))
  return { bars, xLabels, maxRevenue }
})

const yLabels = computed(() => {
  const max = chartPoints.value.maxRevenue
  if (!max) return []
  return [1, 0.75, 0.5, 0.25, 0].map(frac => ({
    pct: (1 - frac) * 100,
    label: frac > 0 ? formatPrice(max * frac) : '0',
  }))
})

// ── Formatters ────────────────────────────────────────────────
function formatPrice(kopecks: number) {
  const r = kopecks / 100
  if (r >= 1_000_000) return (r / 1_000_000).toFixed(1) + 'M ₽'
  if (r >= 1_000)     return Math.round(r / 1_000) + 'K ₽'
  return Math.round(r) + ' ₽'
}

function formatPriceFull(kopecks: number) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(kopecks / 100)
}

function growthPct(cur?: number, prev?: number) {
  const c = cur ?? 0, p = prev ?? 0
  if (!p && !c) return ''
  if (!p) return 'Новый'
  const pct = Math.round(((c - p) / p) * 100)
  return (pct > 0 ? '+' : '') + pct + '%'
}

function deltaClass(cur?: number, prev?: number) {
  const p = prev ?? 0
  if (!p) return 'text-gray-400'
  return (cur ?? 0) >= p ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500 dark:text-red-400'
}

// ── Status breakdowns ─────────────────────────────────────────
const orderBreakdown = computed(() => {
  const total = stats.value.total_orders || 1
  return [
    { label: 'Ожидают',   color: 'bg-yellow-400',  count: stats.value.pending_orders    ?? 0 },
    { label: 'Оплачены',  color: 'bg-emerald-500', count: stats.value.paid_orders       ?? 0 },
    { label: 'В работе',  color: 'bg-blue-500',    count: stats.value.processing_orders ?? 0 },
    { label: 'Завершены', color: 'bg-violet-500',  count: stats.value.completed_orders  ?? 0 },
    { label: 'Отменены',  color: 'bg-red-400',     count: stats.value.cancelled_orders  ?? 0 },
  ].map(s => ({ ...s, pct: Math.round((s.count / total) * 100) }))
})

const bookingBreakdown = computed(() => {
  const total = bookingStats.value.total_bookings || 1
  return [
    { label: 'Ожидают',      color: 'bg-yellow-400', count: bookingStats.value.pending_bookings   ?? 0 },
    { label: 'Подтверждены', color: 'bg-blue-500',   count: bookingStats.value.confirmed_bookings ?? 0 },
    { label: 'Завершены',    color: 'bg-green-500',  count: bookingStats.value.completed_bookings ?? 0 },
    { label: 'Отменены',     color: 'bg-red-400',    count: bookingStats.value.cancelled_bookings ?? 0 },
    { label: 'Неявка',       color: 'bg-gray-400',   count: bookingStats.value.no_show_bookings   ?? 0 },
  ].map(s => ({ ...s, pct: Math.round((s.count / total) * 100) }))
})

// ── Lifecycle ─────────────────────────────────────────────────
let ro: ResizeObserver | null = null

onMounted(async () => {
  await loadAll()
  if (chartContainerRef.value) {
    updateChartWidth()
    ro = new ResizeObserver(updateChartWidth)
    ro.observe(chartContainerRef.value)
  }
})

onUnmounted(() => ro?.disconnect())
</script>

<template>
  <div class="space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Аналитика</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Показатели за выбранный период</p>
      </div>
      <div class="flex items-center bg-gray-100 dark:bg-gray-800 rounded-lg p-1 gap-1">
        <button
          v-for="p in (['7d', '30d'] as Period[])" :key="p"
          @click="period = p"
          :class="['px-3 py-1.5 rounded-md text-sm font-medium transition-all',
            period === p
              ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm'
              : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200']"
        >{{ periodLabels[p] }}</button>
      </div>
    </div>

    <!-- KPI cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
      <!-- Revenue -->
      <div class="card">
        <p class="text-sm text-gray-500 dark:text-gray-400">Выручка</p>
        <div v-if="loadingStats" class="mt-2 space-y-1.5">
          <div class="h-7 w-28 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
          <div class="h-4 w-20 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
        </div>
        <template v-else>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1 tabular-nums">
            {{ formatPriceFull(stats.total_revenue ?? 0) }}
          </p>
          <p v-if="growthPct(stats.total_revenue, stats.prev_revenue)"
            :class="['text-xs font-semibold mt-1.5', deltaClass(stats.total_revenue, stats.prev_revenue)]">
            {{ growthPct(stats.total_revenue, stats.prev_revenue) }} vs прошлый
          </p>
        </template>
        <div class="w-9 h-9 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center mt-3">
          <svg class="w-4.5 h-4.5 w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
      </div>

      <!-- Orders -->
      <div class="card">
        <p class="text-sm text-gray-500 dark:text-gray-400">Заказов</p>
        <div v-if="loadingStats" class="mt-2 space-y-1.5">
          <div class="h-7 w-16 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
          <div class="h-4 w-24 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
        </div>
        <template v-else>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1 tabular-nums">{{ stats.total_orders ?? 0 }}</p>
          <p v-if="growthPct(stats.total_orders, stats.prev_orders)"
            :class="['text-xs font-semibold mt-1.5', deltaClass(stats.total_orders, stats.prev_orders)]">
            {{ growthPct(stats.total_orders, stats.prev_orders) }} vs прошлый
          </p>
        </template>
        <div class="w-9 h-9 bg-primary-100 dark:bg-primary-900/30 rounded-xl flex items-center justify-center mt-3">
          <svg class="w-5 h-5 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
          </svg>
        </div>
      </div>

      <!-- Avg check -->
      <div class="card">
        <p class="text-sm text-gray-500 dark:text-gray-400">Средний чек</p>
        <div v-if="loadingStats" class="mt-2 space-y-1.5">
          <div class="h-7 w-24 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
        </div>
        <template v-else>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1 tabular-nums">
            {{ stats.average_order_value ? formatPriceFull(stats.average_order_value) : '—' }}
          </p>
          <p class="text-xs text-gray-400 mt-1.5">без отменённых</p>
        </template>
        <div class="w-9 h-9 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center mt-3">
          <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
          </svg>
        </div>
      </div>

      <!-- Bookings -->
      <div class="card">
        <p class="text-sm text-gray-500 dark:text-gray-400">Записей всего</p>
        <div v-if="loadingStats" class="mt-2 space-y-1.5">
          <div class="h-7 w-16 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
        </div>
        <template v-else>
          <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1 tabular-nums">{{ bookingStats.total_bookings ?? 0 }}</p>
          <p class="text-xs text-gray-400 mt-1.5">за всё время</p>
        </template>
        <div class="w-9 h-9 bg-violet-100 dark:bg-violet-900/30 rounded-xl flex items-center justify-center mt-3">
          <svg class="w-5 h-5 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
        </div>
      </div>
    </div>

    <!-- Revenue chart -->
    <div ref="chartContainerRef" class="card flex flex-col min-h-[280px]">
      <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4 flex-shrink-0">
        Выручка по дням
        <span class="text-xs font-normal text-gray-400 ml-2">{{ periodLabels[period] }}</span>
      </h2>

      <div v-if="loadingChart" class="flex-1 flex items-end gap-px min-h-[160px]">
        <div v-for="i in 30" :key="i"
          class="flex-1 bg-gray-100 dark:bg-gray-800 rounded-t animate-pulse"
          :style="`height: ${20 + Math.abs(Math.sin(i * 0.9)) * 65}%`"/>
      </div>

      <div v-else-if="!chartHasData" class="flex-1 flex flex-col items-center justify-center text-gray-400 gap-2 min-h-[160px]">
        <svg class="w-10 h-10 text-gray-200 dark:text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        </svg>
        <p class="text-sm">Нет данных за период</p>
      </div>

      <div v-else class="flex-1 flex flex-col min-h-0">
        <div class="flex gap-2 flex-1 min-h-[160px]">
          <!-- Y-axis -->
          <div class="w-10 flex-shrink-0 relative">
            <span v-for="yl in yLabels" :key="yl.pct"
              class="absolute right-0 pr-1 leading-none text-[10px] text-gray-400 dark:text-gray-600 tabular-nums"
              :style="`top: ${yl.pct}%; transform: translateY(-50%)`">{{ yl.label }}</span>
          </div>
          <!-- Bars -->
          <div class="flex-1 relative overflow-hidden">
            <div v-for="yl in yLabels" :key="`g-${yl.pct}`"
              class="absolute left-0 right-0 border-t border-gray-100 dark:border-gray-800 pointer-events-none"
              :style="`top: ${yl.pct}%`"/>
            <div class="absolute inset-0 flex gap-px">
              <div v-for="bar in chartPoints.bars" :key="bar.date"
                class="flex-1 min-w-0 relative group cursor-default flex flex-col justify-end">
                <div :class="['absolute bottom-full mb-1.5 z-20 pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-150 bg-gray-900 dark:bg-gray-700 text-white rounded-lg px-2.5 py-1.5 text-xs whitespace-nowrap shadow-lg',
                  bar.idx < 2 ? 'left-0' : bar.idx > bar.total - 3 ? 'right-0' : 'left-1/2 -translate-x-1/2']">
                  <div class="font-medium mb-0.5">{{ bar.label }}</div>
                  <div class="text-primary-300 tabular-nums">{{ formatPriceFull(bar.revenue) }}</div>
                  <div class="text-gray-400">{{ bar.orders }} {{ plural(bar.orders, 'заказ', 'заказа', 'заказов') }}</div>
                </div>
                <div class="w-full rounded-t-sm transition-colors"
                  :class="bar.revenue > 0 ? 'bg-primary-500 dark:bg-primary-400 group-hover:bg-primary-600' : 'bg-gray-100 dark:bg-gray-800'"
                  :style="`height: ${bar.heightPct}%; min-height: ${bar.revenue > 0 ? 3 : 0}px`"/>
              </div>
            </div>
          </div>
        </div>
        <!-- X-axis -->
        <div class="relative h-5 ml-10 mt-1 flex-shrink-0">
          <span v-for="xl in chartPoints.xLabels" :key="xl.leftPct"
            class="absolute leading-none text-[10px] text-gray-400 dark:text-gray-600 -translate-x-1/2 tabular-nums"
            :style="`left: ${xl.leftPct}%`">{{ xl.label }}</span>
        </div>
      </div>
    </div>

    <!-- Bottom row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Top products -->
      <div class="card">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Топ товаров / услуг</h2>
        <div v-if="loadingChart" class="space-y-3">
          <div v-for="i in 5" :key="i" class="h-5 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
        </div>
        <div v-else-if="topProducts.length === 0" class="py-8 text-sm text-gray-400 text-center">Нет данных</div>
        <div v-else class="space-y-3">
          <div v-for="(p, idx) in topProducts" :key="p.name" class="flex items-center gap-2">
            <span class="text-xs font-semibold text-gray-300 dark:text-gray-600 w-4 text-right flex-shrink-0">{{ idx + 1 }}</span>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between mb-1 gap-2">
                <span class="text-sm text-gray-700 dark:text-gray-300 truncate">{{ p.name }}</span>
                <span class="text-xs font-semibold text-gray-900 dark:text-white flex-shrink-0 tabular-nums">{{ formatPrice(p.revenue) }}</span>
              </div>
              <div class="h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full bg-primary-500 dark:bg-primary-400 rounded-full"
                  :style="`width: ${Math.round((p.revenue / topProducts[0].revenue) * 100)}%`"/>
              </div>
              <p class="text-xs text-gray-400 mt-0.5">{{ p.count }} {{ plural(p.count, 'раз', 'раза', 'раз') }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Top customers -->
      <div class="card">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Топ клиентов</h2>
        <div v-if="loadingCustomers" class="space-y-3">
          <div v-for="i in 5" :key="i" class="h-10 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
        </div>
        <div v-else-if="topCustomers.length === 0" class="py-8 text-sm text-gray-400 text-center">Нет данных</div>
        <div v-else class="space-y-1">
          <div v-for="(c, idx) in topCustomers" :key="c.id"
            class="flex items-center gap-3 py-2 -mx-2 px-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
            <span class="text-xs font-semibold text-gray-300 dark:text-gray-600 w-4 text-right flex-shrink-0">{{ idx + 1 }}</span>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ c.name }}</div>
              <div class="text-xs text-gray-400">{{ c.phone }} · {{ c.total_orders }} {{ plural(c.total_orders, 'заказ', 'заказа', 'заказов') }}</div>
            </div>
            <span class="text-sm font-semibold text-gray-900 dark:text-white tabular-nums flex-shrink-0">
              {{ formatPrice(c.total_spent) }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Status breakdowns -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Orders by status -->
      <div class="card">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Статусы заказов</h2>
        <div v-if="loadingStats" class="space-y-3">
          <div v-for="i in 4" :key="i" class="h-5 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
        </div>
        <div v-else-if="!stats.total_orders" class="py-6 text-sm text-gray-400 text-center">Нет заказов за период</div>
        <div v-else class="space-y-3">
          <div v-for="s in orderBreakdown" :key="s.label" class="flex items-center gap-3">
            <div :class="['w-2.5 h-2.5 rounded-full flex-shrink-0', s.color]"/>
            <span class="text-sm text-gray-600 dark:text-gray-400 w-24 flex-shrink-0">{{ s.label }}</span>
            <div class="flex-1 h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
              <div :class="['h-full rounded-full', s.color]" :style="`width: ${s.pct}%`"/>
            </div>
            <span class="text-sm font-semibold text-gray-900 dark:text-white w-6 text-right tabular-nums">{{ s.count }}</span>
          </div>
          <div class="pt-2 border-t border-gray-100 dark:border-gray-800 flex justify-between text-xs text-gray-400">
            <span>Всего</span><span class="font-medium text-gray-700 dark:text-gray-300 tabular-nums">{{ stats.total_orders }}</span>
          </div>
        </div>
      </div>

      <!-- Bookings by status -->
      <div class="card">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Статусы записей</h2>
        <div v-if="loadingStats" class="space-y-3">
          <div v-for="i in 5" :key="i" class="h-5 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"/>
        </div>
        <div v-else-if="!bookingStats.total_bookings" class="py-6 text-sm text-gray-400 text-center">Нет записей</div>
        <div v-else class="space-y-3">
          <div v-for="s in bookingBreakdown" :key="s.label" class="flex items-center gap-3">
            <div :class="['w-2.5 h-2.5 rounded-full flex-shrink-0', s.color]"/>
            <span class="text-sm text-gray-600 dark:text-gray-400 w-24 flex-shrink-0">{{ s.label }}</span>
            <div class="flex-1 h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
              <div :class="['h-full rounded-full', s.color]" :style="`width: ${s.pct}%`"/>
            </div>
            <span class="text-sm font-semibold text-gray-900 dark:text-white w-6 text-right tabular-nums">{{ s.count }}</span>
          </div>
          <div class="pt-2 border-t border-gray-100 dark:border-gray-800 flex justify-between text-xs text-gray-400">
            <span>Всего</span><span class="font-medium text-gray-700 dark:text-gray-300 tabular-nums">{{ bookingStats.total_bookings }}</span>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>
