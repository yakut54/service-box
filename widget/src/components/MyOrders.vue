<script setup lang="ts">
import { ref } from 'vue'
import { useShopStore } from '@/stores/shop'
import { formatPrice } from '@/lib/utils'
import SbPhoneAuth from '@/components/SbPhoneAuth.vue'
import SbButton from '@/components/SbButton.vue'
import type { WidgetOrder } from '@/types'

const emit = defineEmits<{ back: [] }>()

const shopStore = useShopStore()
const sbAuth    = ref<InstanceType<typeof SbPhoneAuth>>()

const orders    = ref<WidgetOrder[]>([])
const loading   = ref(false)
const searched  = ref(false)
const error     = ref('')

const statusLabels: Record<string, string> = {
  pending:         'Ожидает',
  paid:            'Оплачен',
  processing:      'В работе',
  completed:       'Завершён',
  cancelled:       'Отменён',
  needs_attention: 'Требует внимания',
}

const statusBadge: Record<string, string> = {
  pending:         'sb-badge-warning',
  paid:            'sb-badge-success',
  processing:      'sb-badge-info',
  completed:       'sb-badge-success',
  cancelled:       'sb-badge-danger',
  needs_attention: 'sb-badge-danger',
}

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

async function loadOrders(phone: string, token: string) {
  loading.value  = true
  error.value    = ''
  searched.value = true

  try {
    const resp = await shopStore.getApi().getOrdersByPhone(phone, token)
    orders.value = resp.data
  } catch (e: unknown) {
    const err = e as { status?: number; message?: string }
    if (err.status === 401) {
      orders.value   = []
      searched.value = false
      sbAuth.value?.reset('Сессия истекла. Подтвердите телефон заново.')
    } else {
      error.value = err.message || 'Ошибка загрузки'
    }
  }
  loading.value = false
}

function handleLogout() {
  orders.value   = []
  searched.value = false
  error.value    = ''
}
</script>

<template>
  <div>
    <div class="sb-flex sb-items-center sb-gap-3 sb-mb-4">
      <SbButton variant="ghost" @click="emit('back')">
        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
      </SbButton>
      <h2 class="sb-title" style="margin-bottom: 0;">Мои заказы</h2>
    </div>

    <SbPhoneAuth
      ref="sbAuth"
      hint="Введите номер телефона, чтобы найти свои заказы"
      @authenticated="loadOrders"
      @logout="handleLogout"
    >
      <!-- Loading -->
      <div v-if="loading" class="sb-empty">
        <div class="sb-skeleton" style="width: 40px; height: 40px; border-radius: 50%; margin: 0 auto 16px;"></div>
        <p class="sb-empty-text">Загрузка заказов...</p>
      </div>

      <!-- Error -->
      <div v-else-if="error" class="sb-empty">
        <p class="sb-empty-title">Ошибка</p>
        <p class="sb-empty-text">{{ error }}</p>
      </div>

      <!-- No orders -->
      <div v-else-if="searched && orders.length === 0" class="sb-empty">
        <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
        </svg>
        <p class="sb-empty-title">Заказов не найдено</p>
        <p class="sb-empty-text">По этому номеру телефона заказов пока нет</p>
      </div>

      <!-- Orders list -->
      <div v-else-if="orders.length > 0" class="sb-flex sb-flex-col sb-gap-3">
        <div v-for="order in orders" :key="order.id" class="sb-card">
          <div class="sb-flex sb-items-center sb-justify-between sb-mb-2">
            <span style="font-size: 14px; font-weight: 600; color: var(--sb-text);">#{{ order.id?.slice(0, 8) }}</span>
            <span :class="['sb-badge', statusBadge[order.status] || 'sb-badge-info']">{{ statusLabels[order.status] || order.status }}</span>
          </div>

          <!-- Items -->
          <div v-if="order.items?.length" style="margin-bottom: 8px;">
            <div v-for="item in order.items" :key="item.id" class="sb-flex sb-justify-between sb-items-center" style="padding: 4px 0; font-size: 14px;">
              <span style="color: var(--sb-text);">{{ item.product_name }} <span style="color: var(--sb-text-muted);">&times;{{ item.quantity }}</span></span>
              <span style="font-weight: 500; color: var(--sb-text);">{{ formatPrice(item.price * item.quantity) }}</span>
            </div>
          </div>

          <hr class="sb-divider" />

          <div class="sb-flex sb-justify-between sb-items-center">
            <span class="sb-subtitle">{{ formatDate(order.created_at) }}</span>
            <span class="sb-price">{{ formatPrice(order.total_price) }}</span>
          </div>
        </div>
      </div>
    </SbPhoneAuth>
  </div>
</template>
