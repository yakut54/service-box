<script setup lang="ts">
import { ref } from 'vue'
import { useCartStore } from '@/stores/cart'
import type { WidgetView } from '@/types'

defineProps<{
  currentView: WidgetView
  sidebarCategories: { id: string; name: string }[]
  activeSidebarCategory: string
  open: boolean
  cartOpen: boolean
}>()

const emit = defineEmits<{
  navigate: [view: WidgetView]
  'cart-back': []
  'select-category': [catId: string]
  close: []
}>()

const cartStore = useCartStore()
const categoriesOpen = ref(true)
</script>

<template>
  <!-- Sidebar scrim (mobile) -->
  <div
    class="sb-sidebar-scrim"
    :class="{ 'sb-sidebar-scrim--visible': open }"
    @click="emit('close')"
  />

  <!-- Sidebar -->
  <aside class="sb-sidebar" :class="{ 'sb-sidebar--open': open }">
    <nav class="sb-sidebar-nav">
      <!-- Catalog -->
      <button
        :class="['sb-nav-item', currentView === 'catalog' || currentView === 'product' ? 'sb-nav-item--active' : '']"
        @click="emit('navigate', 'catalog')"
      >
        <svg class="sb-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
        </svg>
        Каталог
      </button>

      <!-- Cart -->
      <button
        :class="['sb-nav-item', currentView === 'cart' || currentView === 'checkout' ? 'sb-nav-item--active' : '']"
        @click="cartOpen ? emit('cart-back') : emit('navigate', 'cart')"
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
        @click="emit('navigate', 'orders')"
      >
        <svg class="sb-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
        </svg>
        Мои заказы
      </button>

      <!-- Bookings -->
      <button
        :class="['sb-nav-item', currentView === 'bookings-list' ? 'sb-nav-item--active' : '']"
        @click="emit('navigate', 'bookings-list')"
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
          @click="emit('select-category', '')"
        >
          Все
        </button>
        <button
          v-for="cat in sidebarCategories"
          :key="cat.id"
          :class="['sb-sidebar-cat', activeSidebarCategory === cat.id ? 'sb-sidebar-cat--active' : '']"
          @click="emit('select-category', cat.id)"
        >
          {{ cat.name }}
        </button>
      </div>
    </template>
  </aside>
</template>
