<script setup lang="ts">
import { useCartStore } from '@/stores/cart'
import { formatPrice, plural } from '@/lib/utils'

const emit = defineEmits<{
  back: []
  checkout: []
}>()

const cartStore = useCartStore()

function handleQuantityChange(itemId: string, delta: number) {
  const current = cartStore.getItemQuantity(itemId)
  cartStore.updateQuantity(itemId, current + delta)
}
</script>

<template>
  <div class="sb-cart-panel">
    <!-- Panel header -->
    <div class="sb-cart-panel-header">
      <h2 class="sb-cart-panel-title">Корзина</h2>
      <span v-if="cartStore.count > 0" class="sb-cart-panel-count">{{ cartStore.count }}</span>
      <div class="sb-header-spacer"></div>
      <button class="sb-close-btn" @click="emit('back')" aria-label="Закрыть корзину">
        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Scrollable items area -->
    <div class="sb-cart-panel-body">
      <!-- Empty -->
      <div v-if="cartStore.isEmpty" class="sb-empty">
        <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
        </svg>
        <p class="sb-empty-title">Корзина пуста</p>
        <p class="sb-empty-text">Добавьте товары из каталога</p>
        <button class="sb-btn sb-btn-primary sb-mt-4" @click="emit('back')">
          Перейти в каталог
        </button>
      </div>

      <!-- Items -->
      <div v-else class="sb-cart-items">
        <div v-for="item in cartStore.items" :key="item.id" class="sb-cart-item">
          <!-- Image -->
          <div class="sb-cart-item-img-wrap">
            <img v-if="item.image_url" :src="item.image_url" :alt="item.name" class="sb-cart-item-img" />
            <div v-else class="sb-cart-item-img-placeholder">
              <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </div>
          </div>

          <!-- Info -->
          <div class="sb-cart-item-info">
            <h4 class="sb-cart-item-name">{{ item.name }}</h4>
            <p class="sb-cart-item-price">
              {{ formatPrice(item.price) }}
              <span v-if="item.quantity > 1" class="sb-cart-item-subtotal">× {{ item.quantity }} = {{ formatPrice(item.price * item.quantity) }}</span>
            </p>
          </div>

          <!-- Qty + Remove -->
          <div class="sb-cart-item-actions">
            <div class="sb-quantity sb-quantity-sm">
              <button class="sb-quantity-btn" @click="handleQuantityChange(item.id, -1)">−</button>
              <span class="sb-quantity-value">{{ item.quantity }}</span>
              <button
                class="sb-quantity-btn"
                :disabled="item.maxStock != null && item.quantity >= item.maxStock"
                @click="handleQuantityChange(item.id, 1)"
              >+</button>
            </div>
            <button class="sb-cart-remove" @click="cartStore.removeItem(item.id)" aria-label="Удалить">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Sticky footer with total + checkout -->
    <div v-if="!cartStore.isEmpty" class="sb-cart-panel-footer">
      <div class="sb-cart-total">
        <span class="sb-cart-total-label">
          Итого
          <span class="sb-cart-total-items">({{ cartStore.count }} {{ plural(cartStore.count, 'товар', 'товара', 'товаров') }})</span>
        </span>
        <span class="sb-cart-total-price">{{ formatPrice(cartStore.total) }}</span>
      </div>
      <button class="sb-btn sb-btn-primary sb-btn-block" @click="emit('checkout')">
        Оформить заказ
      </button>
      <button class="sb-btn sb-btn-ghost sb-btn-block" style="margin-top: 6px;" @click="cartStore.clear()">
        Очистить корзину
      </button>
    </div>
  </div>
</template>
