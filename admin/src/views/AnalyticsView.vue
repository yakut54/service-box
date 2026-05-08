<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'
import KpiCard from '@/components/KpiCard.vue'
import RevenueChart from '@/components/RevenueChart.vue'
import StatusBreakdown from '@/components/StatusBreakdown.vue'
import type { OrderStats, BookingStats, Customer } from '@/types'

type Period = '7d' | '30d'
const period = ref<Period>('30d')
const periodLabels: Record<Period, string> = { '7d': '7 дней', '30d': '30 дней' }
const periodToStats: Record<Period, string> = { '7d': 'week', '30d': 'month' }
const periodToDays:  Record<Period, number>  = { '7d': 7, '30d': 30 }

const stats         = ref<Partial<OrderStats>>({})
const bookingStats  = ref<Partial<BookingStats>>({})
const chartData     = ref<Array<{ date: string; orders: number; revenue: number }>>([])
const topProducts   = ref<Array<{ name: string; revenue: number; count: number }>>([])
const topCustomers  = ref<Customer[]>([])

const loadingStats    = ref(false)
const loadingChart    = ref(false)
const loadingTops     = ref(false)

async function loadAll() {
  loadingStats.value = true
  loadingChart.value = true
  loadingTops.value  = true

  const [statsRes, chartRes, bookingRes, customersRes, ordersRes] = await Promise.allSettled([
    api.getOrderStats({ period: periodToStats[period.value] }),
    api.getOrderChart(periodToDays[period.value]),
    api.getBookingStats(),
    api.getCustomers(),
    api.getOrders(),
  ])

  if (statsRes.status    === 'fulfilled') stats.value       = statsRes.value
  if (chartRes.status    === 'fulfilled') chartData.value   = chartRes.value.data
  if (bookingRes.status  === 'fulfilled') bookingStats.value = bookingRes.value

  if (customersRes.status === 'fulfilled') {
    topCustomers.value = [...(customersRes.value.data ?? [])]
      .sort((a, b) => b.total_spent - a.total_spent).slice(0, 5)
  }

  if (ordersRes.status === 'fulfilled') {
    const map = new Map<string, { name: string; revenue: number; count: number }>()
    for (const order of ordersRes.value.data) {
      if (order.status === 'cancelled') continue
      for (const item of order.items ?? []) {
        const e = map.get(item.product_name) ?? { name: item.product_name, revenue: 0, count: 0 }
        e.revenue += (item.price ?? 0) * (item.quantity ?? 1)
        e.count   += item.quantity ?? 1
        map.set(item.product_name, e)
      }
    }
    topProducts.value = [...map.values()].sort((a, b) => b.revenue - a.revenue).slice(0, 5)
  }

  loadingStats.value = false
  loadingChart.value = false
  loadingTops.value  = false
}

watch(period, loadAll)
onMounted(loadAll)

function formatPriceFull(rubles: number) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(rubles)
}

function formatPrice(rubles: number) {
  if (rubles >= 1_000_000) return (rubles / 1_000_000).toFixed(1) + 'M ₽'
  if (rubles >= 1_000)     return Math.round(rubles / 1_000) + 'K ₽'
  return Math.round(rubles) + ' ₽'
}

function growthPct(cur?: number, prev?: number) {
  const c = cur ?? 0, p = prev ?? 0
  if (!p && !c) return ''
  if (!p) return 'Новый'
  return ((c - p) / p * 100 > 0 ? '+' : '') + Math.round((c - p) / p * 100) + '%'
}

const orderBreakdown = computed(() => {
  const t = stats.value.total_orders || 1
  return [
    { label: 'Ожидают',   color: 'bg-yellow-400',  count: stats.value.pending_orders    ?? 0, pct: Math.round((stats.value.pending_orders    ?? 0) / t * 100) },
    { label: 'Оплачены',  color: 'bg-emerald-500', count: stats.value.paid_orders       ?? 0, pct: Math.round((stats.value.paid_orders       ?? 0) / t * 100) },
    { label: 'В работе',  color: 'bg-blue-500',    count: stats.value.processing_orders ?? 0, pct: Math.round((stats.value.processing_orders ?? 0) / t * 100) },
    { label: 'Завершены', color: 'bg-violet-500',  count: stats.value.completed_orders  ?? 0, pct: Math.round((stats.value.completed_orders  ?? 0) / t * 100) },
    { label: 'Отменены',  color: 'bg-red-400',     count: stats.value.cancelled_orders  ?? 0, pct: Math.round((stats.value.cancelled_orders  ?? 0) / t * 100) },
  ]
})

const bookingBreakdown = computed(() => {
  const t = bookingStats.value.total_bookings || 1
  return [
    { label: 'Ожидают',      color: 'bg-yellow-400', count: bookingStats.value.pending_bookings   ?? 0, pct: Math.round((bookingStats.value.pending_bookings   ?? 0) / t * 100) },
    { label: 'Подтверждены', color: 'bg-blue-500',   count: bookingStats.value.confirmed_bookings ?? 0, pct: Math.round((bookingStats.value.confirmed_bookings ?? 0) / t * 100) },
    { label: 'Завершены',    color: 'bg-green-500',  count: bookingStats.value.completed_bookings ?? 0, pct: Math.round((bookingStats.value.completed_bookings ?? 0) / t * 100) },
    { label: 'Отменены',     color: 'bg-red-400',    count: bookingStats.value.cancelled_bookings ?? 0, pct: Math.round((bookingStats.value.cancelled_bookings ?? 0) / t * 100) },
    { label: 'Неявка',       color: 'bg-gray-400',   count: bookingStats.value.no_show_bookings   ?? 0, pct: Math.round((bookingStats.value.no_show_bookings   ?? 0) / t * 100) },
  ]
})
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
        <button v-for="p in (['7d', '30d'] as Period[])" :key="p" @click="period = p"
          :class="['px-3 py-1.5 rounded-md text-sm font-medium transition-all',
            period === p ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200']">
          {{ periodLabels[p] }}
        </button>
      </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
      <KpiCard
        title="Выручка"
        :value="formatPriceFull(stats.total_revenue ?? 0)"
        :delta="growthPct(stats.total_revenue, stats.prev_revenue) || undefined"
        :delta-up="(stats.total_revenue ?? 0) >= (stats.prev_revenue ?? 0)"
        :loading="loadingStats"
        color="green" icon="revenue"
      />
      <KpiCard
        title="Заказов"
        :value="String(stats.total_orders ?? 0)"
        :delta="growthPct(stats.total_orders, stats.prev_orders) || undefined"
        :delta-up="(stats.total_orders ?? 0) >= (stats.prev_orders ?? 0)"
        :loading="loadingStats"
        color="blue" icon="orders"
      />
      <KpiCard
        title="Средний чек"
        :value="stats.average_order_value ? formatPriceFull(stats.average_order_value) : '—'"
        subtitle="без отменённых"
        :loading="loadingStats"
        color="purple" icon="avg"
      />
      <KpiCard
        title="Записей"
        :value="String(bookingStats.total_bookings ?? 0)"
        subtitle="за всё время"
        :loading="loadingStats"
        color="violet" icon="bookings"
      />
    </div>

    <!-- Chart -->
    <div class="card flex flex-col min-h-[280px]">
      <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4 flex-shrink-0">
        Выручка по дням
        <span class="text-xs font-normal text-gray-400 ml-2">{{ periodLabels[period] }}</span>
      </h2>
      <RevenueChart :data="chartData" :loading="loadingChart"/>
    </div>

    <!-- Tops -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Top products -->
      <div class="card">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Топ товаров / услуг</h2>
        <div v-if="loadingTops" class="space-y-3">
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
                  :style="`width:${Math.round((p.revenue / topProducts[0].revenue) * 100)}%`"/>
              </div>
              <p class="text-xs text-gray-400 mt-0.5">{{ p.count }} {{ plural(p.count, 'раз', 'раза', 'раз') }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Top customers -->
      <div class="card">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Топ клиентов</h2>
        <div v-if="loadingTops" class="space-y-3">
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
            <span class="text-sm font-semibold text-gray-900 dark:text-white tabular-nums flex-shrink-0">{{ formatPrice(c.total_spent) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Status breakdowns -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="card">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Статусы заказов</h2>
        <StatusBreakdown :items="orderBreakdown" :total="stats.total_orders ?? 0" :loading="loadingStats" empty-text="Нет заказов за период"/>
      </div>
      <div class="card">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Статусы записей</h2>
        <StatusBreakdown :items="bookingBreakdown" :total="bookingStats.total_bookings ?? 0" :loading="loadingStats" empty-text="Нет записей"/>
      </div>
    </div>

  </div>
</template>
