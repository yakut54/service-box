<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useChatStore } from '@/stores/chat'
import { useReviewsStore } from '@/stores/reviews'
import { useTheme } from '@/composables/useTheme'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
import AppBreadcrumb from '@/components/AppBreadcrumb.vue'
import ToastContainer from '@/components/ToastContainer.vue'
import SessionSupersededModal from '@/components/SessionSupersededModal.vue'
import UiTooltip from '@/shared/ui/UiTooltip.vue'

const authStore = useAuthStore()
const chatStore = useChatStore()
const reviewsStore = useReviewsStore()
const route = useRoute()
const router = useRouter()
const { isDark, toggle } = useTheme()

chatStore.poll()
useAutoRefresh(() => chatStore.poll(), 15_000)

reviewsStore.fetchPendingCount()
useAutoRefresh(() => reviewsStore.fetchPendingCount(), 60_000)

const sidebarOpen = ref(false)
const menuOpen    = ref(false)
const menuRef     = ref<HTMLElement | null>(null)

// Clock
const now = ref(new Date())
let clockTimer: ReturnType<typeof setInterval> | null = null

function onClickOutside(e: MouseEvent) {
  if (menuRef.value && !menuRef.value.contains(e.target as Node)) {
    menuOpen.value = false
  }
}

onMounted(() => {
  clockTimer = setInterval(() => { now.value = new Date() }, 1000)
  document.addEventListener('click', onClickOutside)
})
onUnmounted(() => {
  if (clockTimer) clearInterval(clockTimer)
  document.removeEventListener('click', onClickOutside)
})

const shopTimezone = computed(() => authStore.shop?.timezone || 'Europe/Moscow')

const currentTime = computed(() =>
  now.value.toLocaleTimeString('ru-RU', {
    hour: '2-digit', minute: '2-digit', second: '2-digit',
    timeZone: shopTimezone.value,
  })
)

const currentDate = computed(() =>
  now.value.toLocaleDateString('ru-RU', {
    weekday: 'short', day: 'numeric', month: 'short',
    timeZone: shopTimezone.value,
  })
)

const navigation = [
  // Обзор
  { name: 'Главная',    href: '/',            icon: 'home' },
  { name: 'Аналитика', href: '/analytics',    icon: 'analytics' },
  // Работа
  { name: 'Заказы',    href: '/orders',       icon: 'shopping-cart' },
  { name: 'Клиенты',   href: '/customers',    icon: 'users' },
  { name: 'Чат',       href: '/chat',         icon: 'chat' },
  // Каталог
  { name: 'Товары',    href: '/products',     icon: 'package' },
  { name: 'Категории', href: '/categories',   icon: 'categories' },
  // Маркетинг
  { name: 'Скидки',    href: '/discounts',    icon: 'discount' },
  { name: 'Отзывы',    href: '/reviews',      icon: 'reviews' },
  // Магазин
  { name: 'Команда',   href: '/staff',        icon: 'staff',        ownerOnly: true },
  { name: 'Комиссия',  href: '/commission',   icon: 'commission',   ownerOnly: true },
  { name: 'Документы', href: '/legal',        icon: 'legal',        ownerOnly: true },
  { name: 'Настройки', href: '/settings',     icon: 'settings',     ownerOnly: true },
]

const visibleNavigation = computed(() =>
  navigation.filter(item => !item.ownerOnly || authStore.isOwner)
)

const superadminNavigation = [
  { name: 'Магазины', href: '/superadmin/shops', icon: 'sa-shops' },
  { name: 'Выручка', href: '/superadmin/revenue', icon: 'sa-revenue' },
]

function isActive(href: string) {
  if (href === '/') return route.path === '/'
  return route.path.startsWith(href)
}

async function handleLogout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<template>
  <div class="app-bg bg-gray-50 transition-colors duration-200 lg:h-screen lg:flex lg:flex-col">
    <!-- Mobile sidebar backdrop -->
    <div
        v-if="sidebarOpen"
        class="fixed inset-0 bg-gray-900/50 z-40 lg:hidden"
        @click="sidebarOpen = false"
    />

    <!-- Sidebar -->
    <aside
        :class="[
        'app-sidebar fixed top-0 left-0 z-50 h-full w-72 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 transform transition-all duration-200 lg:translate-x-0 flex flex-col',
        sidebarOpen ? 'translate-x-0' : '-translate-x-full'
      ]"
    >
      <!-- Logo -->
      <div class="h-16 flex items-center px-6 border-b border-gray-200 dark:border-gray-800 flex-shrink-0">
        <RouterLink to="/" class="flex items-center gap-2">
          <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center">
            <span class="text-white font-bold text-lg">SB</span>
          </div>
          <span class="font-semibold text-gray-900 dark:text-white">ServiceBox</span>
        </RouterLink>
      </div>

      <!-- Shop name -->
      <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
        <p class="text-xs text-gray-500 dark:text-gray-500 uppercase tracking-wider">Интернет-Магазин</p>
        <p class="font-medium text-gray-900 dark:text-white truncate">
          {{ authStore.shop?.name || 'Загрузка...' }}
        </p>
      </div>

      <!-- Navigation -->
      <nav class="p-4 space-y-1 flex-1 overflow-y-auto">
        <RouterLink
            v-for="item in visibleNavigation"
            :key="item.href"
            :to="item.href"
            :class="[
            'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
            isActive(item.href)
              ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400'
              : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'
          ]"
            @click="sidebarOpen = false"
        >
          <svg v-if="item.icon === 'home'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
          </svg>
          <svg v-else-if="item.icon === 'analytics'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
          <svg v-else-if="item.icon === 'package'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          <svg v-else-if="item.icon === 'shopping-cart'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
          <svg v-else-if="item.icon === 'calendar'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
          <svg v-else-if="item.icon === 'master'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <svg v-else-if="item.icon === 'users'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
          </svg>
          <svg v-else-if="item.icon === 'categories'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
          </svg>
          <svg v-else-if="item.icon === 'discount'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
          </svg>
          <svg v-else-if="item.icon === 'reviews'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
          </svg>
          <svg v-else-if="item.icon === 'staff'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
          <svg v-else-if="item.icon === 'chat'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
          <svg v-else-if="item.icon === 'commission'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <svg v-else-if="item.icon === 'legal'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <svg v-else-if="item.icon === 'settings'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <span class="flex-1">{{ item.name }}</span>
          <span
            v-if="item.href === '/chat' && chatStore.totalUnread > 0"
            class="min-w-[1.25rem] h-5 px-1 flex items-center justify-center rounded-full bg-red-500 text-white text-[11px] font-semibold leading-none"
          >
            {{ chatStore.totalUnread > 99 ? '99+' : chatStore.totalUnread }}
          </span>
          <span
            v-if="item.href === '/reviews' && reviewsStore.pendingCount > 0"
            class="min-w-[1.25rem] h-5 px-1 flex items-center justify-center rounded-full bg-red-500 text-white text-[11px] font-semibold leading-none"
          >
            {{ reviewsStore.pendingCount > 99 ? '99+' : reviewsStore.pendingCount }}
          </span>
        </RouterLink>
      </nav>

      <!-- Superadmin section -->
      <div v-if="authStore.user?.is_superadmin" class="px-4 pb-2">
        <div class="border-t border-gray-200 dark:border-gray-800 pt-3 mb-2">
          <p class="px-3 text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider mb-1">
            Платформа
          </p>
        </div>
        <RouterLink
          v-for="item in superadminNavigation"
          :key="item.href"
          :to="item.href"
          :class="[
            'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
            isActive(item.href)
              ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400'
              : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white'
          ]"
          @click="sidebarOpen = false"
        >
          <!-- Shops icon -->
          <svg v-if="item.icon === 'sa-shops'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
          </svg>
          <!-- Revenue icon -->
          <svg v-else-if="item.icon === 'sa-revenue'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
          {{ item.name }}
        </RouterLink>
      </div>

      <!-- Bottom: user menu -->
      <div class="p-4 border-t border-gray-200 dark:border-gray-800 flex-shrink-0">

        <!-- Subtle clock line -->
        <p class="text-xs text-gray-400 dark:text-gray-600 tabular-nums select-none text-center mb-2">
          {{ currentTime }} · <span class="capitalize">{{ currentDate }}</span>
        </p>

        <!-- User row → dropdown trigger -->
        <div ref="menuRef" class="relative">
          <button
            @click.stop="menuOpen = !menuOpen"
            class="w-full flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-left"
          >
            <div class="w-8 h-8 bg-gray-200 dark:bg-gray-700 rounded-full flex items-center justify-center flex-shrink-0">
              <span class="text-gray-600 dark:text-gray-300 font-medium text-sm">
                {{ authStore.user?.name?.charAt(0).toUpperCase() || authStore.user?.email?.charAt(0).toUpperCase() }}
              </span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-900 dark:text-white truncate leading-tight">{{ authStore.user?.name || authStore.user?.email }}</p>
              <p class="text-xs text-gray-400 dark:text-gray-500 leading-tight">{{ authStore.isOwner ? 'Владелец' : 'Администратор' }}</p>
            </div>
            <svg
              class="w-4 h-4 text-gray-400 flex-shrink-0 transition-transform duration-150"
              :class="menuOpen ? 'rotate-180' : ''"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
            </svg>
          </button>

          <!-- Dropdown (opens upward) -->
          <Transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
          >
            <div
              v-if="menuOpen"
              class="absolute bottom-full left-0 right-0 mb-1 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg py-1 z-10"
            >
              <!-- Theme toggle -->
              <button
                @click="toggle(); menuOpen = false"
                class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
              >
                <svg v-if="isDark" class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                </svg>
                {{ isDark ? 'Светлая тема' : 'Тёмная тема' }}
              </button>

              <div class="my-1 border-t border-gray-100 dark:border-gray-800" />

              <!-- Legal links -->
              <a
                href="/offer" target="_blank" rel="noopener"
                class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                @click="menuOpen = false"
              >
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Оферта
              </a>
              <a
                href="/privacy" target="_blank" rel="noopener"
                class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                @click="menuOpen = false"
              >
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 1v3m0 16v3M4.22 4.22l2.12 2.12m11.32 11.32l2.12 2.12M1 12h3m16 0h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12" />
                </svg>
                Конфиденциальность
              </a>

              <div class="my-1 border-t border-gray-100 dark:border-gray-800" />

              <!-- Logout -->
              <button
                @click="handleLogout"
                class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/10 transition-colors"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                Выйти
              </button>
            </div>
          </Transition>
        </div>

      </div>
    </aside>

    <!-- Main content -->
    <div class="lg:pl-72 lg:flex lg:flex-col lg:flex-1 lg:min-h-0">
      <!-- Mobile header -->
      <header class="app-topbar lg:hidden sticky top-0 z-40 h-16 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between px-4 transition-colors duration-200">
        <div class="flex items-center gap-2">
          <button @click="sidebarOpen = true" class="p-2 -ml-2 text-gray-600 dark:text-gray-400">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
          <span class="font-semibold text-gray-900 dark:text-white">ServiceBox</span>
        </div>
        <UiTooltip align="end" side="bottom">
          <button
              @click="toggle"
              class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
          >
            <svg v-if="isDark" class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
            </svg>
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
          </button>
          <template #content>{{ isDark ? 'Светлая тема' : 'Тёмная тема' }}</template>
        </UiTooltip>
      </header>

      <main class="p-3 sm:p-4 lg:p-6 lg:flex-1 lg:overflow-auto lg:flex lg:flex-col lg:min-h-0">
        <AppBreadcrumb />
        <RouterView />
        <ToastContainer />
      </main>
    </div>
  </div>

  <SessionSupersededModal />
</template>
