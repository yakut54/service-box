<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'
import { formatPrice, formatDate } from '@/shared/lib/format'
import { UiSpinner, UiEmptyState } from '@/shared/ui'

const customers = ref<any[]>([])
const loading = ref(true)
const searchQuery = ref('')

const sortedCustomers = computed(() => {
  return [...customers.value].sort((a, b) => (b.total_spent || 0) - (a.total_spent || 0))
})

const totalCustomers = computed(() => customers.value.length)
const totalRevenue = computed(() => customers.value.reduce((sum, c) => sum + (c.total_spent || 0), 0))
const avgOrderValue = computed(() => {
  const totalOrders = customers.value.reduce((sum, c) => sum + (c.total_orders || 0), 0)
  return totalOrders > 0 ? totalRevenue.value / totalOrders : 0
})

async function loadCustomers() {
  loading.value = true
  try {
    const params: Record<string, string> = {}
    if (searchQuery.value.trim()) params.search = searchQuery.value.trim()
    const data = await api.getCustomers(params)
    customers.value = data.data
  } catch { /* ignore */ }
  loading.value = false
}

function onSearch() {
  loadCustomers()
}

onMounted(() => { loadCustomers() })
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Клиенты</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">{{ totalCustomers }} {{ plural(totalCustomers, 'клиент', 'клиента', 'клиентов') }}</p>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-3 sm:gap-4 mb-6">
      <div class="card">
        <div class="text-[10px] sm:text-sm text-gray-500 dark:text-gray-400 leading-tight"><span class="sm:hidden">Клиентов</span><span class="hidden sm:inline">Всего клиентов</span></div>
        <div class="text-base sm:text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ totalCustomers }}</div>
      </div>
      <div class="card">
        <div class="text-[10px] sm:text-sm text-gray-500 dark:text-gray-400 leading-tight"><span class="sm:hidden">Выручка</span><span class="hidden sm:inline">Общая выручка</span></div>
        <div class="text-base sm:text-2xl font-bold text-green-600 dark:text-green-400 mt-1">{{ formatPrice(totalRevenue) }}</div>
      </div>
      <div class="card">
        <div class="text-[10px] sm:text-sm text-gray-500 dark:text-gray-400 leading-tight">Ср. чек<span class="hidden sm:inline">овой</span></div>
        <div class="text-base sm:text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ formatPrice(avgOrderValue) }}</div>
      </div>
    </div>

    <!-- Search -->
    <div class="card mb-6">
      <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
          <input
            v-model="searchQuery"
            @input="onSearch"
            type="text"
            class="input"
            placeholder="Поиск по имени, телефону или email..."
          />
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="card py-12 flex justify-center">
      <UiSpinner />
    </div>

    <!-- Empty -->
    <UiEmptyState v-else-if="customers.length === 0" title="Нет клиентов" description="Клиенты появятся после первого заказа или записи">
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
      </template>
    </UiEmptyState>

    <!-- Table -->
    <div v-else>
      <!-- Desktop table -->
      <div class="card overflow-hidden p-0 hidden sm:block">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Клиент</th>
                <th>Телефон</th>
                <th>Email</th>
                <th>Заказов</th>
                <th>Потрачено</th>
                <th>Последний заказ</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in sortedCustomers" :key="c.id">
                <td>
                  <RouterLink :to="`/customers/${c.id}`" class="font-medium text-gray-900 dark:text-gray-200 hover:text-primary-600 dark:hover:text-primary-400">
                    {{ c.name || 'Без имени' }}
                  </RouterLink>
                </td>
                <td>
                  <a v-if="c.phone" :href="`tel:${c.phone}`" class="text-primary-600 dark:text-primary-400 hover:text-primary-700">{{ c.phone }}</a>
                  <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                </td>
                <td>
                  <a v-if="c.email" :href="`mailto:${c.email}`" class="text-gray-600 dark:text-gray-400 hover:text-primary-600 text-sm">{{ c.email }}</a>
                  <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                </td>
                <td><span class="font-medium dark:text-gray-200">{{ c.total_orders || 0 }}</span></td>
                <td><span class="font-semibold text-gray-900 dark:text-white">{{ formatPrice(c.total_spent || 0) }}</span></td>
                <td class="text-sm text-gray-500 dark:text-gray-400">{{ formatDate(c.last_order_at) }}</td>
                <td>
                  <RouterLink :to="`/customers/${c.id}`" class="btn-ghost btn-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile cards -->
      <div class="sm:hidden card overflow-hidden p-0">
        <RouterLink
          v-for="c in sortedCustomers"
          :key="c.id"
          :to="`/customers/${c.id}`"
          class="flex items-center justify-between gap-3 p-4 border-b border-gray-100 dark:border-gray-800 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
        >
          <div class="min-w-0 flex-1">
            <div class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">{{ c.name || 'Без имени' }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">{{ c.phone || c.email || '—' }}</div>
          </div>
          <div class="text-right shrink-0">
            <div class="font-semibold text-sm text-gray-900 dark:text-white">{{ formatPrice(c.total_spent || 0) }}</div>
            <div class="text-xs text-gray-400 dark:text-gray-500">{{ c.total_orders || 0 }} {{ plural(c.total_orders || 0, 'заказ', 'заказа', 'заказов') }}</div>
          </div>
        </RouterLink>
      </div>
    </div>
  </div>
</template>
