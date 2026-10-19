<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { RouterView, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { usePresenceStore } from '@/stores/presence'
import { useTheme } from '@/composables/useTheme'
import ToastContainer from '@/components/ToastContainer.vue'
import OrderAssignmentRequestModal from '@/components/OrderAssignmentRequestModal.vue'
import OrderAssignmentDeclinedModal from '@/components/OrderAssignmentDeclinedModal.vue'
import SettingsPassword from '@/components/settings/SettingsPassword.vue'
import UiAvatar from '@/shared/ui/UiAvatar.vue'
import UiModal from '@/shared/ui/UiModal.vue'

const authStore = useAuthStore()
const presenceStore = usePresenceStore()
const { isDark, toggle } = useTheme()

// Сборщик не долетает до /settings вообще (роутер уводит его на свой
// кабинет на любом маршруте без requiresCollector) — "Сменить пароль"
// там ему просто негде взять. Тот же SettingsPassword.vue, что и у
// владельца/админа в Настройках → Профиль, без копий — только новая
// точка входа (живой вопрос 2026-10-19).
const showPasswordModal = ref(false)

async function logout() {
  await authStore.logout()
}

onMounted(() => {
  const shopId = authStore.shop?.id
  if (shopId) presenceStore.join(shopId)
})
onUnmounted(() => presenceStore.leave())
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex flex-col">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 shadow-sm">
      <div class="max-w-2xl mx-auto w-full px-4 h-14 flex items-center justify-between">

        <div class="flex items-center gap-2 min-w-0">
          <!-- Аватарка залогиненного сотрудника, не магазина — тот же приём,
               что в AppLayout для обычной админки. -->
          <UiAvatar :src="authStore.user?.avatar_url" :name="authStore.user?.name || authStore.user?.email" size="sm" />
          <div class="flex flex-col min-w-0">
            <span class="text-sm font-semibold text-gray-900 dark:text-white truncate leading-tight">
              {{ authStore.shop?.name }}
            </span>
            <span class="text-xs text-gray-500 dark:text-gray-400 truncate leading-tight">
              {{ authStore.isCollector ? 'Сборщик' : 'Сборка' }} · {{ authStore.user?.name }}
            </span>
          </div>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
          <RouterLink
            v-if="!authStore.isCollector"
            to="/orders"
            class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            title="Вернуться в админку"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </RouterLink>

          <button
            @click="showPasswordModal = true"
            class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            title="Сменить пароль"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
            </svg>
          </button>

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
    </header>

    <!-- Content -->
    <main class="flex-1 overflow-auto">
      <div class="max-w-2xl mx-auto w-full">
        <RouterView />
      </div>
    </main>

    <ToastContainer />
    <OrderAssignmentRequestModal />
    <OrderAssignmentDeclinedModal />

    <UiModal v-model="showPasswordModal" max-width="max-w-md">
      <div class="p-4">
        <SettingsPassword />
      </div>
    </UiModal>
  </div>
</template>
