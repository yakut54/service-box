<script setup lang="ts">
import { useCartStore } from '@/stores/cart'
import { formatPrice } from '@/lib/utils'

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
  <div class="sb-cart">
    <!-- Header -->
    <div class="sb-flex sb-items-center sb-gap-3 sb-mb-4">
      <button class="sb-btn sb-btn-ghost" @click="emit('back')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Назад
      </button>
      <h2 class="sb-title" style="margin-bottom: 0;">Корзина</h2>
      <span class="sb-badge sb-badge-info">{{ cartStore.count }}</span>
    </div>

    <!-- Empty cart -->
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

    <!-- Cart items -->
    <div v-else>
      <div class="sb-cart-items">
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
            <p class="sb-cart-item-price">{{ formatPrice(item.price) }}</p>
          </div>

          <!-- Quantity -->
          <div class="sb-cart-item-actions">
            <div class="sb-quantity sb-quantity-sm">
              <button class="sb-quantity-btn" @click="handleQuantityChange(item.id, -1)">-</button>
              <span class="sb-quantity-value">{{ item.quantity }}</span>
              <button
                class="sb-quantity-btn"
                :disabled="item.maxStock != null && item.quantity >= item.maxStock"
                @click="handleQuantityChange(item.id, 1)"
              >+</button>
            </div>

            <!-- Remove -->
            <button class="sb-cart-remove" @click="cartStore.removeItem(item.id)" title="Удалить">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="sb-cart-footer">
        <div class="sb-cart-total">
          <span>Итого:</span>
          <span class="sb-price-lg">{{ formatPrice(cartStore.total) }}</span>
        </div>

        <button class="sb-btn sb-btn-primary sb-btn-block" @click="emit('checkout')">
          Оформить заказ
        </button>

        <button class="sb-btn sb-btn-ghost sb-btn-block sb-mt-2" @click="cartStore.clear()">
          Очистить корзину
        </button>
      </div>
    </div>
  </div>
</template>
