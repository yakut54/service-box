<script setup lang="ts">
import { formatPrice } from '@/lib/utils'

const props = defineProps<{ booking: any; product: any }>()
const emit = defineEmits<{ back: [] }>()

function formatDateTime(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
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
        <span class="sb-success-value">{{ formatDateTime(booking.start_time) }}</span>
      </div>
      <div v-if="booking.master" class="sb-success-row">
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

    <button class="sb-btn sb-btn-primary sb-btn-block sb-mt-4" @click="emit('back')">
      Вернуться в каталог
    </button>
  </div>
</template>
