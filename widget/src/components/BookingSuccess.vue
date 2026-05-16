<script setup lang="ts">
import { ref, computed } from 'vue'
import { formatPrice, formatDateTime } from '@/lib/utils'
import { useShopStore } from '@/stores/shop'
import type { WidgetBooking, WidgetProduct } from '@/types'
import SbButton from '@/components/SbButton.vue'

const props = defineProps<{ booking: WidgetBooking | null; product: WidgetProduct | null }>()
const emit = defineEmits<{ back: [] }>()

const shopStore = useShopStore()
const tz = () => shopStore.shop?.timezone || 'Europe/Moscow'

const paymentLoading = ref(false)
const paymentError   = ref('')

const needsPrepayment = computed(() =>
  !!(shopStore.shop?.prepayment_enabled && props.booking && !props.booking.payment_url)
)

async function payNow() {
  if (!props.booking) return
  paymentLoading.value = true
  paymentError.value   = ''
  try {
    const res = await shopStore.getApi().createBookingPayment(props.booking.id)
    window.location.href = res.payment_url
  } catch {
    paymentError.value = 'Не удалось создать платёж. Попробуйте позже.'
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

    <h2 class="sb-success-title">Вы записаны!</h2>
    <p class="sb-success-text">Ваша запись оформлена. Ожидайте подтверждения.</p>

    <div v-if="booking" class="sb-success-details">
      <div class="sb-success-row">
        <span>Услуга</span>
        <span class="sb-success-value">{{ booking.service?.name || product?.name }}</span>
      </div>
      <div v-if="booking.start_time" class="sb-success-row">
        <span>Дата и время</span>
        <span class="sb-success-value">{{ formatDateTime(booking.start_time, tz()) }}</span>
      </div>
      <div v-if="booking.master && shopStore.shop?.widget_config?.show_master_name !== false" class="sb-success-row">
        <span>Мастер</span>
        <span class="sb-success-value">{{ booking.master.name }}</span>
      </div>
      <div v-if="product?.price" class="sb-success-row">
        <span>Стоимость</span>
        <span class="sb-success-value">{{ formatPrice(product.price) }}</span>
      </div>
      <div class="sb-success-row">
        <span>Статус</span>
        <span class="sb-badge sb-badge-warning">Ожидает подтверждения</span>
      </div>
    </div>

    <!-- Prepayment block -->
    <div v-if="needsPrepayment" class="sb-prepayment-section">
      <p class="sb-prepayment-hint">
        <span v-if="shopStore.shop!.prepayment_amount > 0">
          Для подтверждения записи оплатите предоплату
          {{ formatPrice(shopStore.shop!.prepayment_amount) }}
        </span>
        <span v-else>Для подтверждения записи оплатите услугу онлайн</span>
      </p>
      <p v-if="paymentError" class="sb-prepayment-error">{{ paymentError }}</p>
      <SbButton block :loading="paymentLoading" @click="payNow">
        {{ paymentLoading ? 'Переход к оплате...' : 'Оплатить онлайн' }}
      </SbButton>
    </div>

    <div v-if="booking?.telegram_link || booking?.max_link" class="sb-notify-section">
      <p class="sb-notify-label">Получать уведомления о записи</p>
      <div class="sb-notify-row">
        <a
          v-if="booking?.telegram_link"
          :href="booking.telegram_link"
          target="_blank"
          rel="noopener noreferrer"
          class="sb-btn-notify"
          style="border-color:#2AABEE;color:#2AABEE;"
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L8.32 14.617l-2.96-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.828.942z"/>
          </svg>
          Telegram
        </a>
        <a
          v-if="booking?.max_link"
          :href="booking.max_link"
          target="_blank"
          rel="noopener noreferrer"
          class="sb-btn-notify"
          style="border-color:#0077FF;color:#0077FF;"
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          MAX
        </a>
      </div>
    </div>

    <SbButton block class="sb-mt-4" @click="emit('back')">
      Вернуться в каталог
    </SbButton>
  </div>
</template>
