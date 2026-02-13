<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useShopStore } from '@/stores/shop'

type WidgetView = 'loading' | 'error' | 'catalog' | 'product' | 'booking' | 'cart' | 'checkout' | 'success'

const shopStore = useShopStore()
const currentView = ref<WidgetView>('loading')
const widgetEl = ref<HTMLElement | null>(null)

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

function formatPrice(kopecks: number): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(kopecks / 100)
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
            <div class="sb-skeleton sb-skeleton-btn" style="margin-top: 12px;"></div>
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

    <!-- Catalog (placeholder — full implementation in W2) -->
    <div v-else-if="currentView === 'catalog'" class="sb-content">
      <div class="sb-empty">
        <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
        </svg>
        <p class="sb-empty-title">Каталог</p>
        <p class="sb-empty-text">Виджет подключён к магазину «{{ shopStore.shop?.name }}». Каталог товаров появится в следующем обновлении.</p>
      </div>
    </div>
  </div>
</template>
