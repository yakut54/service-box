<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { formatPrice, plural } from '@/lib/utils'
import SbButton from './SbButton.vue'

const emit = defineEmits<{
  back: []
  checkout: []
}>()

const cartStore = useCartStore()
const shopStore = useShopStore()

// Promo code state
const promoExpanded = ref(false)
const promoInput = ref('')
const promoLoading = ref(false)
const promoError = ref('')

function handleQuantityChange(itemId: string, delta: number) {
  const current = cartStore.getItemQuantity(itemId)
  cartStore.updateQuantity(itemId, current + delta)
}

async function applyPromo() {
  const code = promoInput.value.trim().toUpperCase()
  if (!code) return
  promoLoading.value = true
  promoError.value = ''
  try {
    const res = await shopStore.getApi().validateDiscount(code, cartStore.total)
    cartStore.setDiscount({ code, name: res.name, amount: res.discount_amount })
    promoExpanded.value = false
    promoInput.value = ''
  } catch (e: unknown) {
    promoError.value = e instanceof Error ? e.message : 'Промокод недействителен'
  } finally {
    promoLoading.value = false
  }
}

function removeDiscount() {
  cartStore.setDiscount(null)
}

// ── Авто-скидка (без промокода) ──────────────────────────────
async function checkAutoDiscount() {
  // Не перетираем вручную введённый промокод
  if (cartStore.discount?.code) return
  if (cartStore.isEmpty) {
    cartStore.setDiscount(null)
    return
  }
  try {
    const res = await shopStore.getApi().autoApplyDiscount(cartStore.total)
    if (res.found && res.name && res.discount_amount) {
      cartStore.setDiscount({ code: '', name: res.name, amount: res.discount_amount })
    } else if (!cartStore.discount?.code) {
      cartStore.setDiscount(null)
    }
  } catch {
    // тихо — авто-скидка не критична
  }
}

onMounted(checkAutoDiscount)

// Пересчитываем при изменении суммы (добавили/убрали товар)
watch(() => cartStore.total, (newVal, oldVal) => {
  if (newVal !== oldVal && !cartStore.discount?.code) {
    checkAutoDiscount()
  }
})
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
        <SbButton class="sb-mt-4" @click="emit('back')">
          Перейти в каталог
        </SbButton>
      </div>

      <!-- Items -->
      <div v-else class="sb-cart-items">
        <div v-for="item in cartStore.items" :key="item.id" class="sb-cart-item">

          <!-- ── Физический товар: маркетплейс-стиль ── -->
          <template v-if="item.type === 'physical'">
            <div class="sb-cart-item-main">
              <!-- Картинка -->
              <div class="sb-cart-item-img-wrap">
                <div v-if="item.image_url" class="sb-cart-item-img" :style="{ backgroundImage: `url(${item.image_url})` }"></div>
                <div v-else class="sb-cart-item-img-placeholder">
                  <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                </div>
              </div>
              <!-- Контент -->
              <div class="sb-cart-item-body">
                <!-- Верхняя строка: название + крестик -->
                <div class="sb-cart-item-top">
                  <h4 class="sb-cart-item-name">{{ item.name }}</h4>
                  <button class="sb-cart-remove" @click="cartStore.removeItem(item.id)" aria-label="Удалить">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
                <!-- Нижняя строка: цена слева, счётчик справа -->
                <div class="sb-cart-item-bottom">
                  <div class="sb-cart-item-price-block">
                    <span v-if="item.compare_price && item.compare_price > item.price" class="sb-cart-item-price-unit">{{ formatPrice(item.compare_price) }}</span>
                    <span class="sb-cart-item-price-total">{{ formatPrice(item.price * item.quantity) }}</span>
                  </div>
                  <div class="sb-quantity sb-quantity-sm">
                    <button class="sb-quantity-btn" @click="handleQuantityChange(item.id, -1)">−</button>
                    <span class="sb-quantity-value">{{ item.quantity }}</span>
                    <button
                      class="sb-quantity-btn"
                      :disabled="item.maxStock != null && item.quantity >= item.maxStock"
                      @click="handleQuantityChange(item.id, 1)"
                    >+</button>
                  </div>
                </div>
              </div>
            </div>
          </template>

          <!-- ── Цифровой товар: 1 строка, удалить справа ── -->
          <template v-else>
            <div class="sb-cart-item-img-wrap sb-cart-item-img-wrap--digital">
              <div v-if="item.image_url" class="sb-cart-item-img sb-cart-item-img--contain" :style="{ backgroundImage: `url(${item.image_url})` }"></div>
              <div v-else class="sb-cart-item-img-placeholder">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.723v6.554a1 1 0 01-1.447.894L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" />
                </svg>
              </div>
            </div>
            <div class="sb-cart-item-info">
              <h4 class="sb-cart-item-name">{{ item.name }}</h4>
              <p class="sb-cart-item-price">{{ formatPrice(item.price) }}</p>
            </div>
            <button class="sb-cart-remove sb-cart-remove--inline" @click="cartStore.removeItem(item.id)" aria-label="Удалить">
              <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </template>

        </div>

        <!-- Promo code section -->
        <div class="sb-promo">
          <!-- Applied discount badge -->
          <div v-if="cartStore.discount" class="sb-promo-applied">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg>
            <span>{{ cartStore.discount.name }}: −{{ formatPrice(cartStore.discount.amount) }}</span>
            <button class="sb-promo-remove" @click="removeDiscount" aria-label="Убрать скидку">✕</button>
          </div>

          <!-- Collapsed trigger -->
          <button
            v-else-if="!promoExpanded"
            class="sb-promo-toggle"
            @click="promoExpanded = true"
          >
            У меня есть промокод
          </button>

          <!-- Expanded input -->
          <div v-else class="sb-promo-form">
            <div class="sb-promo-input-row">
              <input
                v-model="promoInput"
                type="text"
                class="sb-promo-input"
                placeholder="ПРОМОКОД"
                maxlength="50"
                autofocus
                @keyup.enter="applyPromo"
              />
              <SbButton
                :disabled="promoLoading || !promoInput.trim()"
                @click="applyPromo"
              >
                {{ promoLoading ? '...' : 'Применить' }}
              </SbButton>
            </div>
            <p v-if="promoError" class="sb-promo-error">{{ promoError }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Sticky footer with total + checkout -->
    <div v-if="!cartStore.isEmpty" class="sb-cart-panel-footer">
      <!-- Subtotal line (shown only when discount applied) -->
      <div v-if="cartStore.discount" class="sb-cart-subtotal">
        <span>Товары</span>
        <span>{{ formatPrice(cartStore.total) }}</span>
      </div>
      <!-- Discount line -->
      <div v-if="cartStore.discount" class="sb-cart-discount-row">
        <span>Скидка</span>
        <span class="sb-cart-discount-amount">−{{ formatPrice(cartStore.discount.amount) }}</span>
      </div>
      <!-- Total -->
      <div class="sb-cart-total">
        <span class="sb-cart-total-label">
          {{ cartStore.discount ? 'К оплате' : 'Итого' }}
          <span class="sb-cart-total-items">({{ cartStore.count }} {{ plural(cartStore.count, 'товар', 'товара', 'товаров') }})</span>
        </span>
        <span class="sb-cart-total-price">{{ formatPrice(cartStore.discount ? cartStore.totalAfterDiscount : cartStore.total) }}</span>
      </div>
      <SbButton block @click="emit('checkout')">
        Оформить заказ
      </SbButton>
      <SbButton variant="ghost" block style="margin-top: 6px;" @click="cartStore.clear()">
        Очистить корзину
      </SbButton>
    </div>
  </div>
</template>
