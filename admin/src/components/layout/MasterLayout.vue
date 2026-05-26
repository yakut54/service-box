<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterView, RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { api } from '@/lib/api'
import ToastContainer from '@/components/ToastContainer.vue'

const authStore = useAuthStore()
const { isDark, toggle } = useTheme()
const route = useRoute()

const anyConnected = ref(false)

async function logout() {
  await authStore.logout()
}

onMounted(async () => {
  try {
    const data = await api.request<{ telegram_connected: boolean; max_connected: boolean }>('/master/messenger-status')
    anyConnected.value = data.telegram_connected || data.max_connected
  } catch {}
})
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex flex-col">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 shadow-sm">
      <div class="max-w-2xl mx-auto w-full px-4 h-14 flex items-center justify-between">

        <!-- Shop + user name -->
        <div class="flex flex-col min-w-0">
          <span class="text-sm font-semibold text-gray-900 dark:text-white truncate leading-tight">
            {{ authStore.shop?.name }}
          </span>
          <span class="text-xs text-gray-500 dark:text-gray-400 truncate leading-tight">
            {{ authStore.user?.name }}
          </span>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-2 flex-shrink-0">

          <!-- Dark mode toggle -->
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

          <!-- Logout -->
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
    </header>

    <!-- Content -->
    <main class="flex-1 overflow-auto pb-16">
      <div class="max-w-2xl mx-auto w-full">
        <RouterView />
      </div>
    </main>

    <!-- Bottom Navigation -->
    <nav class="fixed bottom-0 left-0 right-0 z-40 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
      <div class="max-w-2xl mx-auto flex">

        <!-- Записи -->
        <RouterLink
          to="/master"
          active-class=""
          exact-active-class=""
          class="flex-1 flex flex-col items-center justify-center gap-1 py-2 transition-colors"
          :class="route.path === '/master'
            ? 'text-primary-600 dark:text-primary-400'
            : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
          <span class="text-xs font-medium">Записи</span>
        </RouterLink>

        <!-- Уведомления -->
        <RouterLink
          to="/master/notifications"
          active-class=""
          exact-active-class=""
          class="flex-1 flex flex-col items-center justify-center gap-1 py-2 transition-colors relative"
          :class="route.path === '/master/notifications'
            ? 'text-primary-600 dark:text-primary-400'
            : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
        >
          <div class="relative">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <!-- Green dot if connected -->
            <span
              v-if="anyConnected"
              class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-green-500 rounded-full border-2 border-white dark:border-gray-900"
            />
          </div>
          <span class="text-xs font-medium">Уведомления</span>
        </RouterLink>

      </div>
    </nav>

    <ToastContainer />
  </div>
</template>
