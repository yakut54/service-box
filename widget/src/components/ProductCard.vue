<script setup lang="ts">
import { computed } from 'vue'
import { formatPrice } from '@/lib/utils'

const props = defineProps<{ product: any }>()
const emit = defineEmits<{ select: [product: any] }>()

const badge = computed(() => {
  const p = props.product
  if (p.type === 'service' && p.service) {
    return { cls: 'sb-badge sb-badge-info', text: `${p.service.duration_minutes} мин` }
  }
  if (p.type === 'physical' && p.physical) {
    const stock = p.physical.stock_quantity ?? 0
    if (stock === 0) return { cls: 'sb-badge sb-badge-danger', text: 'Нет в наличии' }
    if (stock <= 5) return { cls: 'sb-badge sb-badge-warning', text: `Осталось ${stock}` }
  }
  if (p.type === 'digital') {
    return { cls: 'sb-badge sb-badge-info', text: 'Цифровой' }
  }
  return null
})

const isOutOfStock = computed(() => {
  const p = props.product
  return p.type === 'physical' && p.physical && (p.physical.stock_quantity ?? 0) === 0
})

const buttonLabel = computed(() => {
  if (props.product.type === 'service') return 'Записаться'
  return 'В корзину'
})
</script>

<template>
  <div class="sb-card sb-card-interactive sb-product-card">
    <div class="sb-product-image" @click="emit('select', product)">
      <img
        v-if="product.image_url"
        :src="product.image_url"
        :alt="product.name"
        loading="lazy"
        class="sb-product-img"
      />
      <div v-else class="sb-product-img-placeholder">
        <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      </div>
    </div>

    <div class="sb-product-body" @click="emit('select', product)">
      <h3 class="sb-product-name">{{ product.name }}</h3>
      <p v-if="product.category" class="sb-product-category">{{ product.category }}</p>
      <div class="sb-flex sb-items-center sb-justify-between sb-mt-2">
        <span class="sb-price">{{ formatPrice(product.price) }}</span>
        <span v-if="badge" :class="badge.cls">{{ badge.text }}</span>
      </div>
    </div>

    <button
      class="sb-btn sb-btn-primary sb-product-btn"
      :disabled="isOutOfStock"
      @click="emit('select', product)"
    >
      {{ isOutOfStock ? 'Нет в наличии' : buttonLabel }}
    </button>
  </div>
</template>
