<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import { formatPrice } from '@/shared/lib/format'
import { plural } from '@/lib/utils'
import PageHeader from '@/components/PageHeader.vue'
import KpiCard from '@/components/KpiCard.vue'
import RevenueChart from '@/components/RevenueChart.vue'
import type { ChainRevenue } from '@/types'

const data    = ref<ChainRevenue | null>(null)
const loading = ref(true)
const error   = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await api.chainGetRevenue(30)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Выручка сети" subtitle="Оборот по всем вашим точкам за последние 30 дней" />

    <div v-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <KpiCard
        title="Оборот за 30 дней"
        :value="data ? formatPrice(data.period_kopecks) : '—'"
        :loading="loading"
        color="green"
        icon="revenue"
      />
      <KpiCard
        title="Заказы за 30 дней"
        :value="data ? String(data.period_orders) : '—'"
        :loading="loading"
        color="blue"
        icon="orders"
      />
      <KpiCard
        title="Всего с открытия"
        :value="data ? formatPrice(data.total_kopecks) : '—'"
        :loading="loading"
        color="purple"
        icon="avg"
      />
      <KpiCard
        title="Точек в сети"
        :value="data ? String(data.shops_count) : '—'"
        :loading="loading"
        color="gray"
      />
    </div>

    <div class="card flex flex-col min-h-[280px]">
      <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4 flex-shrink-0">Динамика за 30 дней</h2>
      <RevenueChart :data="data?.chart ?? []" :loading="loading" />
    </div>

    <div class="card overflow-hidden">
      <h2 class="text-base font-semibold text-gray-900 dark:text-white p-4 pb-0">По точкам</h2>

      <!-- Desktop -->
      <table class="w-full text-sm mt-2 hidden md:table">
        <thead>
          <tr class="border-b border-gray-200 dark:border-gray-700">
            <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Точка</th>
            <th class="text-right py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Оборот</th>
            <th class="text-right py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Заказы</th>
            <th class="text-right py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Доля</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <tr v-if="!loading && (!data || data.per_shop.length === 0)">
            <td colspan="4" class="py-12 text-center text-gray-400">Нет данных за период</td>
          </tr>
          <tr v-for="row in data?.per_shop ?? []" :key="row.shop_id" class="hover:bg-gray-50 dark:hover:bg-gray-800/40">
            <td class="py-3 px-4 font-medium text-gray-900 dark:text-white">{{ row.name }}</td>
            <td class="py-3 px-4 text-right tabular-nums text-gray-900 dark:text-white">{{ formatPrice(row.revenue_kopecks) }}</td>
            <td class="py-3 px-4 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ row.orders }}</td>
            <td class="py-3 px-4 text-right tabular-nums text-gray-600 dark:text-gray-400">{{ row.share_percent }}%</td>
          </tr>
        </tbody>
      </table>

      <!-- Mobile -->
      <div class="md:hidden divide-y divide-gray-100 dark:divide-gray-800">
        <p v-if="!loading && (!data || data.per_shop.length === 0)" class="text-center text-gray-400 py-12">Нет данных за период</p>
        <div v-for="row in data?.per_shop ?? []" :key="row.shop_id" class="p-4 flex flex-col gap-1">
          <div class="flex items-center justify-between">
            <p class="font-medium text-gray-900 dark:text-white">{{ row.name }}</p>
            <p class="tabular-nums text-gray-900 dark:text-white">{{ formatPrice(row.revenue_kopecks) }}</p>
          </div>
          <p class="text-xs text-gray-400">
            {{ row.orders }} {{ plural(row.orders, 'заказ', 'заказа', 'заказов') }} · доля {{ row.share_percent }}%
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
