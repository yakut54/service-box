<script setup lang="ts">
import { ref, computed } from 'vue'
import { formatPrice } from '@/lib/utils'
import { useShopStore } from '@/stores/shop'
import type { WidgetOrder } from '@/types'
import SbButton from '@/components/SbButton.vue'

const props = defineProps<{ order: WidgetOrder | null }>()
const emit = defineEmits<{ back: [] }>()

const shopStore = useShopStore()

const PAYMENT_HOSTS = ['yookassa.ru', 'api.yookassa.ru', 'yoomoney.ru', 'robokassa.ru']

function openPayment(url: string) {
  try {
    const host = new URL(url).hostname
    if (!PAYMENT_HOSTS.includes(host)) return
    window.open(url, '_blank', 'noopener,noreferrer')
  } catch { /* invalid URL — ignore */ }
}

// createOrder() никогда не отдаёт payment_url сразу (оплата у обычных
// заказов отдельным шагом, см. PaymentController::createOrderPayment) —
// ссылку создаём лениво здесь же, тем же приёмом, что уже есть у
// BookingSuccess.vue для предоплаты записи.
const paymentLoading = ref(false)
const paymentError   = ref('')

const needsPayment = computed(() =>
  !!(shopStore.shop?.online_payment_enabled && props.order && !props.order.payment_url)
)

async function payNow() {
  if (!props.order) return
  paymentLoading.value = true
  paymentError.value   = ''
  try {
    const res = await shopStore.getApi().createOrderPayment(props.order.id)
    openPayment(res.payment_url)
  } catch {
    paymentError.value = 'Не удалось создать платёж. Попробуйте позже.'
  } finally {
    paymentLoading.value = false
  }
}
</script>

<template>
  <div class="sb-success">
    <div class="sb-success-icon">
      <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </div>

    <h2 class="sb-success-title">Заказ оформлен!</h2>
    <p class="sb-success-text">
      <template v-if="order?.customer_email">Спасибо за покупку. Детали заказа отправлены на ваш email.</template>
      <template v-else>Спасибо за покупку!</template>
    </p>

    <div v-if="order" class="sb-success-details">
      <div class="sb-success-row">
        <span>Номер заказа</span>
        <span class="sb-success-value">#{{ order.id?.slice(0, 8) }}</span>
      </div>
      <div v-if="order.total_price" class="sb-success-row">
        <span>Сумма</span>
        <span class="sb-success-value">{{ formatPrice(order.total_price) }}</span>
      </div>
      <div class="sb-success-row">
        <span>Статус</span>
        <span v-if="order.payment_url || needsPayment" class="sb-badge sb-badge-warning">Ожидает оплаты</span>
        <span v-else class="sb-badge sb-badge-success">Заказ принят</span>
      </div>
    </div>

    <SbButton
      v-if="order?.payment_url"
      block
      class="sb-mt-4"
      @click="openPayment(order.payment_url)"
    >
      Оплатить заказ
    </SbButton>

    <template v-else-if="needsPayment">
      <p v-if="paymentError" class="sb-prepayment-error">{{ paymentError }}</p>
      <SbButton block class="sb-mt-4" :loading="paymentLoading" @click="payNow">
        {{ paymentLoading ? 'Переход к оплате...' : 'Оплатить заказ' }}
      </SbButton>
    </template>

    <SbButton
      :variant="(order?.payment_url || needsPayment) ? 'secondary' : 'primary'"
      block
      :class="(order?.payment_url || needsPayment) ? 'sb-mt-2' : 'sb-mt-4'"
      @click="emit('back')"
    >
      Вернуться в каталог
    </SbButton>
  </div>
</template>
