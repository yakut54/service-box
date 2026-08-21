<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import type { SuperadminRevenue } from '@/types'

const data = ref<SuperadminRevenue | null>(null)
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
    data.value = await api.superadminGetRevenue()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="flex flex-col gap-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Выручка платформы</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Комиссия 20% со всех оплаченных заказов</p>
      </div>
      <button @click="load" class="btn-ghost text-sm">Обновить</button>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <div v-else-if="error" class="p-4 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-lg text-sm">
      {{ error }}
    </div>

    <template v-else-if="data">
      <!-- KPI cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card p-5">
          <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Комиссия всего</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ formatKop(data.commission_total_kopecks) }}</p>
          <p class="text-xs text-gray-400 mt-1">за всё время</p>
        </div>
        <div class="card p-5">
          <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Комиссия за 30д</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ formatKop(data.commission_last_30d_kopecks) }}</p>
          <p class="text-xs text-gray-400 mt-1">за последние 30 дней</p>
        </div>
        <div class="card p-5">
          <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Магазины</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ data.total_shops }}</p>
          <p class="text-xs text-gray-400 mt-1">всего на платформе</p>
        </div>
        <div class="card p-5">
          <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Новых (30д)</p>
          <p class="text-2xl font-bold text-green-600 dark:text-green-400">+{{ data.new_shops_30d }}</p>
          <p class="text-xs text-gray-400 mt-1">за последние 30 дней</p>
        </div>
      </div>

      <!-- Recent orders -->
      <div class="card overflow-hidden">
        <div class="p-5 border-b border-gray-100 dark:border-gray-800">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Последние заказы с комиссией</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 dark:border-gray-800">
                <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Магазин</th>
                <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Статус</th>
                <th class="text-right py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Сумма заказа</th>
                <th class="text-right py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Комиссия</th>
                <th class="text-right py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Дата</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
              <tr v-if="data.recent_orders.length === 0">
                <td colspan="5" class="py-8 text-center text-gray-400">Заказов с комиссией ещё нет</td>
              </tr>
              <tr v-for="o in data.recent_orders" :key="o.order_id" class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                <td class="py-3 px-4 text-gray-900 dark:text-white">{{ o.shop_name }}</td>
                <td class="py-3 px-4 text-gray-500 dark:text-gray-400">{{ statusLabels[o.status] || o.status }}</td>
                <td class="py-3 px-4 text-right text-gray-600 dark:text-gray-400">{{ formatKop(o.total_kopecks) }}</td>
                <td class="py-3 px-4 text-right font-medium text-gray-900 dark:text-white">{{ formatKop(o.commission_kopecks) }}</td>
                <td class="py-3 px-4 text-right text-gray-400 text-xs">{{ formatDate(o.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
