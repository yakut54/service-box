<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useShopStore } from '@/stores/shop'
import { useCartStore } from '@/stores/cart'
import Catalog from '@/components/Catalog.vue'
import ProductDetail from '@/components/ProductDetail.vue'
import Cart from '@/components/Cart.vue'
import Checkout from '@/components/Checkout.vue'
import OrderSuccess from '@/components/OrderSuccess.vue'
import BookingCalendar from '@/components/BookingCalendar.vue'
import BookingSuccess from '@/components/BookingSuccess.vue'

type WidgetView = 'loading' | 'error' | 'catalog' | 'product' | 'booking' | 'booking-success' | 'cart' | 'checkout' | 'success'

const shopStore = useShopStore()
const cartStore = useCartStore()
const currentView = ref<WidgetView>('loading')
const widgetEl = ref<HTMLElement | null>(null)
const selectedProduct = ref<any>(null)
const completedOrder = ref<any>(null)
const completedBooking = ref<any>(null)

onMounted(async () => {
  cartStore.init(shopStore.shopId)
  await shopStore.loadConfig()

  if (shopStore.error) {
    currentView.value = 'error'
  } else {
    currentView.value = 'catalog'
  }
})

// Apply theme when shop loads and widget element is ready
watch(() => shopStore.shop, () => {
  if (widgetEl.value && shopStore.shop) {
    shopStore.applyTheme(widgetEl.value)
  }
})

function scrollToTop() {
  widgetEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function handleProductSelect(product: any) {
  selectedProduct.value = product
  currentView.value = 'product'
  scrollToTop()
}

const previousView = ref<WidgetView>('catalog')

function handleBack() {
  currentView.value = 'catalog'
  selectedProduct.value = null
  scrollToTop()
}

function handleCartBack() {
  currentView.value = previousView.value
  scrollToTop()
}

function handleBooking(product: any) {
  selectedProduct.value = product
  currentView.value = 'booking'
  scrollToTop()
}

function handleOrderSuccess(order: any) {
  completedOrder.value = order
  currentView.value = 'success'
  scrollToTop()
}

function handleBookingSuccess(booking: any) {
  completedBooking.value = booking
  currentView.value = 'booking-success'
  scrollToTop()
}

function navigate(view: WidgetView) {
  currentView.value = view
  scrollToTop()
}
</script>

<template>
  <div class="sb-widget" ref="widgetEl">
    <!-- Header -->
    <header class="sb-header" v-if="currentView !== 'loading'">
      <img
        v-if="shopStore.config.logo_url"
        :src="shopStore.config.logo_url"
        :alt="shopStore.shop?.name"
        class="sb-logo"
      />
      <span class="sb-shop-name">{{ shopStore.shop?.name }}</span>

      <!-- Cart button -->
      <button
        v-if="!cartStore.isEmpty"
        class="sb-cart-btn"
        @click="previousView = currentView; navigate('cart')"
      >
        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
        </svg>
        <span class="sb-cart-count">{{ cartStore.count }}</span>
      </button>
    </header>

    <!-- Loading state -->
    <div v-if="currentView === 'loading'" class="sb-content">
      <div class="sb-header">
        <div class="sb-skeleton" style="width: 36px; height: 36px; border-radius: 50%;"></div>
        <div class="sb-skeleton sb-skeleton-title" style="width: 160px;"></div>
      </div>
      <div class="sb-grid-container">
        <div class="sb-grid sb-grid-2 sb-grid-3" style="margin-top: 16px;">
          <div v-for="i in 6" :key="i" class="sb-card">
            <div class="sb-skeleton sb-skeleton-image"></div>
            <div class="sb-skeleton sb-skeleton-title"></div>
            <div class="sb-skeleton sb-skeleton-text" style="width: 40%;"></div>
            <div class="sb-skeleton sb-skeleton-btn" style="margin-top: 12px; width: 100%;"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Error state -->
    <div v-else-if="currentView === 'error'" class="sb-content">
      <div class="sb-empty">
        <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
        <p class="sb-empty-title">Не удалось загрузить магазин</p>
        <p class="sb-empty-text">{{ shopStore.error }}</p>
        <button class="sb-btn sb-btn-primary sb-mt-4" @click="shopStore.loadConfig().then(() => { if (!shopStore.error) currentView = 'catalog' })">
          Попробовать снова
        </button>
      </div>
    </div>

    <!-- Catalog -->
    <div v-else-if="currentView === 'catalog'" class="sb-content">
      <Catalog @select="handleProductSelect" />
    </div>

    <!-- Product Detail -->
    <div v-else-if="currentView === 'product' && selectedProduct" class="sb-content">
      <ProductDetail
        :product="selectedProduct"
        @back="handleBack"
        @booking="handleBooking"
      />
    </div>

    <!-- Booking Calendar -->
    <div v-else-if="currentView === 'booking' && selectedProduct" class="sb-content">
      <BookingCalendar
        :product="selectedProduct"
        @back="handleBack"
        @success="handleBookingSuccess"
      />
    </div>

    <!-- Booking Success -->
    <div v-else-if="currentView === 'booking-success'" class="sb-content">
      <BookingSuccess
        :booking="completedBooking"
        :product="selectedProduct"
        @back="navigate('catalog')"
      />
    </div>

    <!-- Cart -->
    <div v-else-if="currentView === 'cart'" class="sb-content">
      <Cart
        @back="handleCartBack"
        @checkout="navigate('checkout')"
      />
    </div>

    <!-- Checkout -->
    <div v-else-if="currentView === 'checkout'" class="sb-content sb-grid-container">
      <Checkout
        @back="navigate('cart')"
        @success="handleOrderSuccess"
      />
    </div>

    <!-- Order Success -->
    <div v-else-if="currentView === 'success'" class="sb-content">
      <OrderSuccess
        :order="completedOrder"
        @back="navigate('catalog')"
      />
    </div>

    <!-- Fallback for views not yet implemented -->
    <div v-else class="sb-content">
      <div class="sb-empty">
        <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
        </svg>
        <p class="sb-empty-title">Скоро будет доступно</p>
        <p class="sb-empty-text">Этот раздел в разработке</p>
        <button class="sb-btn sb-btn-primary sb-mt-4" @click="navigate('catalog')">
          Вернуться в каталог
        </button>
      </div>
    </div>
  </div>
</template>
