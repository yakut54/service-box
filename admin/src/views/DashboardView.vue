<script setup lang="ts">
import { onMounted, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useOrdersStore } from '@/stores/orders'
import { useProductsStore } from '@/stores/products'

const authStore = useAuthStore()
const ordersStore = useOrdersStore()
const productsStore = useProductsStore()

function formatPrice(kopecks: number): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(kopecks / 100)
}

onMounted(async () => {
  await Promise.all([
    ordersStore.fetchOrders(),
    ordersStore.fetchStats(),
    productsStore.fetchProducts(),
  ])
})

const recentOrders = computed(() => ordersStore.orders.slice(0, 5))
</script>

<template>
  <div>
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">{{ authStore.shop?.name }}</h1>
      <p class="text-gray-500 mt-1">Вот что происходит в вашем магазине</p>
    </div>

    <!-- Stats cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <div class="card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-500">Всего заказов</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ ordersStore.stats.total_orders }}</p>
          </div>
          <div class="w-12 h-12 bg-primary-100 rounded-xl flex items-center justify-center">
            <svg class="w-6 h-6 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
            </svg>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-500">Выручка</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ formatPrice(ordersStore.stats.total_revenue || 0) }}</p>
          </div>
          <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-500">Ожидают обработки</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ ordersStore.pendingOrders.length }}</p>
          </div>
          <div class="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center">
            <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-500">Товаров</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ productsStore.activeProducts.length }}</p>
          </div>
          <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent orders -->
    <div class="card">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Последние заказы</h2>
        <RouterLink to="/orders" class="text-sm text-primary-600 hover:text-primary-700">Все заказы &rarr;</RouterLink>
      </div>

      <div v-if="ordersStore.loading" class="py-8 text-center text-gray-500">Загрузка...</div>

      <div v-else-if="recentOrders.length === 0" class="py-8 text-center text-gray-500">Пока нет заказов</div>

      <table v-else class="table">
        <thead>
          <tr>
            <th>Заказ</th>
            <th>Клиент</th>
            <th>Сумма</th>
            <th>Статус</th>
            <th>Дата</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="order in recentOrders" :key="order.id">
            <td>
              <RouterLink :to="`/orders/${order.id}`" class="text-primary-600 hover:text-primary-700 font-medium">
                #{{ order.id.slice(0, 8) }}
              </RouterLink>
            </td>
            <td>
              <div>{{ order.customer_name }}</div>
              <div class="text-xs text-gray-500">{{ order.customer_phone }}</div>
            </td>
            <td class="font-medium">{{ formatPrice(order.total_price) }}</td>
            <td>
              <span :class="`badge-${order.status}`">
                {{ order.status === 'pending' ? 'Ожидает' : order.status === 'paid' ? 'Оплачен' : order.status === 'processing' ? 'В работе' : order.status === 'completed' ? 'Завершён' : 'Отменён' }}
              </span>
            </td>
            <td class="text-gray-500 text-sm">{{ new Date(order.created_at).toLocaleDateString('ru-RU') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Quick actions -->
    <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-4">
      <RouterLink to="/products/new" class="card hover:border-primary-300 hover:shadow-md transition-all group">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 bg-primary-100 rounded-xl flex items-center justify-center group-hover:bg-primary-200 transition-colors">
            <svg class="w-6 h-6 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
          </div>
          <div>
            <div class="font-medium text-gray-900">Добавить товар</div>
            <div class="text-sm text-gray-500">Создайте новый товар или услугу</div>
          </div>
        </div>
      </RouterLink>

      <RouterLink to="/orders" class="card hover:border-primary-300 hover:shadow-md transition-all group">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center group-hover:bg-yellow-200 transition-colors">
            <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
          </div>
          <div>
            <div class="font-medium text-gray-900">Обработать заказы</div>
            <div class="text-sm text-gray-500">{{ ordersStore.pendingOrders.length }} ожидают</div>
          </div>
        </div>
      </RouterLink>

      <RouterLink to="/settings" class="card hover:border-primary-300 hover:shadow-md transition-all group">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center group-hover:bg-gray-200 transition-colors">
            <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            </svg>
          </div>
          <div>
            <div class="font-medium text-gray-900">Настройки</div>
            <div class="text-sm text-gray-500">Виджет, платежи, Telegram</div>
          </div>
        </div>
      </RouterLink>
    </div>
  </div>
</template>
