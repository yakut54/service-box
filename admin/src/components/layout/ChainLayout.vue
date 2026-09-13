<script setup lang="ts">
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import AppBreadcrumb from '@/components/AppBreadcrumb.vue'
import ToastContainer from '@/components/ToastContainer.vue'

const authStore = useAuthStore()
const route = useRoute()
const { isDark, toggle } = useTheme()

function isActive(name: string) {
  return route.name === name
}

async function logout() {
  await authStore.logout()
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex flex-col">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 shadow-sm">
      <div class="max-w-5xl mx-auto w-full px-4 h-14 flex items-center justify-between gap-3">

        <div class="flex items-center gap-4 min-w-0">
          <span class="text-sm font-semibold text-gray-900 dark:text-white truncate leading-tight shrink-0">
            {{ authStore.chain?.name || authStore.user?.name }}
          </span>
          <nav class="hidden sm:flex items-center gap-1">
            <RouterLink
              :to="{ name: 'chain-shops' }"
              :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
                isActive('chain-shops')
                  ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400'
                  : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800']"
            >Мои точки</RouterLink>
            <RouterLink
              :to="{ name: 'chain-revenue' }"
              :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
                isActive('chain-revenue')
                  ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400'
                  : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800']"
            >Выручка</RouterLink>
            <RouterLink
              :to="{ name: 'chain-settings' }"
              :class="['px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
                isActive('chain-settings')
                  ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400'
                  : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800']"
            >Настройки</RouterLink>
          </nav>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
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

          <button
            @click="logout"
            class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-600 dark:hover:text-red-400 transition-colors"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Мобильная навигация -->
      <nav class="sm:hidden flex items-center gap-1 px-4 pb-2 max-w-5xl mx-auto w-full">
        <RouterLink
          :to="{ name: 'chain-shops' }"
          :class="['flex-1 text-center px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
            isActive('chain-shops')
              ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400'
              : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800']"
        >Мои точки</RouterLink>
        <RouterLink
          :to="{ name: 'chain-revenue' }"
          :class="['flex-1 text-center px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
            isActive('chain-revenue')
              ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400'
              : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800']"
        >Выручка</RouterLink>
        <RouterLink
          :to="{ name: 'chain-settings' }"
          :class="['flex-1 text-center px-3 py-1.5 rounded-lg text-sm font-medium transition-colors',
            isActive('chain-settings')
              ? 'bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-400'
              : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800']"
        >Настройки</RouterLink>
      </nav>
    </header>

    <!-- Content -->
    <main class="flex-1 overflow-auto p-3 sm:p-4 lg:p-6">
      <div class="max-w-5xl mx-auto w-full">
        <AppBreadcrumb />
        <RouterView />
        <ToastContainer />
      </div>
    </main>
  </div>
</template>
