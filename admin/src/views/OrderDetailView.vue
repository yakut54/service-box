<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/lib/api'

const route = useRoute()
const router = useRouter()

const order = ref<any>(null)
const loading = ref(true)
const updating = ref(false)

function formatPrice(kopecks: number): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(kopecks / 100)
}

const statusLabels: Record<string, string> = { pending: 'Ожидает', paid: 'Оплачен', processing: 'В работе', completed: 'Завершён', cancelled: 'Отменён' }

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

onMounted(async () => {
  try {
    const resp = await api.getOrder(route.params.id as string)
    order.value = resp.data
  } catch { /* not found */ }
  loading.value = false
})

async function updateStatus(status: string) {
  if (!order.value) return
  updating.value = true
  try {
    const resp = await api.updateOrderStatus(order.value.id, status)
    order.value = resp.data
  } catch { /* error */ }
  updating.value = false
}
</script>

<template>
  <div class="max-w-3xl">
    <button @click="router.back()" class="text-gray-500 hover:text-gray-700 mb-4 flex items-center gap-1">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      Назад к заказам
    </button>

    <div v-if="loading" class="card py-12 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <div v-else-if="!order" class="card py-12 text-center">
      <h2 class="text-xl font-semibold text-gray-900 mb-2">Заказ не найден</h2>
      <RouterLink to="/orders" class="btn-primary">Все заказы</RouterLink>
    </div>

    <template v-else>
      <div class="flex items-start justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Заказ #{{ order.id.slice(0, 8) }}</h1>
          <p class="text-gray-500 mt-1">{{ formatDate(order.created_at) }}</p>
        </div>
        <span :class="`badge-${order.status} text-sm px-3 py-1`">{{ statusLabels[order.status] || order.status }}</span>
      </div>

      <div v-if="order.status !== 'completed' && order.status !== 'cancelled'" class="card mb-6">
        <h3 class="font-medium text-gray-900 mb-3">Изменить статус</h3>
        <div class="flex flex-wrap gap-2">
          <button v-if="order.status !== 'processing'" @click="updateStatus('processing')" :disabled="updating" class="btn btn-primary">В работу</button>
          <button v-if="order.status !== 'completed'" @click="updateStatus('completed')" :disabled="updating" class="btn bg-green-600 text-white hover:bg-green-700">Завершить</button>
          <button @click="updateStatus('cancelled')" :disabled="updating" class="btn-danger">Отменить</button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card">
          <h3 class="font-semibold text-gray-900 mb-4">Клиент</h3>
          <div class="space-y-3">
            <div><div class="text-sm text-gray-500">Имя</div><div class="font-medium">{{ order.customer_name }}</div></div>
            <div><div class="text-sm text-gray-500">Телефон</div><a :href="`tel:${order.customer_phone}`" class="font-medium text-primary-600">{{ order.customer_phone }}</a></div>
            <div v-if="order.customer_email"><div class="text-sm text-gray-500">Email</div><a :href="`mailto:${order.customer_email}`" class="font-medium text-primary-600">{{ order.customer_email }}</a></div>
          </div>
        </div>

        <div class="card">
          <h3 class="font-semibold text-gray-900 mb-4">Оплата</h3>
          <div class="space-y-3">
            <div v-if="order.paid_at"><div class="text-sm text-gray-500">Оплачен</div><div>{{ formatDate(order.paid_at) }}</div></div>
            <div><div class="text-sm text-gray-500">Сумма</div><div class="text-xl font-bold text-green-600">{{ formatPrice(order.total_price) }}</div></div>
          </div>
        </div>

        <div v-if="order.shipping_address" class="card">
          <h3 class="font-semibold text-gray-900 mb-4">Доставка</h3>
          <div class="text-gray-700">
            <div>{{ order.shipping_address.city }}</div>
            <div>{{ order.shipping_address.street }}, {{ order.shipping_address.building }}</div>
            <div v-if="order.shipping_address.apartment">кв. {{ order.shipping_address.apartment }}</div>
            <div>{{ order.shipping_address.postal_code }}</div>
          </div>
        </div>

        <div v-if="order.notes" class="card">
          <h3 class="font-semibold text-gray-900 mb-4">Примечание</h3>
          <p class="text-gray-700">{{ order.notes }}</p>
        </div>
      </div>

      <div class="card mt-6">
        <h3 class="font-semibold text-gray-900 mb-4">Состав заказа</h3>
        <div class="divide-y divide-gray-100">
          <div v-for="item in order.items" :key="item.id" class="py-3 flex items-center justify-between">
            <div>
              <div class="font-medium text-gray-900">{{ item.product_name }}</div>
              <div class="text-sm text-gray-500">{{ formatPrice(item.price) }} x {{ item.quantity }}</div>
            </div>
            <div class="font-semibold">{{ formatPrice(item.price * item.quantity) }}</div>
          </div>
        </div>
        <div class="border-t border-gray-200 pt-4 mt-4 flex justify-between">
          <span class="font-semibold text-gray-900">Итого</span>
          <span class="text-xl font-bold text-gray-900">{{ formatPrice(order.total_price) }}</span>
        </div>
      </div>
    </template>
  </div>
</template>
