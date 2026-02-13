<script setup lang="ts">
import { ref, computed } from 'vue'
import { formatPrice } from '@/lib/utils'
import { useCartStore } from '@/stores/cart'

const props = defineProps<{ product: any }>()
const emit = defineEmits<{
  back: []
  booking: [product: any]
}>()

const cartStore = useCartStore()
const quantity = ref(1)
const addedToCart = ref(false)

const isService = computed(() => props.product.type === 'service')
const isPhysical = computed(() => props.product.type === 'physical')

const maxStock = computed(() => {
  if (!isPhysical.value || !props.product.physical) return Infinity
  return props.product.physical.stock_quantity ?? 0
})

const isOutOfStock = computed(() => isPhysical.value && maxStock.value === 0)

const inCartQty = computed(() => cartStore.getItemQuantity(props.product.id))
const canAddMore = computed(() => {
  if (!isPhysical.value) return true
  return inCartQty.value + quantity.value <= maxStock.value
})

const badge = computed(() => {
  const p = props.product
  if (isService.value && p.service) return { cls: 'sb-badge sb-badge-info', text: `${p.service.duration_minutes} мин` }
  if (isPhysical.value && p.physical) {
    const stock = p.physical.stock_quantity ?? 0
    if (stock === 0) return { cls: 'sb-badge sb-badge-danger', text: 'Нет в наличии' }
    if (stock <= 5) return { cls: 'sb-badge sb-badge-warning', text: `Осталось ${stock}` }
  }
  if (p.type === 'digital') return { cls: 'sb-badge sb-badge-info', text: 'Цифровой' }
  return null
})

function decrementQty() {
  if (quantity.value > 1) quantity.value--
}

function incrementQty() {
  if (isPhysical.value && quantity.value >= maxStock.value) return
  quantity.value++
}

function handleAddToCart() {
  for (let i = 0; i < quantity.value; i++) {
    cartStore.addItem(props.product)
  }
  addedToCart.value = true
  setTimeout(() => { addedToCart.value = false }, 2000)
}

function handleBooking() {
  emit('booking', props.product)
}
</script>

<template>
  <div class="sb-product-detail">
    <!-- Back button -->
    <button class="sb-btn sb-btn-ghost sb-mb-4" @click="emit('back')">
      <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      Назад к каталогу
    </button>

    <div class="sb-detail-layout">
      <!-- Image -->
      <div class="sb-detail-image">
        <img
          v-if="product.image_url"
          :src="product.image_url"
          :alt="product.name"
          class="sb-detail-img"
        />
        <div v-else class="sb-detail-img-placeholder">
          <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
        </div>
      </div>

      <!-- Info -->
      <div class="sb-detail-info">
        <div class="sb-flex sb-items-center sb-gap-2 sb-mb-2">
          <span v-if="badge" :class="badge.cls">{{ badge.text }}</span>
          <span v-if="product.category" class="sb-badge" style="background: var(--sb-bg-muted); color: var(--sb-text-muted);">{{ product.category }}</span>
        </div>

        <h2 class="sb-detail-name">{{ product.name }}</h2>
        <p class="sb-price-lg sb-mb-4">{{ formatPrice(product.price) }}</p>

        <p v-if="product.description" class="sb-detail-description">{{ product.description }}</p>

        <!-- Service details -->
        <div v-if="isService && product.service" class="sb-detail-meta sb-mt-4">
          <div class="sb-detail-meta-row">
            <span class="sb-detail-meta-label">Длительность</span>
            <span>{{ product.service.duration_minutes }} мин</span>
          </div>
        </div>

        <!-- Physical details -->
        <div v-if="isPhysical && product.physical" class="sb-detail-meta sb-mt-4">
          <div v-if="product.physical.sku" class="sb-detail-meta-row">
            <span class="sb-detail-meta-label">Артикул</span>
            <span>{{ product.physical.sku }}</span>
          </div>
          <div v-if="product.physical.weight_grams" class="sb-detail-meta-row">
            <span class="sb-detail-meta-label">Вес</span>
            <span>{{ product.physical.weight_grams }} г</span>
          </div>
        </div>

        <!-- Actions -->
        <div class="sb-detail-actions sb-mt-4">
          <!-- Service: booking button -->
          <template v-if="isService">
            <button class="sb-btn sb-btn-primary sb-btn-block" @click="handleBooking">
              Записаться
            </button>
          </template>

          <!-- Physical/Digital: quantity + add to cart -->
          <template v-else>
            <div v-if="!isOutOfStock && !isService" class="sb-quantity-row sb-mb-2">
              <div class="sb-quantity">
                <button class="sb-quantity-btn" @click="decrementQty" :disabled="quantity <= 1">-</button>
                <span class="sb-quantity-value">{{ quantity }}</span>
                <button class="sb-quantity-btn" @click="incrementQty" :disabled="isPhysical && quantity >= maxStock">+</button>
              </div>
              <span class="sb-detail-total">{{ formatPrice(product.price * quantity) }}</span>
            </div>

            <button
              class="sb-btn sb-btn-primary sb-btn-block"
              :disabled="isOutOfStock || !canAddMore"
              @click="handleAddToCart"
            >
              <template v-if="addedToCart">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Добавлено!
              </template>
              <template v-else-if="isOutOfStock">
                Нет в наличии
              </template>
              <template v-else>
                В корзину
              </template>
            </button>

            <p v-if="inCartQty > 0 && !addedToCart" class="sb-detail-in-cart">
              Уже в корзине: {{ inCartQty }} шт.
            </p>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
