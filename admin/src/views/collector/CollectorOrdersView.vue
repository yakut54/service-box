<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { getEcho } from '@/lib/echo'
import { useAuthStore } from '@/stores/auth'
import { formatPrice, formatRelativeTime, formatDateTime } from '@/shared/lib/format'
import { ORDER_STATUS_LABELS } from '@/shared/lib/labels'
import { UiEmptyState, UiSpinner } from '@/shared/ui'
import CustomSelect from '@/components/CustomSelect.vue'
import type { Order, OrderItem } from '@/types'

const authStore = useAuthStore()

const tab     = ref<'active' | 'done'>('active')
const orders  = ref<Order[]>([])
const loading = ref(false)
const error   = ref('')
const search  = ref('')
const statusFilter = ref('')
const sortOrder = ref<'newest' | 'oldest'>('newest')

const statusOptions = [
  { value: '', label: 'Все статусы' },
  { value: 'pending', label: 'Ожидает' },
  { value: 'paid', label: 'Оплачен' },
  { value: 'processing', label: 'В работе' },
  { value: 'needs_attention', label: 'Требует внимания' },
]

const sortOptions = [
  { value: 'newest', label: 'Сначала новые' },
  { value: 'oldest', label: 'Сначала старые' },
]

const filteredOrders = computed(() => {
  const q = search.value.trim().toLowerCase()
  return orders.value
    .filter(order => {
      if (statusFilter.value && order.status !== statusFilter.value) return false
      if (!q) return true
      return order.customer_name.toLowerCase().includes(q)
        || (order.items ?? []).some(i => i.product_name.toLowerCase().includes(q))
    })
    .sort((a, b) => {
      const diff = new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
      return sortOrder.value === 'newest' ? diff : -diff
    })
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getOrders(tab.value === 'done' ? { scope: 'done' } : undefined)
    orders.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

function switchTab(t: 'active' | 'done') {
  if (tab.value === t) return
  tab.value = t
  statusFilter.value = ''
  load()
}

function shortId(order: Order): string {
  return '#' + order.id.slice(0, 8)
}

function itemsSummary(order: Order): string {
  if (!order.items?.length) return ''
  return order.items.map(i => `${i.product_name} ×${i.quantity}`).join(', ')
}

function isResolved(item: OrderItem): boolean {
  const weightVariable = item.product?.physical?.sale_mode === 'weight_variable'
  return weightVariable ? item.actual_weight_grams != null : item.picked_qty != null
}

function progress(order: Order): { done: number; total: number } | null {
  if (!order.items?.length || !order.picking_started_at) return null
  const done = order.items.filter(isResolved).length
  return { done, total: order.items.length }
}

let channelName: string | null = null

onMounted(() => {
  load()

  const shopId = authStore.shop?.id
  if (shopId) {
    channelName = `shop.${shopId}`
    getEcho().private(channelName).listen('.orders.updated', () => load())
  }
})

onUnmounted(() => {
  if (channelName) getEcho().leave(channelName)
})
</script>

<template>
  <div class="p-4 space-y-3">
    <h1 class="text-lg font-semibold text-gray-900 dark:text-white px-1">Заказы на сборку</h1>

    <div class="flex gap-1 p-1 bg-gray-100 dark:bg-gray-900 rounded-lg">
      <button
        @click="switchTab('active')"
        :class="['flex-1 py-2 rounded-md text-sm font-medium transition-colors',
          tab === 'active' ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-gray-400']"
      >В работе</button>
      <button
        @click="switchTab('done')"
        :class="['flex-1 py-2 rounded-md text-sm font-medium transition-colors',
          tab === 'done' ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm' : 'text-gray-500 dark:text-gray-400']"
      >Собранные сегодня</button>
    </div>

    <div class="flex flex-col sm:flex-row gap-2">
      <input v-model="search" type="text" class="input flex-1" placeholder="Поиск по клиенту или товару..." />
      <CustomSelect v-if="tab === 'active'" v-model="statusFilter" :options="statusOptions" class="w-full sm:w-44 shrink-0" />
      <CustomSelect v-model="sortOrder" :options="sortOptions" class="w-full sm:w-44 shrink-0" />
    </div>

    <div v-if="loading" class="card flex items-center justify-center py-16">
      <UiSpinner />
    </div>

    <div v-else-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <UiEmptyState
      v-else-if="orders.length === 0"
      :title="tab === 'active' ? 'Нет заказов в работе' : 'Сегодня ничего не собирали'"
      :description="tab === 'active' ? 'Новые заказы появятся здесь' : ''"
    />

    <UiEmptyState v-else-if="filteredOrders.length === 0" title="Ничего не найдено" description="Попробуйте изменить поиск или фильтр" />

    <RouterLink
      v-else
      v-for="order in filteredOrders"
      :key="order.id"
      :to="{ name: 'collector-order-detail', params: { id: order.id } }"
      class="card flex flex-col gap-2 hover:border-primary-300 dark:hover:border-primary-700 transition-colors"
    >
      <div class="flex items-center justify-between gap-2">
        <span class="font-semibold text-gray-900 dark:text-white">
          <span class="text-gray-400 font-normal">{{ shortId(order) }}</span>
          {{ order.customer_name }}
        </span>
        <span :class="`badge-${order.status}`">
          {{ ORDER_STATUS_LABELS[order.status] }}
        </span>
      </div>

      <p class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ itemsSummary(order) }}</p>

      <div v-if="order.collector_name" class="text-xs text-primary-600 dark:text-primary-400">
        Собирает {{ order.collector_name }}
        <template v-if="progress(order)">— собрано {{ progress(order)!.done }} из {{ progress(order)!.total }}</template>
      </div>

      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-400 dark:text-gray-500">
          {{ tab === 'active' ? formatRelativeTime(order.created_at) : formatDateTime(order.created_at, authStore.shop?.timezone ?? undefined) }}
        </span>
        <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatPrice(order.total_price) }}</span>
      </div>
    </RouterLink>
  </div>
</template>
