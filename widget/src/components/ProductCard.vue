<script setup lang="ts">
import { computed } from 'vue'
import { formatPrice, plural } from '@/lib/utils'

const props = defineProps<{ product: any }>()
const emit = defineEmits<{ select: [product: any] }>()

const badge = computed(() => {
  const p = props.product
  if (p.type === 'service' && p.service) {
    return { cls: 'sb-pc-badge sb-pc-badge-info', text: `${p.service.duration_minutes} мин` }
  }
  if (p.type === 'physical' && p.physical) {
    const stock = p.physical.stock_quantity ?? 0
    if (stock === 0) return { cls: 'sb-pc-badge sb-pc-badge-danger', text: 'Нет в наличии' }
    if (stock <= 5) return { cls: 'sb-pc-badge sb-pc-badge-warning', text: `Остал${stock === 1 ? 'ся' : 'ось'} ${stock} ${plural(stock, 'товар', 'товара', 'товаров')}` }
  }
  if (p.type === 'digital') {
    return { cls: 'sb-pc-badge sb-pc-badge-info', text: 'Цифровой' }
  }
  return null
})

const isOutOfStock = computed(() => {
  const p = props.product
  return p.type === 'physical' && p.physical && (p.physical.stock_quantity ?? 0) === 0
})

const isService = computed(() => props.product.type === 'service')

const hasDiscount = computed(() =>
  props.product.compare_price && props.product.compare_price > props.product.price
)

const discountPercent = computed(() => {
  if (!hasDiscount.value) return 0
  return Math.round((1 - props.product.price / props.product.compare_price) * 100)
})

const rating = computed(() => props.product.rating ?? null)
const reviewCount = computed(() => props.product.review_count ?? 0)

function fullStars(r: number) {
  return Math.floor(r)
}
function hasHalf(r: number) {
  return r % 1 >= 0.5
}
</script>

<template>
  <div
    class="sb-pc"
    :class="{ 'sb-pc--oos': isOutOfStock }"
    @click="emit('select', product)"
  >
    <!-- Image area -->
    <div class="sb-pc-image">
      <img
        v-if="product.image_url"
        :src="product.image_url"
        :alt="product.name"
        loading="lazy"
        class="sb-pc-img"
      />
      <div v-else class="sb-pc-img-placeholder">
        <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      </div>

      <!-- Badge overlay top-left -->
      <span v-if="badge" :class="badge.cls">{{ badge.text }}</span>

      <!-- Discount % badge top-right -->
      <span v-if="hasDiscount && !isOutOfStock" class="sb-pc-discount">−{{ discountPercent }}%</span>

      <!-- Mini-FAB bottom-right -->
      <button
        v-if="!isOutOfStock"
        class="sb-pc-fab"
        :aria-label="isService ? 'Записаться' : 'В корзину'"
        @click.stop="emit('select', product)"
      >
        <svg v-if="isService" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <svg v-else width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
      </button>
    </div>

    <!-- Body -->
    <div class="sb-pc-body">
      <p v-if="product.category" class="sb-pc-category">{{ product.category }}</p>
      <h3 class="sb-pc-name">{{ product.name }}</h3>

      <!-- Rating -->
      <div v-if="rating" class="sb-pc-rating">
        <span class="sb-pc-stars" aria-hidden="true">
          <svg
            v-for="i in fullStars(rating)"
            :key="'f' + i"
            class="sb-pc-star sb-pc-star-full"
            viewBox="0 0 20 20"
          >
            <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" />
          </svg>
          <svg v-if="hasHalf(rating)" key="h" class="sb-pc-star sb-pc-star-half" viewBox="0 0 20 20">
            <defs>
              <linearGradient id="sb-half">
                <stop offset="50%" stop-color="currentColor" />
                <stop offset="50%" stop-color="transparent" />
              </linearGradient>
            </defs>
            <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" fill="url(#sb-half)" stroke="currentColor" stroke-width="0.5" />
          </svg>
          <svg
            v-for="i in (5 - fullStars(rating) - (hasHalf(rating) ? 1 : 0))"
            :key="'e' + i"
            class="sb-pc-star sb-pc-star-empty"
            viewBox="0 0 20 20"
          >
            <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" />
          </svg>
        </span>
        <span class="sb-pc-rating-val">{{ rating.toFixed(1) }}</span>
        <span v-if="reviewCount" class="sb-pc-rating-count">({{ reviewCount }})</span>
      </div>

      <!-- Price row -->
      <div class="sb-pc-price-row">
        <span class="sb-pc-price">{{ formatPrice(product.price) }}</span>
        <span v-if="hasDiscount" class="sb-pc-old-price">{{ formatPrice(product.compare_price) }}</span>
      </div>
    </div>
  </div>
</template>
