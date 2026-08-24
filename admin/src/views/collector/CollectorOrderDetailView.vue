<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/composables/useToast'
import { formatPrice } from '@/shared/lib/format'
import { ORDER_STATUS_LABELS } from '@/shared/lib/labels'
import { UiSpinner } from '@/shared/ui'
import type { Order } from '@/types'

const route   = useRoute()
const toast   = useToast()

const order    = ref<Order | null>(null)
const loading  = ref(true)
const updating = ref(false)
const error    = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const resp = await api.getOrder(route.params.id as string)
    order.value = resp.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить заказ'
  } finally {
    loading.value = false
  }
}

async function setStatus(status: string) {
  if (!order.value) return
  updating.value = true
  try {
    const resp = await api.updateOrderStatus(order.value.id, status)
    order.value = resp.data
    toast.success('Статус обновлён')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось изменить статус')
  } finally {
    updating.value = false
  }
}

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', {
    day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
  })
}

onMounted(load)
</script>

<template>
  <div class="p-4 space-y-4">
    <RouterLink :to="{ name: 'collector-orders' }" class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      Все заказы
    </RouterLink>

    <div v-if="loading" class="card flex items-center justify-center py-16">
      <UiSpinner />
    </div>

    <div v-else-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <template v-else-if="order">
      <div class="card space-y-3">
        <div class="flex items-center justify-between">
          <h1 class="text-lg font-semibold text-gray-900 dark:text-white">{{ order.customer_name }}</h1>
          <span class="text-xs text-gray-400 dark:text-gray-500">{{ formatDate(order.created_at) }}</span>
        </div>

        <a v-if="order.customer_phone" :href="`tel:${order.customer_phone}`" class="text-sm text-primary-600 dark:text-primary-400">
          {{ order.customer_phone }}
        </a>

        <div v-if="order.shipping_address" class="text-sm text-gray-600 dark:text-gray-300">
          {{ order.shipping_address.city }}, {{ order.shipping_address.street }} {{ order.shipping_address.building }}
          <span v-if="order.shipping_address.apartment">, кв. {{ order.shipping_address.apartment }}</span>
        </div>
        <div v-else-if="order.delivery_method" class="text-sm text-gray-600 dark:text-gray-300">
          {{ order.delivery_method }}
        </div>

        <p v-if="order.notes" class="text-sm text-gray-500 dark:text-gray-400 italic">«{{ order.notes }}»</p>
      </div>

      <!-- Items -->
      <div class="card space-y-2">
        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Состав заказа</h2>
        <div
          v-for="item in order.items"
          :key="item.id"
          class="flex items-center justify-between py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-0"
        >
          <span class="text-gray-900 dark:text-white">{{ item.product_name }} <span class="text-gray-400">× {{ item.quantity }}</span></span>
          <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatPrice(item.price * item.quantity) }}</span>
        </div>
        <div class="flex items-center justify-between pt-2 font-semibold text-gray-900 dark:text-white">
          <span>Итого</span>
          <span>{{ formatPrice(order.total_price) }}</span>
        </div>
      </div>

      <!-- Status -->
      <div class="card space-y-3">
        <div class="flex items-center justify-between">
          <span class="text-sm text-gray-500 dark:text-gray-400">Статус</span>
          <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
            {{ ORDER_STATUS_LABELS[order.status] }}
          </span>
        </div>

        <div v-if="order.status !== 'completed' && order.status !== 'cancelled'" class="flex gap-2">
          <button
            v-if="order.status !== 'processing'"
            @click="setStatus('processing')"
            :disabled="updating"
            class="btn-secondary btn-sm flex-1"
          >
            В сборку
          </button>
          <button
            @click="setStatus('completed')"
            :disabled="updating"
            class="btn-primary btn-sm flex-1"
          >
            Готово
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
