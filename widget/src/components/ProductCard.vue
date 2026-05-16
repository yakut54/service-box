<script setup lang="ts">
import { computed } from 'vue'
import { formatPrice, plural } from '@/lib/utils'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import type { WidgetProduct } from '@/types'

const props = defineProps<{ product: WidgetProduct }>()
const emit = defineEmits<{
  select: [product: WidgetProduct]
  'open-cart': []
  book: [product: WidgetProduct]
}>()

const cartStore  = useCartStore()
const shopStore  = useShopStore()
const cfg        = computed(() => shopStore.config)

const inCartItem = computed(() => cartStore.items.find(i => i.id === props.product.id))
const inCartQty  = computed(() => inCartItem.value?.quantity ?? 0)
const inCart     = computed(() => inCartQty.value > 0)
const maxStock   = computed(() => props.product.physical?.stock_quantity ?? Infinity)

const badge = computed(() => {
  const p = props.product
  if (p.type === 'service' && p.service && cfg.value.show_duration !== false) {
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

const isService  = computed(() => props.product.type === 'service')
const isDigital  = computed(() => props.product.type === 'digital')

const hasDiscount = computed(() =>
  props.product.compare_price != null && props.product.compare_price > props.product.price
)

const discountPercent = computed(() => {
  if (!hasDiscount.value || props.product.compare_price == null) return 0
  return Math.round((1 - props.product.price / props.product.compare_price) * 100)
})

const rating      = computed(() => props.product.rating != null ? parseFloat(String(props.product.rating)) : null)
const reviewCount = computed(() => props.product.review_count ?? 0)

function addToCart() {
  cartStore.addItem(props.product)
  emit('open-cart')
}

function increment() {
  cartStore.updateQuantity(props.product.id, inCartQty.value + 1)
}

function decrement() {
  cartStore.updateQuantity(props.product.id, inCartQty.value - 1)
}
</script>

<template>
  <div
    class="sb-pc"
    :class="{ 'sb-pc--oos': isOutOfStock }"
    @click="emit('select', product)"
  >
    <!-- ══ IMAGE ══ -->
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
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
      </div>

      <!-- Top-left: type/stock badge -->
      <span v-if="badge" :class="badge.cls">{{ badge.text }}</span>

      <!-- Discount — top-right, ALWAYS visible (outside img-footer) -->
      <span v-if="hasDiscount && !isOutOfStock" class="sb-pc-discount">−{{ discountPercent }}%</span>

      <!-- Mobile-only FAB overlay (hidden on desktop via CSS) -->
      <div class="sb-pc-img-footer">
        <!-- SERVICE -->
        <button
          v-if="isService && !isOutOfStock"
          class="sb-pc-fab" aria-label="Записаться"
          @click.stop="emit('book', product)">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
        </button>

        <!-- DIGITAL in cart -->
        <div v-else-if="isDigital && inCart" class="sb-pc-fab sb-pc-fab--done" @click.stop>
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
          </svg>
        </div>

        <!-- PHYSICAL qty on image -->
        <div v-else-if="!isService && !isDigital && inCart" class="sb-pc-fab-qty" @click.stop>
          <button class="sb-pc-fab-qty-btn" @click="decrement">−</button>
          <span class="sb-pc-fab-qty-val">{{ inCartQty }}</span>
          <button class="sb-pc-fab-qty-btn" @click="increment" :disabled="inCartQty >= maxStock">+</button>
        </div>

        <!-- Add to cart -->
        <button
          v-else-if="!isOutOfStock"
          class="sb-pc-fab" :aria-label="isDigital ? 'Получить' : 'В корзину'"
          @click.stop="addToCart">
          <svg v-if="isDigital" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
          </svg>
          <svg v-else width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
              d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- ══ BODY ══ -->
    <div class="sb-pc-body">
      <div v-if="cfg.show_price !== false" class="sb-pc-prices">
        <span class="sb-pc-price">{{ formatPrice(product.price) }}</span>
        <span v-if="hasDiscount" class="sb-pc-old-price">{{ formatPrice(product.compare_price!) }}</span>
      </div>
      <h3 class="sb-pc-name">{{ product.name }}</h3>
      <div v-if="rating" class="sb-pc-rating">
        <svg class="sb-pc-star-ico" viewBox="0 0 20 20" aria-hidden="true">
          <polygon fill="currentColor" points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7"/>
        </svg>
        <span class="sb-pc-rating-val">{{ rating.toFixed(1) }}</span>
        <span v-if="reviewCount" class="sb-pc-rating-count">· {{ reviewCount }}</span>
      </div>

      <!-- Desktop action button (hidden on mobile via CSS) -->
      <div class="sb-pc-action">
        <!-- SERVICE -->
        <button v-if="isService && !isOutOfStock"
          class="sb-pc-action-btn sb-pc-action-btn--primary"
          @click.stop="emit('book', product)">
          <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
          Записаться
        </button>

        <!-- DIGITAL in cart -->
        <div v-else-if="isDigital && inCart" class="sb-pc-action-btn sb-pc-action-btn--done">
          <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
          </svg>
          В корзине
        </div>

        <!-- PHYSICAL qty -->
        <div v-else-if="!isService && !isDigital && inCart"
          class="sb-pc-action-qty" @click.stop>
          <button class="sb-pc-action-qty-btn" @click="decrement">−</button>
          <span class="sb-pc-action-qty-val">{{ inCartQty }}</span>
          <button class="sb-pc-action-qty-btn" @click="increment" :disabled="inCartQty >= maxStock">+</button>
        </div>

        <!-- Add to cart / get digital -->
        <button v-else-if="!isOutOfStock"
          class="sb-pc-action-btn sb-pc-action-btn--primary"
          @click.stop="addToCart">
          <svg v-if="isDigital" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
          </svg>
          <svg v-else width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
              d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
          </svg>
          {{ isDigital ? 'Получить' : 'В корзину' }}
        </button>
      </div>
    </div>
  </div>
</template>
