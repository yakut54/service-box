<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { formatPrice } from '@/shared/lib/format'
import { ORDER_STATUS_LABELS } from '@/shared/lib/labels'
import { UiEmptyState, UiSpinner } from '@/shared/ui'
import type { Order, OrderStatus } from '@/types'

const orders  = ref<Order[]>([])
const loading = ref(false)
const error   = ref('')

const STATUS_COLORS: Record<OrderStatus, string> = {
  pending: 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
  paid: 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
  processing: 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
  completed: 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400',
  cancelled: 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
  needs_attention: 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400',
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getOrders()
    // Активные заказы — то, что нужно собрать — сверху; завершённые/отменённые вниз
    orders.value = res.data.sort((a, b) => {
      const rank = (o: Order) => (o.status === 'cancelled' || o.status === 'completed' ? 1 : 0)
      return rank(a) - rank(b) || new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
    })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

function itemsSummary(order: Order): string {
  if (!order.items?.length) return ''
  return order.items.map(i => `${i.product_name} ×${i.quantity}`).join(', ')
}

onMounted(load)
</script>

<template>
  <div class="p-4 space-y-3">
    <h1 class="text-lg font-semibold text-gray-900 dark:text-white px-1">Заказы на сборку</h1>

    <div v-if="loading" class="card flex items-center justify-center py-16">
      <UiSpinner />
    </div>

    <div v-else-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <UiEmptyState v-else-if="orders.length === 0" title="Нет заказов" description="Новые заказы появятся здесь" />

    <RouterLink
      v-else
      v-for="order in orders"
      :key="order.id"
      :to="{ name: 'collector-order-detail', params: { id: order.id } }"
      class="card flex flex-col gap-2 hover:border-primary-300 dark:hover:border-primary-700 transition-colors"
    >
      <div class="flex items-center justify-between gap-2">
        <span class="font-semibold text-gray-900 dark:text-white">{{ order.customer_name }}</span>
        <span :class="['px-2 py-0.5 rounded-full text-xs font-medium shrink-0', STATUS_COLORS[order.status]]">
          {{ ORDER_STATUS_LABELS[order.status] }}
        </span>
      </div>
      <p class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ itemsSummary(order) }}</p>
      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-400 dark:text-gray-500">{{ new Date(order.created_at).toLocaleString('ru-RU') }}</span>
        <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatPrice(order.total_price) }}</span>
      </div>
    </RouterLink>
  </div>
</template>
