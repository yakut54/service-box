<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import { useAuthStore } from '@/stores/auth'
import { formatPrice, formatDateTime, formatDate } from '@/shared/lib/format'
import { ORDER_STATUS_LABELS, BOOKING_STATUS_LABELS } from '@/shared/lib/labels'
import { UiSpinner, UiAvatar } from '@/shared/ui'
import UiModal from '@/shared/ui/UiModal.vue'
import type { Customer } from '@/types'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const shopTz = computed(() => authStore.shop?.timezone || 'Europe/Moscow')

const customer = ref<Customer | null>(null)
const loading = ref(true)

// ── Delete dialog ──────────────────────────────────────────────────────────
const showDeleteModal = ref(false)
const deleteConfirmName = ref('')
const deleting = ref(false)
const deleteError = ref('')

const deleteConfirmValid = computed(() =>
  deleteConfirmName.value.trim() === (customer.value?.name ?? '').trim()
)

function openDeleteModal() {
  deleteConfirmName.value = ''
  deleteError.value = ''
  showDeleteModal.value = true
}

async function doDelete() {
  if (!deleteConfirmValid.value || !customer.value) return
  deleting.value = true
  deleteError.value = ''
  try {
    await api.deleteCustomer(customer.value.id)
    showDeleteModal.value = false
    router.push('/customers')
  } catch (e: unknown) {
    deleteError.value = parseApiError(e, 'Не удалось удалить клиента')
  } finally {
    deleting.value = false
  }
}

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
    <div v-if="loading" class="card py-12 flex justify-center">
      <UiSpinner />
    </div>

    <div v-else-if="!customer" class="card py-12 text-center">
      <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Клиент не найден</h2>
      <RouterLink to="/customers" class="btn-primary">Все клиенты</RouterLink>
    </div>

    <template v-else>
      <!-- Header -->
      <div class="flex items-start justify-between mb-6">
        <div class="flex items-center gap-3">
          <UiAvatar :src="customer.avatar_url" :name="customer.name" size="lg" />
          <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ customer.name || 'Без имени' }}</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Клиент с {{ formatDate(customer.created_at) }}</p>
          </div>
        </div>
        <button @click="openDeleteModal" class="btn-danger btn-sm shrink-0">
          Удалить клиента
        </button>
      </div>

      <!-- Stats + Contact -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="card">
          <div class="text-sm text-gray-500 dark:text-gray-400">Контакты</div>
          <div class="mt-2 space-y-2">
            <div v-if="customer.phone">
              <div class="text-xs text-gray-400 dark:text-gray-500">Телефон</div>
              <a :href="`tel:${customer.phone}`" class="font-medium text-primary-600">{{ customer.phone }}</a>
            </div>
            <div v-if="customer.email">
              <div class="text-xs text-gray-400 dark:text-gray-500">Email</div>
              <a :href="`mailto:${customer.email}`" class="font-medium text-primary-600">{{ customer.email }}</a>
            </div>
            <div v-if="customer.notes">
              <div class="text-xs text-gray-400 dark:text-gray-500">Заметка</div>
              <div class="text-sm text-gray-700 dark:text-gray-300">{{ customer.notes }}</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="text-sm text-gray-500 dark:text-gray-400">Заказов</div>
          <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ customer.total_orders || 0 }}</div>
          <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">Последний: {{ formatDate(customer.last_order_at) }}</div>
        </div>
        <div class="card">
          <div class="text-sm text-gray-500 dark:text-gray-400">Потрачено</div>
          <div class="text-2xl font-bold text-green-600 mt-1">{{ formatPrice(customer.total_spent || 0) }}</div>
          <div v-if="customer.total_orders" class="text-xs text-gray-400 dark:text-gray-500 mt-1">
            Средний чек: {{ formatPrice(Math.round((customer.total_spent || 0) / customer.total_orders)) }}
          </div>
        </div>
      </div>

      <!-- Orders -->
      <div class="card mb-6">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">
          История заказов
          <span class="text-gray-400 dark:text-gray-500 font-normal text-sm ml-1">({{ customer.orders?.length || 0 }})</span>
        </h3>

        <div v-if="!customer.orders?.length" class="text-gray-500 dark:text-gray-400 text-sm py-4 text-center">Нет заказов</div>

        <div v-else class="divide-y divide-gray-100 dark:divide-gray-700">
          <div v-for="order in customer.orders" :key="order.id" class="py-3 flex items-center justify-between">
            <div class="flex items-center gap-3 min-w-0">
              <RouterLink :to="`/orders/${order.id}`" class="text-primary-600 hover:text-primary-700 font-medium text-sm shrink-0">
                #{{ order.id.slice(0, 8) }}
              </RouterLink>
              <div class="min-w-0">
                <div class="text-sm text-gray-700 dark:text-gray-300 truncate">
                  {{ order.items?.map((i) => i.quantity > 1 ? `${i.product_name} ×${i.quantity}` : i.product_name).join(', ') || '—' }}
                </div>
                <div class="text-xs text-gray-400 dark:text-gray-500">{{ formatDateTime(order.created_at) }}</div>
              </div>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <span class="font-semibold text-sm dark:text-gray-200">{{ formatPrice(order.total_price) }}</span>
              <span :class="`badge-${order.status}`">{{ ORDER_STATUS_LABELS[order.status] || order.status }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Bookings -->
      <div v-if="customer.bookings" class="card">
        <h3 class="font-semibold text-gray-900 dark:text-white mb-4">
          История записей
          <span class="text-gray-400 dark:text-gray-500 font-normal text-sm ml-1">({{ customer.bookings?.length || 0 }})</span>
        </h3>

        <div v-if="!customer.bookings?.length" class="text-gray-500 dark:text-gray-400 text-sm py-4 text-center">Нет записей</div>

        <div v-else class="divide-y divide-gray-100 dark:divide-gray-700">
          <div v-for="booking in customer.bookings" :key="booking.id" class="py-3 flex items-center justify-between">
            <div class="min-w-0">
              <div class="font-medium text-sm text-gray-900 dark:text-gray-100">{{ booking.service?.name || '—' }}</div>
              <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ formatDateTime(booking.start_time, shopTz) }}
                <span v-if="booking.master"> &middot; <RouterLink :to="`/masters/${booking.master.id}`" class="hover:text-primary-600 dark:hover:text-primary-400">{{ booking.master.name }}</RouterLink></span>
              </div>
            </div>
            <span :class="`badge-${booking.status}`">{{ BOOKING_STATUS_LABELS[booking.status] || booking.status }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>

  <!-- ── Delete Modal ──────────────────────────────────────────────────────── -->
  <UiModal v-model="showDeleteModal" maxWidth="max-w-md">
    <div class="p-6 space-y-5">
      <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Удалить «{{ customer?.name || 'Без имени' }}»?</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
          Будет удалено:
          {{ customer?.total_orders || 0 }} {{ (customer?.total_orders || 0) === 1 ? 'заказ' : (customer?.total_orders || 0) < 5 ? 'заказа' : 'заказов' }},
          {{ customer?.bookings?.length || 0 }} {{ (customer?.bookings?.length || 0) === 1 ? 'запись' : (customer?.bookings?.length || 0) < 5 ? 'записи' : 'записей' }}
        </p>
      </div>

      <div class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
        <p class="text-sm text-red-700 dark:text-red-400">Это действие необратимо. Все заказы, позиции заказов и записи клиента будут удалены навсегда.</p>
      </div>

      <div class="space-y-2">
        <p class="label text-sm">Введите <strong>{{ customer?.name || 'Без имени' }}</strong> для подтверждения</p>
        <input
          v-model="deleteConfirmName"
          type="text"
          class="input"
          :placeholder="customer?.name || 'Без имени'"
          @keydown.enter="deleteConfirmValid && doDelete()"
        />
      </div>

      <p v-if="deleteError" class="text-xs text-red-500">{{ deleteError }}</p>

      <div class="flex gap-3">
        <button @click="showDeleteModal = false" class="btn-secondary flex-1">Отмена</button>
        <button @click="doDelete" class="btn-danger flex-1" :disabled="deleting || !deleteConfirmValid">
          {{ deleting ? 'Удаление...' : 'Удалить навсегда' }}
        </button>
      </div>
    </div>
  </UiModal>
</template>
