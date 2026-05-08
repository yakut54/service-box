<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import type { SuperadminRevenue } from '@/types'

const data = ref<SuperadminRevenue | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const planLabels: Record<string, string> = {
  micro: 'Micro', start: 'Start', business: 'Business', pro: 'Pro',
}

const planColors: Record<string, string> = {
  micro:    'bg-gray-200 dark:bg-gray-600',
  start:    'bg-blue-400 dark:bg-blue-500',
  business: 'bg-purple-400 dark:bg-purple-500',
  pro:      'bg-amber-400 dark:bg-amber-500',
}

function formatRub(rubles: number) {
  return rubles.toLocaleString('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 })
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
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Выручка платформы</h1>
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
          <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">MRR</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ formatRub(data.mrr_kopecks) }}</p>
          <p class="text-xs text-gray-400 mt-1">в месяц (расчётно)</p>
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
        <div class="card p-5">
          <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">ARPU</p>
          <p class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ data.total_shops > 0 ? formatRub(Math.round(data.mrr_kopecks / data.total_shops)) : '—' }}
          </p>
          <p class="text-xs text-gray-400 mt-1">средний чек/магазин</p>
        </div>
      </div>

      <!-- By plan -->
      <div class="card p-5">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Распределение по тарифам</h2>
        <div class="space-y-3">
          <div v-for="(count, plan) in data.shops_by_plan" :key="plan" class="flex items-center gap-3">
            <span class="text-sm text-gray-700 dark:text-gray-300 w-20">{{ planLabels[plan] || plan }}</span>
            <div class="flex-1 h-3 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
              <div
                :class="['h-full rounded-full transition-all', planColors[plan] || 'bg-gray-400']"
                :style="{ width: data.total_shops > 0 ? (count / data.total_shops * 100) + '%' : '0%' }"
              ></div>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white w-8 text-right">{{ count }}</span>
          </div>
        </div>
      </div>

      <!-- Recent payments -->
      <div class="card overflow-hidden">
        <div class="p-5 border-b border-gray-100 dark:border-gray-800">
          <h2 class="text-base font-semibold text-gray-900 dark:text-white">Последние платежи</h2>
        </div>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-gray-100 dark:border-gray-800">
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Магазин</th>
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Тариф</th>
              <th class="text-right py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Сумма</th>
              <th class="text-right py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Дата</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-if="data.recent_payments.length === 0">
              <td colspan="4" class="py-8 text-center text-gray-400">Платежей ещё нет</td>
            </tr>
            <tr v-for="p in data.recent_payments" :key="p.id" class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
              <td class="py-3 px-4 text-gray-900 dark:text-white">{{ p.shop_name }}</td>
              <td class="py-3 px-4 text-gray-500 dark:text-gray-400">{{ planLabels[p.plan] || p.plan }}</td>
              <td class="py-3 px-4 text-right font-medium text-gray-900 dark:text-white">{{ formatRub(p.amount_kopecks) }}</td>
              <td class="py-3 px-4 text-right text-gray-400 text-xs">{{ formatDate(p.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
