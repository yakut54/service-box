<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import type { Commission } from '@/types'

const data = ref<Commission | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const statusLabels: Record<string, string> = {
  paid: 'Оплачен', processing: 'В работе', completed: 'Завершён', cancelled: 'Отменён',
}

function formatRub(rubles: number) {
  return rubles.toLocaleString('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 })
}

function formatKop(kopecks: number) {
  return formatRub(Math.round(kopecks / 100))
}

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' })
}

async function load() {
  loading.value = true
  error.value = null
  try {
    data.value = await api.getCommission()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="p-6 space-y-6 max-w-4xl mx-auto">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Комиссия</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Как платформа зарабатывает на ваших продажах</p>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="w-8 h-8 border-2 border-primary-600 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-4 py-3 text-red-700 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <template v-else-if="data">
      <!-- Rate card -->
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-sm">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Ставка комиссии</p>
            <p class="text-4xl font-bold text-gray-900 dark:text-white">{{ data.commission_percent }}%</p>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
              с каждого оплаченного заказа — единая ставка, без тарифов и скрытых условий
            </p>
          </div>
          <div class="text-right">
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Минимальная сумма заказа</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">
              {{ formatKop(data.min_order_amount_kopecks) }}
            </p>
          </div>
        </div>
      </div>

      <!-- KPI cards -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow-sm">
          <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">За всё время</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ formatKop(data.commission_total_kopecks) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow-sm">
          <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">За 30 дней</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ formatKop(data.commission_last_30d_kopecks) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-5 shadow-sm">
          <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">В этом месяце</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ formatKop(data.commission_month_kopecks) }}</p>
        </div>
      </div>

      <!-- Recent orders -->
      <div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Последние заказы с комиссией</h3>

        <div v-if="data.recent_orders.length === 0" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-8 text-center text-gray-500 dark:text-gray-400 text-sm">
          Оплаченных заказов пока нет
        </div>

        <div v-else class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Заказ</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Статус</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Сумма заказа</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Комиссия</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Дата</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <tr v-for="o in data.recent_orders" :key="o.id">
                <td class="px-4 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">#{{ o.id.slice(0, 8) }}</td>
                <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ statusLabels[o.status] || o.status }}</td>
                <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ formatKop(o.total_price) }}</td>
                <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-white">{{ formatKop(o.commission_amount) }}</td>
                <td class="px-4 py-3 text-right text-gray-400 text-xs">{{ formatDate(o.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
