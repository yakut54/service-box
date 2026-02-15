<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/lib/api'

const route = useRoute()
const router = useRouter()

const customer = ref<any>(null)
const loading = ref(true)

function formatPrice(kopecks: number): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(kopecks / 100)
}

function formatDate(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function formatDateShort(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })
}

const orderStatusLabels: Record<string, string> = { pending: 'Ожидает', paid: 'Оплачен', processing: 'В работе', completed: 'Завершён', cancelled: 'Отменён' }
const bookingStatusLabels: Record<string, string> = { pending: 'Ожидает', confirmed: 'Подтверждена', completed: 'Завершена', cancelled: 'Отменена', no_show: 'Неявка' }

onMounted(async () => {
  try {
    const resp = await api.getCustomer(route.params.id as string)
    customer.value = resp.data
  } catch { /* not found */ }
  loading.value = false
})
</script>

<template>
  <div class="max-w-4xl">
    <button @click="router.back()" class="text-gray-500 hover:text-gray-700 mb-4 flex items-center gap-1">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      Назад к клиентам
    </button>

    <div v-if="loading" class="card py-12 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <div v-else-if="!customer" class="card py-12 text-center">
      <h2 class="text-xl font-semibold text-gray-900 mb-2">Клиент не найден</h2>
      <RouterLink to="/customers" class="btn-primary">Все клиенты</RouterLink>
    </div>

    <template v-else>
      <!-- Header -->
      <div class="flex items-start justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">{{ customer.name || 'Без имени' }}</h1>
          <p class="text-gray-500 mt-1">Клиент с {{ formatDateShort(customer.created_at) }}</p>
        </div>
      </div>

      <!-- Stats + Contact -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="card">
          <div class="text-sm text-gray-500">Контакты</div>
          <div class="mt-2 space-y-2">
            <div v-if="customer.phone">
              <div class="text-xs text-gray-400">Телефон</div>
              <a :href="`tel:${customer.phone}`" class="font-medium text-primary-600">{{ customer.phone }}</a>
            </div>
            <div v-if="customer.email">
              <div class="text-xs text-gray-400">Email</div>
              <a :href="`mailto:${customer.email}`" class="font-medium text-primary-600">{{ customer.email }}</a>
            </div>
            <div v-if="customer.notes">
              <div class="text-xs text-gray-400">Заметка</div>
              <div class="text-sm text-gray-700">{{ customer.notes }}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="text-sm text-gray-500">Заказов</div>
          <div class="text-2xl font-bold text-gray-900 mt-1">{{ customer.total_orders || 0 }}</div>
          <div class="text-xs text-gray-400 mt-1">Последний: {{ formatDateShort(customer.last_order_at) }}</div>
        </div>
        <div class="card">
          <div class="text-sm text-gray-500">Потрачено</div>
          <div class="text-2xl font-bold text-green-600 mt-1">{{ formatPrice(customer.total_spent || 0) }}</div>
          <div v-if="customer.total_orders" class="text-xs text-gray-400 mt-1">
            Средний чек: {{ formatPrice(Math.round((customer.total_spent || 0) / customer.total_orders)) }}
          </div>
        </div>
      </div>

      <!-- Orders -->
      <div class="card mb-6">
        <h3 class="font-semibold text-gray-900 mb-4">
          История заказов
          <span class="text-gray-400 font-normal text-sm ml-1">({{ customer.orders?.length || 0 }})</span>
        </h3>

        <div v-if="!customer.orders?.length" class="text-gray-500 text-sm py-4 text-center">Нет заказов</div>

        <div v-else class="divide-y divide-gray-100">
          <div v-for="order in customer.orders" :key="order.id" class="py-3 flex items-center justify-between">
            <div class="flex items-center gap-3 min-w-0">
              <RouterLink :to="`/orders/${order.id}`" class="text-primary-600 hover:text-primary-700 font-medium text-sm shrink-0">
                #{{ order.id.slice(0, 8) }}
              </RouterLink>
              <div class="min-w-0">
                <div class="text-sm text-gray-700 truncate">
                  {{ order.items?.map((i: any) => i.product_name).join(', ') || '—' }}
                </div>
                <div class="text-xs text-gray-400">{{ formatDate(order.created_at) }}</div>
              </div>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <span class="font-semibold text-sm">{{ formatPrice(order.total_price) }}</span>
              <span :class="`badge-${order.status}`">{{ orderStatusLabels[order.status] || order.status }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Bookings -->
      <div v-if="customer.bookings" class="card">
        <h3 class="font-semibold text-gray-900 mb-4">
          История записей
          <span class="text-gray-400 font-normal text-sm ml-1">({{ customer.bookings?.length || 0 }})</span>
        </h3>

        <div v-if="!customer.bookings?.length" class="text-gray-500 text-sm py-4 text-center">Нет записей</div>

        <div v-else class="divide-y divide-gray-100">
          <div v-for="booking in customer.bookings" :key="booking.id" class="py-3 flex items-center justify-between">
            <div class="min-w-0">
              <div class="font-medium text-sm text-gray-900">{{ booking.service?.name || '—' }}</div>
              <div class="text-xs text-gray-500">
                {{ formatDate(booking.start_time) }}
                <span v-if="booking.master"> &middot; {{ booking.master.name }}</span>
              </div>
            </div>
            <span :class="`badge-${booking.status}`">{{ bookingStatusLabels[booking.status] || booking.status }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
