<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { plural } from '@/lib/utils'
import { UiTooltip } from '@/shared/ui'

const props = defineProps<{
  data: Array<{ date: string; orders: number; revenue: number }>
  loading?: boolean
}>()

const root = ref<HTMLElement | null>(null)
const width = ref(600)

const hasData = computed(() => props.data.some(d => d.revenue > 0))

function fmt(rubles: number) {
  if (rubles >= 1_000_000) return (rubles / 1_000_000).toFixed(1) + 'M ₽'
  if (rubles >= 1_000)     return Math.round(rubles / 1_000) + 'K ₽'
  return Math.round(rubles) + ' ₽'
}

function fmtFull(rubles: number) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(rubles)
}

const points = computed(() => {
  const data = props.data
  if (!data.length) return { bars: [], xLabels: [], maxRevenue: 0 }
  const maxRevenue = Math.max(...data.map(d => d.revenue), 1)
  const bars = data.map((d, i) => ({
    ...d,
    heightPct: (d.revenue / maxRevenue) * 100,
    label: new Date(d.date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }),
    idx: i, total: data.length,
  }))
  const step = Math.ceil(data.length / Math.max(3, Math.floor(width.value / 44)))
  const idxSet = new Set<number>()
  for (let i = 0; i < data.length; i += step) idxSet.add(i)
  if (data.length - 1 - Math.floor((data.length - 1) / step) * step >= Math.ceil(step / 2))
    idxSet.add(data.length - 1)
  const xLabels = [...idxSet].sort((a, b) => a - b).map(i => ({
    label: new Date(data[i].date).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }),
    leftPct: ((i + 0.5) / data.length) * 100,
  }))
  return { bars, xLabels, maxRevenue }
})

const yLabels = computed(() => {
  const max = points.value.maxRevenue
  if (!max) return []
  return [1, 0.75, 0.5, 0.25, 0].map(f => ({ pct: (1 - f) * 100, label: f > 0 ? fmt(max * f) : '0' }))
})

let ro: ResizeObserver | null = null
function measure() { if (root.value) width.value = root.value.offsetWidth }

onMounted(() => {
  measure()
  ro = new ResizeObserver(measure)
  if (root.value) ro.observe(root.value)
})
onBeforeUnmount(() => ro?.disconnect())
</script>

<template>
  <div ref="root" class="flex flex-col flex-1 min-h-0">

    <!-- Skeleton -->
    <div v-if="loading" class="flex-1 flex items-end gap-px min-h-[160px]">
      <div v-for="i in 30" :key="i"
        class="flex-1 bg-gray-100 dark:bg-gray-800 rounded-t animate-pulse"
        :style="`height:${20 + Math.abs(Math.sin(i * 0.9)) * 65}%`"/>
    </div>

    <!-- Empty -->
    <div v-else-if="!hasData" class="flex-1 flex flex-col items-center justify-center text-gray-400 gap-2 min-h-[160px]">
      <svg class="w-10 h-10 text-gray-200 dark:text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
      </svg>
      <p class="text-sm">Нет данных за период</p>
    </div>

    <!-- Chart -->
    <template v-else>
      <div class="flex gap-2 flex-1 min-h-[160px]">
        <!-- Y-axis -->
        <div class="w-10 flex-shrink-0 relative">
          <span v-for="yl in yLabels" :key="yl.pct"
            class="absolute right-0 pr-1 leading-none text-[10px] text-gray-400 dark:text-gray-600 tabular-nums"
            :style="`top:${yl.pct}%;transform:translateY(-50%)`">{{ yl.label }}</span>
        </div>
        <!-- Bars + grid -->
        <div class="flex-1 relative">
          <div v-for="yl in yLabels" :key="`g${yl.pct}`"
            class="absolute left-0 right-0 border-t border-gray-100 dark:border-gray-800 pointer-events-none"
            :style="`top:${yl.pct}%`"/>
          <div class="absolute inset-0 flex gap-px">
            <UiTooltip
              v-for="bar in points.bars"
              :key="bar.date"
              :align="bar.idx < 2 ? 'start' : bar.idx > bar.total - 3 ? 'end' : 'center'"
              :bottom-pct="bar.heightPct"
              class="flex-1 min-w-0 flex flex-col justify-end cursor-default"
            >
              <template #content>
                <div class="font-semibold mb-1">{{ bar.label }}</div>
                <div class="text-primary-300 tabular-nums text-sm">{{ fmtFull(bar.revenue) }}</div>
                <div class="text-gray-400 mt-0.5">{{ bar.orders }} {{ plural(bar.orders, 'заказ', 'заказа', 'заказов') }}</div>
              </template>
              <div class="w-full rounded-t-sm transition-colors"
                :class="bar.revenue > 0 ? 'bg-primary-500 dark:bg-primary-400 hover:bg-primary-600' : 'bg-gray-100 dark:bg-gray-800'"
                :style="`height:${bar.heightPct}%;min-height:${bar.revenue > 0 ? 3 : 0}px`"/>
            </UiTooltip>
          </div>
        </div>
      </div>
      <!-- X-axis -->
      <div class="relative h-5 ml-10 mt-1 flex-shrink-0">
        <span v-for="xl in points.xLabels" :key="xl.leftPct"
          class="absolute leading-none text-[10px] text-gray-400 dark:text-gray-600 -translate-x-1/2 tabular-nums"
          :style="`left:${xl.leftPct}%`">{{ xl.label }}</span>
      </div>
    </template>
  </div>
</template>
