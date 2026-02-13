<script setup lang="ts">
import { formatPrice } from '@/lib/utils'

const props = defineProps<{ order: any }>()
const emit = defineEmits<{ back: [] }>()
</script>

<template>
  <div class="sb-success">
    <div class="sb-success-icon">
      <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </div>

    <h2 class="sb-success-title">Заказ оформлен!</h2>
    <p class="sb-success-text">Спасибо за покупку. Детали заказа отправлены на ваш email.</p>

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
        <span class="sb-badge sb-badge-warning">Ожидает оплаты</span>
      </div>
    </div>

    <button class="sb-btn sb-btn-primary sb-btn-block sb-mt-4" @click="emit('back')">
      Вернуться в каталог
    </button>
  </div>
</template>
