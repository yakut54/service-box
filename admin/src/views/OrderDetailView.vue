<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useOrdersStore } from '@/stores/orders'
import { useAuthStore } from '@/stores/auth'
import { formatPrice } from '@/shared/lib/format'
import { ORDER_STATUS_LABELS } from '@/shared/lib/labels'
import { UiSpinner } from '@/shared/ui'

const route      = useRoute()
const ordersStore = useOrdersStore()
const authStore  = useAuthStore()
const shopTz     = computed(() => authStore.shop?.timezone || 'Europe/Moscow')

const order   = ref<any>(null)
const loading = ref(true)
const updating = ref(false)
const errorMsg = ref<string | null>(null)

function formatDate(dateStr: string | null) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleString('ru-RU', {
    day: 'numeric', month: 'long', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
    timeZone: shopTz.value,
  })
}

onMounted(async () => {
  const id = route.params.id as string
  const cached = ordersStore.orders.find(o => o.id === id)
  if (cached) { order.value = cached; loading.value = false }
  try {
    const resp = await api.getOrder(id)
    order.value = resp.data
    errorMsg.value = null
  } catch (err) {
    if (!order.value) {
      errorMsg.value = err instanceof ApiError ? `Ошибка ${err.status}: ${err.message}` : 'Не удалось загрузить заказ'
    }
  } finally {
    loading.value = false
  }
})

async function updateStatus(status: string) {
  if (!order.value) return
  updating.value = true
  try {
    const resp = await api.updateOrderStatus(order.value.id, status)
    order.value = resp.data
  } catch { /* ignore */ }
  updating.value = false
}
</script>

<template>
  <div class="max-w-5xl">

    <!-- Loading -->
    <div v-if="loading" class="card py-16 flex justify-center">
      <UiSpinner />
    </div>

    <!-- Error -->
    <div v-else-if="!order" class="card py-16 text-center">
      <svg class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
      </svg>
      <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Заказ не найден</h2>
      <p v-if="errorMsg" class="text-sm text-red-500 mb-4">{{ errorMsg }}</p>
      <RouterLink to="/orders" class="btn-primary">Все заказы</RouterLink>
    </div>

    <template v-else>
      <!-- Breadcrumb -->
      <nav class="flex items-center gap-2 text-sm text-gray-400 dark:text-gray-500 mb-6">
        <RouterLink to="/orders" class="hover:text-primary-600 dark:hover:text-primary-400 transition-colors">Заказы</RouterLink>
        <span>/</span>
        <span class="text-gray-600 dark:text-gray-300 font-medium">#{{ order.id.slice(0, 8) }}</span>
      </nav>

      <!-- Header -->
      <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Заказ <span class="font-mono">#{{ order.id.slice(0, 8) }}</span></h1>
          <p class="text-gray-500 dark:text-gray-400 mt-0.5 text-sm">{{ formatDate(order.created_at) }}</p>
        </div>
        <span :class="`badge-${order.status} text-sm px-3 py-1.5`">{{ ORDER_STATUS_LABELS[order.status] || order.status }}</span>
      </div>

      <!-- Status actions -->
      <div v-if="!['completed','cancelled'].includes(order.status)" class="card mb-6 flex flex-wrap items-center gap-3">
        <span class="text-sm text-gray-500 dark:text-gray-400 mr-1">Действия:</span>
        <button v-if="order.status === 'pending'" @click="updateStatus('processing')" :disabled="updating" class="btn-primary btn-sm">
          В работу
        </button>
        <button v-if="['pending','paid','processing'].includes(order.status)" @click="updateStatus('completed')" :disabled="updating" class="btn btn-sm bg-emerald-600 text-white hover:bg-emerald-700">
          Завершить
        </button>
        <button @click="updateStatus('cancelled')" :disabled="updating" class="btn-danger btn-sm">
          Отменить
        </button>
        <UiSpinner v-if="updating" size="sm" class="ml-1" />
      </div>

      <!-- Body -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Left: items + shipping + notes -->
        <div class="lg:col-span-2 space-y-6">

          <!-- Items card -->
          <div class="card p-0 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
              <h2 class="font-semibold text-gray-900 dark:text-white">Состав заказа</h2>
              <span class="text-sm text-gray-400">{{ order.items?.length || 0 }} {{ order.items?.length === 1 ? 'позиция' : 'позиции' }}</span>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-700/60">
              <div v-for="item in order.items" :key="item.id" class="flex items-center gap-4 px-5 py-4">
                <!-- Thumbnail -->
                <div class="w-11 h-11 rounded-lg bg-gray-100 dark:bg-gray-700 flex-shrink-0 overflow-hidden">
                  <img v-if="item.product?.image_url" :src="item.product.image_url" :alt="item.product_name" class="w-full h-full object-cover" />
                  <div v-else class="w-full h-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                  </div>
                </div>
                <!-- Name + qty -->
                <div class="flex-1 min-w-0">
                  <RouterLink
                    v-if="item.product_id"
                    :to="`/products/${item.product_id}/edit`"
                    class="font-medium text-gray-900 dark:text-gray-100 hover:text-primary-600 dark:hover:text-primary-400 truncate block transition-colors"
                  >{{ item.product_name }}</RouterLink>
                  <span v-else class="font-medium text-gray-900 dark:text-gray-100 truncate block">{{ item.product_name }}</span>
                  <div class="text-sm text-gray-400 dark:text-gray-500 mt-0.5">{{ formatPrice(item.price) }} × {{ item.quantity }}</div>
                </div>
                <!-- Line total -->
                <div class="font-semibold text-gray-900 dark:text-white shrink-0 tabular-nums">{{ formatPrice(item.price * item.quantity) }}</div>
              </div>
            </div>

            <!-- Totals -->
            <div class="px-5 py-4 bg-gray-50 dark:bg-gray-800/60 border-t border-gray-100 dark:border-gray-700 space-y-2">
              <div v-if="order.discount_amount > 0" class="flex justify-between text-sm">
                <span class="text-gray-500 dark:text-gray-400">Скидка</span>
                <span class="text-emerald-600 dark:text-emerald-400 font-medium">−{{ formatPrice(order.discount_amount) }}</span>
              </div>
              <div class="flex justify-between items-center pt-1">
                <span class="font-semibold text-gray-900 dark:text-white">Итого</span>
                <span class="text-xl font-bold text-gray-900 dark:text-white tabular-nums">{{ formatPrice(order.total_price) }}</span>
              </div>
            </div>
          </div>

          <!-- Shipping -->
          <div v-if="order.shipping_address" class="card">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
              Доставка
            </h3>
            <p class="text-gray-700 dark:text-gray-300 leading-relaxed">
              <span v-if="order.shipping_address.city">{{ order.shipping_address.city }}, </span>
              <span v-if="order.shipping_address.street">{{ order.shipping_address.street }}</span>
              <span v-if="order.shipping_address.building">, {{ order.shipping_address.building }}</span>
              <span v-if="order.shipping_address.apartment">, кв. {{ order.shipping_address.apartment }}</span>
              <span v-if="order.shipping_address.postal_code"><br/>{{ order.shipping_address.postal_code }}</span>
            </p>
          </div>

          <!-- Notes -->
          <div v-if="order.notes" class="card">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-2 flex items-center gap-2">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
              </svg>
              Примечание
            </h3>
            <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">{{ order.notes }}</p>
          </div>
        </div>

        <!-- Right sidebar -->
        <div class="space-y-4">

          <!-- Customer -->
          <div class="card">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">Клиент</div>
            <div class="flex items-center gap-3 mb-4">
              <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 flex items-center justify-center font-bold text-sm flex-shrink-0 select-none">
                {{ order.customer_name?.charAt(0)?.toUpperCase() || '?' }}
              </div>
              <div class="min-w-0">
                <RouterLink
                  v-if="order.customer_id"
                  :to="`/customers/${order.customer_id}`"
                  class="font-semibold text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 transition-colors truncate block"
                >{{ order.customer_name }}</RouterLink>
                <div v-else class="font-semibold text-gray-900 dark:text-white truncate">{{ order.customer_name }}</div>
              </div>
            </div>
            <div class="space-y-2 text-sm">
              <a :href="`tel:${order.customer_phone}`" class="flex items-center gap-2 text-primary-600 dark:text-primary-400 hover:text-primary-700 transition-colors">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                {{ order.customer_phone }}
              </a>
              <a v-if="order.customer_email" :href="`mailto:${order.customer_email}`" class="flex items-center gap-2 text-primary-600 dark:text-primary-400 hover:text-primary-700 transition-colors truncate">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                {{ order.customer_email }}
              </a>
            </div>
            <RouterLink v-if="order.customer_id" :to="`/customers/${order.customer_id}`" class="btn-secondary btn-sm w-full mt-4 text-center">
              Профиль клиента →
            </RouterLink>
          </div>

          <!-- Payment -->
          <div class="card">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">Оплата</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums mb-1">{{ formatPrice(order.total_price) }}</div>
            <div v-if="order.paid_at" class="flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              Оплачен {{ formatDate(order.paid_at) }}
            </div>
            <div v-else class="flex items-center gap-1.5 text-sm text-amber-500">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              Ожидает оплаты
            </div>
          </div>

        </div>
      </div>
    </template>
  </div>
</template>
