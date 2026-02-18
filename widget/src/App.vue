<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useShopStore } from '@/stores/shop'
import { useCartStore } from '@/stores/cart'
import Catalog from '@/components/Catalog.vue'
import ProductDetail from '@/components/ProductDetail.vue'
import Cart from '@/components/Cart.vue'
import Checkout from '@/components/Checkout.vue'
import OrderSuccess from '@/components/OrderSuccess.vue'
import BookingCalendar from '@/components/BookingCalendar.vue'
import BookingSuccess from '@/components/BookingSuccess.vue'
import MyOrders from '@/components/MyOrders.vue'
import MyBookings from '@/components/MyBookings.vue'

type WidgetView = 'loading' | 'error' | 'catalog' | 'product' | 'booking' | 'booking-success' | 'cart' | 'checkout' | 'success' | 'orders' | 'bookings-list'

const shopStore = useShopStore()
const cartStore = useCartStore()
const currentView = ref<WidgetView>('loading')
const widgetEl = ref<HTMLElement | null>(null)
const mainEl = ref<HTMLElement | null>(null)
const selectedProduct = ref<any>(null)
const completedOrder = ref<any>(null)
const completedBooking = ref<any>(null)
const sidebarOpen = ref(false)
const isClosing = ref(false)
const sidebarCategories = ref<string[]>([])
const activeSidebarCategory = ref('')
const categoriesOpen = ref(true)
const previousView = ref<WidgetView>('catalog')
let initialized = false

// ── Init on first open ──────────────────────────────────────
watch(() => shopStore.isOpen, async (open) => {
  if (open && !initialized) {
    initialized = true
    cartStore.init(shopStore.shopId)

    // If config already loaded (e.g. restored from wasOpen), go straight to catalog
    if (!shopStore.loading && shopStore.shop) {
      currentView.value = 'catalog'
      return
    }
    if (!shopStore.loading && shopStore.error) {
      currentView.value = 'error'
      return
    }

    // Wait for config to load (triggered by main.ts openWidget)
    await nextTick()

    // Config might already be loading from main.ts deferred load
    // Watch for it to finish
    const unwatch = watch(() => shopStore.loading, (loading) => {
      if (!loading) {
        if (shopStore.error) {
          currentView.value = 'error'
        } else {
          currentView.value = 'catalog'
        }
        unwatch()
      }
    }, { immediate: true })
  }
}, { immediate: true })

// Apply theme when shop loads and widget element is ready
watch(() => shopStore.shop, () => {
  if (widgetEl.value && shopStore.shop) {
    shopStore.applyTheme(widgetEl.value)
  }
})

// Also apply theme attribute reactively
watch(() => shopStore.theme, (t) => {
  if (widgetEl.value) {
    widgetEl.value.setAttribute('data-theme', t)
  }
})

// ── Escape key to close ────────────────────────────────────
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && shopStore.isOpen) {
    handleClose()
  }
}

onMounted(() => {
  document.addEventListener('keydown', onKeydown)
  // Set initial theme attribute
  if (widgetEl.value) {
    widgetEl.value.setAttribute('data-theme', shopStore.theme)
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
})

// ── Navigation ──────────────────────────────────────────────
function scrollToTop() {
  mainEl.value?.scrollTo({ top: 0, behavior: 'smooth' })
}

function handleClose() {
  isClosing.value = true
  setTimeout(() => {
    isClosing.value = false
    shopStore.close()
  }, 200)
}

function handleThemeToggle() {
  shopStore.toggleTheme(widgetEl.value || undefined)
}

function handleProductSelect(product: any) {
  selectedProduct.value = product
  currentView.value = 'product'
  sidebarOpen.value = false
  scrollToTop()
}

function handleBack() {
  currentView.value = 'catalog'
  selectedProduct.value = null
  scrollToTop()
}

function handleCartBack() {
  const safeViews: WidgetView[] = ['catalog', 'product']
  if (!cartStore.isEmpty && safeViews.includes(previousView.value)) {
    currentView.value = previousView.value
  } else {
    currentView.value = 'catalog'
  }
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
  if (view === 'cart') {
    previousView.value = currentView.value
  }
  currentView.value = view
  activeSidebarCategory.value = ''
  sidebarOpen.value = false
  scrollToTop()
}

function handleCategoriesLoaded(cats: string[]) {
  sidebarCategories.value = cats
}

function selectSidebarCategory(cat: string) {
  activeSidebarCategory.value = cat === activeSidebarCategory.value ? '' : cat
  currentView.value = 'catalog'
  sidebarOpen.value = false
  scrollToTop()
}
</script>

<template>
  <div class="sb-widget" ref="widgetEl" :data-theme="shopStore.theme">
    <div
        class="sb-overlay"
        :class="{
        'sb-overlay--open': shopStore.isOpen && !isClosing,
      }"
    >
      <!-- Top Header -->
      <header class="sb-top-header" v-if="currentView !== 'loading' || shopStore.isOpen">
        <!-- Hamburger (mobile) -->
        <button class="sb-hamburger" @click="sidebarOpen = !sidebarOpen" aria-label="Меню">
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>

        <!-- Logo -->
        <img
            v-if="shopStore.config.logo_url"
            :src="shopStore.config.logo_url"
            :alt="shopStore.shop?.name"
            class="sb-logo"
        />

        <!-- Shop name -->
        <span class="sb-shop-name">{{ shopStore.shop?.name }}</span>

        <div class="sb-header-spacer"></div>

        <!-- Theme toggle -->
        <button class="sb-theme-toggle" @click="handleThemeToggle" aria-label="Переключить тему">
          <!-- Sun (shown in dark mode) -->
          <svg v-if="shopStore.theme === 'dark'" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
          </svg>
          <!-- Moon (shown in light mode) -->
          <svg v-else width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
          </svg>
        </button>

        <!-- Close -->
        <button class="sb-close-btn" @click="handleClose" aria-label="Закрыть">
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </header>

      <!-- Body: Sidebar + Main -->
      <div class="sb-body">
        <!-- Sidebar scrim (mobile) -->
        <div
            class="sb-sidebar-scrim"
            :class="{ 'sb-sidebar-scrim--visible': sidebarOpen }"
            @click="sidebarOpen = false"
        ></div>

        <!-- Sidebar -->
        <aside class="sb-sidebar" :class="{ 'sb-sidebar--open': sidebarOpen }">
          <nav class="sb-sidebar-nav">
            <!-- Catalog -->
            <button
                :class="['sb-nav-item', currentView === 'catalog' || currentView === 'product' ? 'sb-nav-item--active' : '']"
                @click="navigate('catalog')"
            >
              <svg class="sb-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
              </svg>
              Каталог
            </button>

            <!-- Cart -->
            <button
                :class="['sb-nav-item', currentView === 'cart' || currentView === 'checkout' ? 'sb-nav-item--active' : '']"
                @click="navigate('cart')"
            >
              <svg class="sb-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
              </svg>
              Корзина
              <span v-if="cartStore.count > 0" class="sb-nav-badge">{{ cartStore.count }}</span>
            </button>

            <!-- Orders -->
            <button
                :class="['sb-nav-item', currentView === 'orders' ? 'sb-nav-item--active' : '']"
                @click="navigate('orders')"
            >
              <svg class="sb-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
              </svg>
              Мои заказы
            </button>

            <!-- Bookings -->
            <button
                :class="['sb-nav-item', currentView === 'bookings-list' ? 'sb-nav-item--active' : '']"
                @click="navigate('bookings-list')"
            >
              <svg class="sb-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              Мои записи
            </button>
          </nav>

          <!-- Categories accordion -->
          <template v-if="sidebarCategories.length > 0">
            <hr class="sb-sidebar-divider" />
            <button class="sb-sidebar-accordion-toggle" @click="categoriesOpen = !categoriesOpen">
              <span>Категории</span>
              <svg
                class="sb-sidebar-accordion-chevron"
                :class="{ 'sb-sidebar-accordion-chevron--open': categoriesOpen }"
                fill="none" stroke="currentColor" viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <div class="sb-sidebar-categories" :class="{ 'sb-sidebar-categories--collapsed': !categoriesOpen }">
              <button
                  :class="['sb-sidebar-cat', activeSidebarCategory === '' ? 'sb-sidebar-cat--active' : '']"
                  @click="activeSidebarCategory = ''"
              >
                Все
              </button>
              <button
                  v-for="cat in sidebarCategories"
                  :key="cat"
                  :class="['sb-sidebar-cat', activeSidebarCategory === cat ? 'sb-sidebar-cat--active' : '']"
                  @click="selectSidebarCategory(cat)"
              >
                {{ cat }}
              </button>
            </div>
          </template>
        </aside>

        <!-- Main content -->
        <main class="sb-main" ref="mainEl">
          <div class="sb-main-inner sb-grid-container">

            <!-- Loading -->
            <div v-if="currentView === 'loading'" class="sb-grid sb-grid-2 sb-grid-3" style="margin-top: 16px;">
              <div v-for="i in 6" :key="i" class="sb-card">
                <div class="sb-skeleton sb-skeleton-image"></div>
                <div class="sb-skeleton sb-skeleton-title"></div>
                <div class="sb-skeleton sb-skeleton-text" style="width: 40%;"></div>
                <div class="sb-skeleton sb-skeleton-btn" style="margin-top: 12px; width: 100%;"></div>
              </div>
            </div>

            <!-- Error -->
            <div v-else-if="currentView === 'error'" class="sb-empty">
              <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
              </svg>
              <p class="sb-empty-title">Не удалось загрузить магазин</p>
              <p class="sb-empty-text">{{ shopStore.error }}</p>
              <button class="sb-btn sb-btn-primary sb-mt-4" @click="shopStore.loadConfig().then(() => { if (!shopStore.error) currentView = 'catalog' })">
                Попробовать снова
              </button>
            </div>

            <!-- Catalog -->
            <Catalog
              v-else-if="currentView === 'catalog'"
              :active-category="activeSidebarCategory"
              @select="handleProductSelect"
              @categories-loaded="handleCategoriesLoaded"
            />

            <!-- Product Detail -->
            <ProductDetail
              v-else-if="currentView === 'product' && selectedProduct"
              :product="selectedProduct"
              @back="handleBack"
              @booking="handleBooking"
              @go-to-cart="navigate('cart')"
            />

            <!-- Booking Calendar -->
            <BookingCalendar
              v-else-if="currentView === 'booking' && selectedProduct"
              :product="selectedProduct"
              @back="handleBack"
              @success="handleBookingSuccess"
            />

            <!-- Booking Success -->
            <BookingSuccess
              v-else-if="currentView === 'booking-success'"
              :booking="completedBooking"
              :product="selectedProduct"
              @back="navigate('catalog')"
            />

            <!-- Cart -->
            <Cart
              v-else-if="currentView === 'cart'"
              @back="handleCartBack"
              @checkout="navigate('checkout')"
            />

            <!-- Checkout -->
            <Checkout
              v-else-if="currentView === 'checkout'"
              @back="navigate('cart')"
              @success="handleOrderSuccess"
            />

            <!-- Order Success -->
            <OrderSuccess
              v-else-if="currentView === 'success'"
              :order="completedOrder"
              @back="navigate('catalog')"
            />

            <!-- My Orders -->
            <MyOrders v-else-if="currentView === 'orders'" @back="navigate('catalog')" />

            <!-- My Bookings -->
            <MyBookings v-else-if="currentView === 'bookings-list'" @back="navigate('catalog')" />

            <!-- Fallback -->
            <div v-else class="sb-empty">
              <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
              </svg>
              <p class="sb-empty-title">Скоро будет доступно</p>
              <p class="sb-empty-text">Этот раздел в разработке</p>
              <button class="sb-btn sb-btn-primary sb-mt-4" @click="navigate('catalog')">Вернуться в каталог</button>
            </div>

          </div>
        </main>

      </div>

      <!-- Floating Cart Button (inside overlay, fixed position) -->
      <transition name="sb-fab">
        <button
            v-if="cartStore.count > 0 && currentView !== 'cart' && currentView !== 'checkout' && currentView !== 'success'"
            class="sb-fab-cart"
            @click="navigate('cart')"
            aria-label="Перейти в корзину"
        >
          <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
          </svg>
          <span class="sb-fab-cart-label">Корзина</span>
          <span class="sb-fab-cart-badge">{{ cartStore.count }}</span>
        </button>
      </transition>
    </div>
  </div>
</template>