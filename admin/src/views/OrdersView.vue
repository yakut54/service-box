<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useOrdersStore } from '@/stores/orders'
import CustomSelect from '@/components/CustomSelect.vue'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import UiSpinner from '@/shared/ui/UiSpinner.vue'
import { plural } from '@/lib/utils'
import { formatPrice, formatDateTime } from '@/shared/lib/format'
import { ORDER_STATUS_LABELS } from '@/shared/lib/labels'

const ordersStore = useOrdersStore()
const filterStatus = ref('')
const searchQuery = ref('')
const deleteConfirm = ref<string | null>(null)
const deleting = ref(false)

async function handleDelete(id: string) {
  deleting.value = true
  await ordersStore.deleteOrder(id)
  deleteConfirm.value = null
  deleting.value = false
}

const statusOptions = [
  { value: '', label: 'Все статусы' },
  { value: 'pending', label: 'Ожидают' },
  { value: 'paid', label: 'Оплачены' },
  { value: 'processing', label: 'В работе' },
  { value: 'completed', label: 'Завершены' },
  { value: 'cancelled', label: 'Отменены' },
]

onMounted(() => { ordersStore.fetchOrders() })

async function applyFilters() {
  const params: Record<string, string> = {}
  if (filterStatus.value) params.status = filterStatus.value
  if (searchQuery.value) params.search = searchQuery.value
  await ordersStore.fetchOrders(params)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Заказы</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">{{ ordersStore.orders.length }} {{ plural(ordersStore.orders.length, 'заказ', 'заказа', 'заказов') }}</p>
      </div>
    </div>

    <div class="card mb-6">
      <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
          <input v-model="searchQuery" @input="applyFilters" type="text" class="input" placeholder="Поиск по клиенту..." />
        </div>
        <CustomSelect v-model="filterStatus" @change="applyFilters" :options="statusOptions" class="w-full sm:w-48" />
      </div>
    </div>

    <div v-if="ordersStore.loading" class="card py-12 flex justify-center">
      <UiSpinner />
    </div>

    <div v-else-if="ordersStore.orders.length === 0" class="card py-12 text-center">
      <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Нет заказов</h3>
      <p class="text-gray-500 dark:text-gray-400">Заказы появятся, когда клиенты начнут покупать</p>
    </div>

    <div v-else>
      <!-- Desktop table -->
      <div class="card overflow-hidden p-0 hidden sm:block">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr><th>Заказ</th><th>Клиент</th><th>Товары</th><th>Сумма</th><th>Статус</th><th>Дата</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="order in ordersStore.orders" :key="order.id">
                <td><RouterLink :to="`/orders/${order.id}`" class="text-primary-600 hover:text-primary-700 font-medium">#{{ order.id.slice(0, 8) }}</RouterLink></td>
                <td>
                  <RouterLink v-if="order.customer_id" :to="`/customers/${order.customer_id}`" class="font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">{{ order.customer_name }}</RouterLink>
                  <div v-else class="font-medium text-gray-900 dark:text-gray-100">{{ order.customer_name }}</div>
                  <div class="text-sm text-gray-500 dark:text-gray-400">{{ order.customer_phone }}</div>
                </td>
                <td>
                  <div class="text-sm text-gray-500 dark:text-gray-400 mb-0.5">{{ order.items?.length || 0 }} поз.</div>
                  <div class="flex flex-wrap gap-1">
                    <span
                      v-for="item in order.items"
                      :key="item.id"
                      class="text-xs text-gray-600 dark:text-gray-400 truncate max-w-[180px]"
                    >{{ item.product_name }}</span>
                  </div>
                </td>
                <td class="font-semibold dark:text-gray-100">{{ formatPrice(order.total_price) }}</td>
                <td><span :class="`badge-${order.status}`">{{ ORDER_STATUS_LABELS[order.status] || order.status }}</span></td>
                <td class="text-sm text-gray-500 dark:text-gray-400">{{ formatDateTime(order.created_at) }}</td>
                <td>
                  <div class="flex items-center gap-1">
                    <RouterLink :to="`/orders/${order.id}`" class="btn-ghost btn-sm">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    </RouterLink>
                    <button @click="deleteConfirm = order.id" class="btn-ghost btn-sm text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20" title="Удалить">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile cards -->
      <div class="sm:hidden card overflow-hidden p-0">
        <div
          v-for="order in ordersStore.orders"
          :key="order.id"
          class="flex items-start justify-between gap-3 p-4 border-b border-gray-100 dark:border-gray-800 last:border-0"
        >
          <RouterLink :to="`/orders/${order.id}`" class="min-w-0 flex-1">
            <div class="flex items-center gap-2 mb-0.5">
              <span class="text-primary-600 font-medium text-sm">#{{ order.id.slice(0, 8) }}</span>
              <span :class="`badge-${order.status}`">{{ ORDER_STATUS_LABELS[order.status] || order.status }}</span>
            </div>
            <div class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ order.customer_name }}</div>
            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ formatDateTime(order.created_at) }} · {{ order.items?.length || 0 }} поз.</div>
          </RouterLink>
          <div class="flex items-center gap-2 shrink-0">
            <span class="font-semibold text-sm text-gray-900 dark:text-white">{{ formatPrice(order.total_price) }}</span>
            <button @click="deleteConfirm = order.id" class="btn-ghost btn-sm text-red-500 hover:text-red-700" title="Удалить">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <UiConfirmDialog
      :modelValue="!!deleteConfirm"
      @update:modelValue="if (!$event) deleteConfirm = null"
      title="Удалить заказ?"
      :loading="deleting"
      @confirm="handleDelete(deleteConfirm!)"
    >
      Заказ и все его позиции будут удалены безвозвратно.
    </UiConfirmDialog>
  </div>
</template>
