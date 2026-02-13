<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useShopStore } from '@/stores/shop'
import Catalog from '@/components/Catalog.vue'

type WidgetView = 'loading' | 'error' | 'catalog' | 'product' | 'booking' | 'cart' | 'checkout' | 'success'

const shopStore = useShopStore()
const currentView = ref<WidgetView>('loading')
const widgetEl = ref<HTMLElement | null>(null)
const selectedProduct = ref<any>(null)

onMounted(async () => {
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

function handleProductSelect(product: any) {
  selectedProduct.value = product
  // W3: add to cart for physical/digital
  // W4: navigate to booking for services
}

function navigate(view: WidgetView) {
  currentView.value = view
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
    </header>

    <!-- Loading state (shop config loading) -->
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

    <!-- Error state (shop not found) -->
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
  </div>
</template>
