<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { api } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { UiSpinner } from '@/shared/ui'

const authStore = useAuthStore()
const shopTz = computed(() => authStore.shop?.timezone || 'Europe/Moscow')

// ── Period ──────────────────────────────────────────────────────
type Period = 'today' | 'week' | 'month'
const period = ref<Period>('month')

function toYMD(d: Date): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: shopTz.value }).format(d)
}

const range = computed(() => {
  const now = new Date()
  if (period.value === 'today') {
    const d = toYMD(now)
    return { from: d, to: d }
  }
  if (period.value === 'week') {
    // Monday of current week
    const day = new Date(now)
    const dow = day.getDay() === 0 ? 6 : day.getDay() - 1 // 0=Mon
    day.setDate(day.getDate() - dow)
    const mon = toYMD(day)
    day.setDate(day.getDate() + 6)
    const sun = toYMD(day)
    return { from: mon, to: sun }
  }
  // month
  const y = now.toLocaleDateString('en-CA', { timeZone: shopTz.value }).slice(0, 7)
  const firstDay = `${y}-01`
  const lastDay  = toYMD(new Date(now.getFullYear(), now.getMonth() + 1, 0))
  return { from: firstDay, to: lastDay }
})

// ── Data ────────────────────────────────────────────────────────
interface StatBooking {
  id: string
  start_time: string
  customer_name: string
  service_name: string
  service_price: number
  amount: number
}

interface StatsData {
  period: { from: string; to: string }
  total: number
  completed: number
  no_show: number
  earnings: number
  salary_type: 'fixed' | 'percent'
  salary_rate: number
  bookings: StatBooking[]
}

const stats   = ref<StatsData | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  stats.value   = null
  try {
    stats.value = await (api as any).request<StatsData>(
      `/master/stats?from=${range.value.from}&to=${range.value.to}`
    )
  } catch { /* not a master */ }
  loading.value = false
}

watch(range, load)
onMounted(load)

// ── Formatting ──────────────────────────────────────────────────
function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('ru-RU', {
    hour: '2-digit', minute: '2-digit', timeZone: shopTz.value,
  })
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('ru-RU', {
    day: 'numeric', month: 'short', timeZone: shopTz.value,
  })
}

function formatMoney(val: number): string {
  return val.toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽'
}

const periodLabels: Record<Period, string> = {
  today: 'Сегодня',
  week:  'Неделя',
  month: 'Месяц',
}
</script>

<template>
  <div class="px-4 py-4 space-y-4 pb-4">

    <!-- Period tabs -->
    <div class="flex rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
      <button
        v-for="p in (['today', 'week', 'month'] as Period[])"
        :key="p"
        @click="period = p"
        class="flex-1 py-2.5 text-sm font-medium transition-colors"
        :class="period === p
          ? 'bg-primary-600 text-white'
          : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'"
      >
        {{ periodLabels[p] }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="py-12 flex justify-center">
      <UiSpinner class="w-8 h-8 text-primary-600" />
    </div>

    <template v-else-if="stats">

      <!-- Stat cards 2×2 -->
      <div class="grid grid-cols-2 gap-3">

        <div class="card py-4">
          <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">Всего записей</div>
          <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ stats.total }}</div>
        </div>

        <div class="card py-4">
          <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">Выполнено</div>
          <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ stats.completed }}</div>
        </div>

        <div class="card py-4">
          <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">Неявок</div>
          <div class="text-2xl font-bold text-red-500 dark:text-red-400">{{ stats.no_show }}</div>
        </div>

        <div class="card py-4">
          <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">Заработано</div>
          <div class="text-2xl font-bold text-primary-600 dark:text-primary-400 leading-tight">
            {{ formatMoney(stats.earnings) }}
          </div>
          <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
            {{ stats.salary_type === 'fixed'
              ? `${stats.salary_rate} ₽/визит`
              : `${stats.salary_rate}% от услуги` }}
          </div>
        </div>
      </div>

      <!-- Completed bookings list -->
      <div class="card !p-0 overflow-hidden" v-if="stats.bookings.length">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
          <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
            Завершённые записи
            <span class="text-gray-400 font-normal ml-1">({{ stats.bookings.length }})</span>
          </h3>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-700">
          <div
            v-for="b in stats.bookings"
            :key="b.id"
            class="flex items-center justify-between gap-3 px-4 py-3"
          >
            <div class="min-w-0 flex-1">
              <div class="text-sm font-medium text-gray-900 dark:text-white truncate">
                {{ b.customer_name }}
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">
                {{ b.service_name }}
              </div>
              <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                {{ formatDate(b.start_time) }}, {{ formatTime(b.start_time) }}
              </div>
            </div>
            <div class="flex-shrink-0 text-right">
              <div v-if="b.amount > 0" class="text-sm font-semibold text-primary-600 dark:text-primary-400">
                +{{ formatMoney(b.amount) }}
              </div>
              <div v-else class="text-xs text-gray-400">—</div>
              <div v-if="b.service_price > 0" class="text-xs text-gray-400 dark:text-gray-500">
                {{ formatMoney(b.service_price) }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty completed list -->
      <div v-else class="card text-center py-8">
        <div class="text-gray-400 dark:text-gray-500 text-sm">Завершённых записей нет</div>
      </div>

    </template>

  </div>
</template>
