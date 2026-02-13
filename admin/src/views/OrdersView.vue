<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useOrdersStore } from '@/stores/orders'

const ordersStore = useOrdersStore()
const filterStatus = ref('')
const searchQuery = ref('')

function formatPrice(kopecks: number): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(kopecks / 100)
}

const statusLabels: Record<string, string> = { pending: 'Ожидает', paid: 'Оплачен', processing: 'В работе', completed: 'Завершён', cancelled: 'Отменён' }

onMounted(() => { ordersStore.fetchOrders() })

async function applyFilters() {
  const params: Record<string, string> = {}
  if (filterStatus.value) params.status = filterStatus.value
  if (searchQuery.value) params.search = searchQuery.value
  await ordersStore.fetchOrders(params)
}

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Заказы</h1>
        <p class="text-gray-500 mt-1">{{ ordersStore.orders.length }} заказов</p>
      </div>
    </div>

    <div class="card mb-6">
      <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
          <input v-model="searchQuery" @input="applyFilters" type="text" class="input" placeholder="Поиск по клиенту..." />
        </div>
        <select v-model="filterStatus" @change="applyFilters" class="input w-full sm:w-48">
          <option value="">Все статусы</option>
          <option value="pending">Ожидают</option>
          <option value="paid">Оплачены</option>
          <option value="processing">В работе</option>
          <option value="completed">Завершены</option>
          <option value="cancelled">Отменены</option>
        </select>
      </div>
    </div>

    <div v-if="ordersStore.loading" class="card py-12 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <div v-else-if="ordersStore.orders.length === 0" class="card py-12 text-center">
      <h3 class="text-lg font-medium text-gray-900 mb-2">Нет заказов</h3>
      <p class="text-gray-500">Заказы появятся, когда клиенты начнут покупать</p>
    </div>

    <div v-else class="card overflow-hidden p-0">
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr><th>Заказ</th><th>Клиент</th><th>Товары</th><th>Сумма</th><th>Статус</th><th>Дата</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="order in ordersStore.orders" :key="order.id">
              <td><RouterLink :to="`/orders/${order.id}`" class="text-primary-600 hover:text-primary-700 font-medium">#{{ order.id.slice(0, 8) }}</RouterLink></td>
              <td>
                <div class="font-medium text-gray-900">{{ order.customer_name }}</div>
                <div class="text-sm text-gray-500">{{ order.customer_phone }}</div>
              </td>
              <td>
                <div class="text-sm">{{ order.items?.length || 0 }} поз.</div>
                <div class="text-xs text-gray-500 line-clamp-1">{{ order.items?.map((i: any) => i.product_name).join(', ') }}</div>
              </td>
              <td class="font-semibold">{{ formatPrice(order.total_price) }}</td>
              <td><span :class="`badge-${order.status}`">{{ statusLabels[order.status] || order.status }}</span></td>
              <td class="text-sm text-gray-500">{{ formatDate(order.created_at) }}</td>
              <td>
                <RouterLink :to="`/orders/${order.id}`" class="btn-ghost btn-sm">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
