<script setup lang="ts">
import { computed } from 'vue'
import { formatPrice, plural } from '@/lib/utils'
import { useCartStore } from '@/stores/cart'

const props = defineProps<{ product: any }>()
const emit = defineEmits<{
  back: []
  booking: [product: any]
  goToCart: []
}>()

const cartStore = useCartStore()

const isService = computed(() => props.product.type === 'service')
const isPhysical = computed(() => props.product.type === 'physical')

const maxStock = computed(() => {
  if (!isPhysical.value || !props.product.physical) return Infinity
  return props.product.physical.stock_quantity ?? 0
})

const isOutOfStock = computed(() => isPhysical.value && maxStock.value === 0)
const inCart = computed(() => cartStore.hasItem(props.product.id))
const inCartQty = computed(() => cartStore.getItemQuantity(props.product.id))

const badge = computed(() => {
  const p = props.product
  if (isService.value && p.service) return { cls: 'sb-pd-badge sb-pd-badge-info', text: `${p.service.duration_minutes} мин` }
  if (isPhysical.value && p.physical) {
    const stock = p.physical.stock_quantity ?? 0
    if (stock === 0) return { cls: 'sb-pd-badge sb-pd-badge-danger', text: 'Нет в наличии' }
    if (stock <= 5) return { cls: 'sb-pd-badge sb-pd-badge-warning', text: `Остал${stock === 1 ? 'ся' : 'ось'} ${stock} ${plural(stock, 'товар', 'товара', 'товаров')}` }
  }
  if (p.type === 'digital') return { cls: 'sb-pd-badge sb-pd-badge-info', text: 'Цифровой' }
  return null
})

const hasDiscount = computed(() =>
  props.product.compare_price && props.product.compare_price > props.product.price
)

const discountPercent = computed(() => {
  if (!hasDiscount.value) return 0
  return Math.round((1 - props.product.price / props.product.compare_price) * 100)
})

const rating = computed(() => props.product.rating ?? null)
const reviewCount = computed(() => props.product.review_count ?? 0)

function handleAddToCart() {
  cartStore.addItem(props.product)
}

function handleIncrement() {
  cartStore.updateQuantity(props.product.id, inCartQty.value + 1)
}

function handleDecrement() {
  cartStore.updateQuantity(props.product.id, inCartQty.value - 1)
}
</script>

<template>
  <div class="sb-pd sb-grid-container">
    <!-- Back -->
    <button class="sb-pd-back sb-btn sb-btn-ghost" @click="emit('back')">
      <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      Назад
    </button>

    <!-- 2-col layout -->
    <div class="sb-pd-layout">

      <!-- Left: image -->
      <div class="sb-pd-col-img">
        <div class="sb-pd-img-wrap">
          <img
            v-if="product.image_url"
            :src="product.image_url"
            :alt="product.name"
            class="sb-pd-img"
          />
          <div v-else class="sb-pd-img-placeholder">
            <svg width="56" height="56" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          </div>
          <span v-if="badge" :class="badge.cls">{{ badge.text }}</span>
          <span v-if="hasDiscount && !isOutOfStock" class="sb-pd-discount">−{{ discountPercent }}%</span>
        </div>
      </div>

      <!-- Right: info + actions -->
      <div class="sb-pd-col-info">
        <p v-if="product.category" class="sb-pd-category">{{ product.category }}</p>
        <h2 class="sb-pd-name">{{ product.name }}</h2>

        <!-- Rating -->
        <div v-if="rating" class="sb-pd-rating">
          <span class="sb-pd-stars" aria-hidden="true">
            <svg v-for="i in Math.floor(rating)" :key="'f'+i" class="sb-pd-star sb-pd-star-full" viewBox="0 0 20 20">
              <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" />
            </svg>
            <svg v-for="i in (5 - Math.floor(rating))" :key="'e'+i" class="sb-pd-star sb-pd-star-empty" viewBox="0 0 20 20">
              <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" />
            </svg>
          </span>
          <span class="sb-pd-rating-val">{{ rating.toFixed(1) }}</span>
          <span v-if="reviewCount" class="sb-pd-rating-count">({{ reviewCount }} отзывов)</span>
        </div>

        <!-- Price -->
        <div class="sb-pd-price-row">
          <span class="sb-pd-price">{{ formatPrice(product.price) }}</span>
          <span v-if="hasDiscount" class="sb-pd-old-price">{{ formatPrice(product.compare_price) }}</span>
        </div>

        <!-- Description -->
        <p v-if="product.description" class="sb-pd-description">{{ product.description }}</p>

        <!-- Meta -->
        <div v-if="isService && product.service" class="sb-pd-meta">
          <div class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Длительность</span>
            <span>{{ product.service.duration_minutes }} мин</span>
          </div>
        </div>
        <div v-if="isPhysical && product.physical" class="sb-pd-meta">
          <div v-if="product.physical.sku" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Артикул</span>
            <span>{{ product.physical.sku }}</span>
          </div>
          <div v-if="product.physical.weight_grams" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Вес</span>
            <span>{{ product.physical.weight_grams }} г</span>
          </div>
        </div>

        <!-- Actions — inline (desktop) / above footer (mobile) -->
        <div class="sb-pd-actions">
          <template v-if="isService">
            <button class="sb-btn sb-btn-primary sb-btn-block" @click="emit('booking', product)">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              Записаться
            </button>
          </template>

          <template v-else-if="inCart">
            <div class="sb-pd-qty-row">
              <div class="sb-quantity">
                <button class="sb-quantity-btn" @click="handleDecrement">−</button>
                <span class="sb-quantity-value">{{ inCartQty }}</span>
                <button class="sb-quantity-btn" :disabled="isPhysical && inCartQty >= maxStock" @click="handleIncrement">+</button>
              </div>
              <span class="sb-pd-qty-total">{{ formatPrice(product.price * inCartQty) }}</span>
            </div>
            <button class="sb-btn sb-btn-primary sb-btn-block" @click="emit('goToCart')">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
              </svg>
              Перейти в корзину
            </button>
          </template>

          <template v-else>
            <button class="sb-btn sb-btn-primary sb-btn-block" :disabled="isOutOfStock" @click="handleAddToCart">
              {{ isOutOfStock ? 'Нет в наличии' : 'В корзину' }}
            </button>
          </template>
        </div>
      </div>
    </div>

    <!-- Mobile sticky footer (price + CTA) -->
    <div class="sb-pd-footer">
      <div class="sb-pd-footer-price">
        <span class="sb-pd-footer-amount">{{ formatPrice(product.price) }}</span>
        <span v-if="hasDiscount" class="sb-pd-footer-old">{{ formatPrice(product.compare_price) }}</span>
      </div>
      <template v-if="isService">
        <button class="sb-btn sb-btn-primary" @click="emit('booking', product)">Записаться</button>
      </template>
      <template v-else-if="inCart">
        <div class="sb-quantity sb-quantity-sm">
          <button class="sb-quantity-btn" @click="handleDecrement">−</button>
          <span class="sb-quantity-value">{{ inCartQty }}</span>
          <button class="sb-quantity-btn" :disabled="isPhysical && inCartQty >= maxStock" @click="handleIncrement">+</button>
        </div>
        <button class="sb-btn sb-btn-primary" @click="emit('goToCart')">В корзину</button>
      </template>
      <template v-else>
        <button class="sb-btn sb-btn-primary" :disabled="isOutOfStock" @click="handleAddToCart">
          {{ isOutOfStock ? 'Нет в наличии' : 'В корзину' }}
        </button>
      </template>
    </div>
  </div>
</template>
