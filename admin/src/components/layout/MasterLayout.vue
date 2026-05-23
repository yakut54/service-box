<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { api } from '@/lib/api'
import ToastContainer from '@/components/ToastContainer.vue'
import UiTooltip from '@/shared/ui/UiTooltip.vue'

const authStore = useAuthStore()
const { isDark, toggle } = useTheme()

const showMessengers = ref(false)
const tgConnected = ref(false)
const maxConnected = ref(false)
const tgLink = ref<string | null>(null)
const maxCode = ref<string | null>(null)
const maxUsername = ref<string | null>(null)
const loadingTg = ref(false)
const loadingMax = ref(false)

async function logout() {
  await authStore.logout()
}

async function loadMessengerStatus() {
  try {
    const data = await api.request<{ telegram_connected: boolean; max_connected: boolean }>('/master/messenger-status')
    tgConnected.value = data.telegram_connected
    maxConnected.value = data.max_connected
  } catch {}
}

async function connectTelegram() {
  loadingTg.value = true
  tgLink.value = null
  try {
    const data = await api.request<{ url: string }>('/master/telegram-link')
    tgLink.value = data.url
  } finally {
    loadingTg.value = false
  }
}

async function connectMax() {
  loadingMax.value = true
  maxCode.value = null
  try {
    const data = await api.request<{ code: string; bot_username: string }>('/master/max-code', { method: 'POST' })
    maxCode.value = data.code
    maxUsername.value = data.bot_username
  } finally {
    loadingMax.value = false
  }
}

async function disconnectTelegram() {
  await api.request('/master/telegram', { method: 'DELETE' })
  tgConnected.value = false
  tgLink.value = null
}

async function disconnectMax() {
  await api.request('/master/max', { method: 'DELETE' })
  maxConnected.value = false
  maxCode.value = null
}

onMounted(loadMessengerStatus)
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex flex-col">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 shadow-sm">
      <div class="flex items-center justify-between px-4 h-14">

        <!-- Shop name -->
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
          <!-- Messenger connect toggle -->
          <UiTooltip align="center">
            <button
              @click="showMessengers = !showMessengers"
              class="p-2 rounded-lg transition-colors"
              :class="(tgConnected || maxConnected)
                ? 'text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20'
                : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
              </svg>
            </button>
            <template #content>Уведомления</template>
          </UiTooltip>

          <!-- Dark mode toggle -->
          <UiTooltip align="center">
            <button
              @click="toggle"
              class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            >
              <svg v-if="isDark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
              </svg>
            </button>
            <template #content>{{ isDark ? 'Светлая тема' : 'Тёмная тема' }}</template>
          </UiTooltip>

          <!-- Logout -->
          <UiTooltip align="end">
            <button
              @click="logout"
              class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-600 dark:hover:text-red-400 transition-colors"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
            </button>
            <template #content>Выйти</template>
          </UiTooltip>
        </div>

      </div>

      <!-- Messenger panel (dropdown under header) -->
      <div v-if="showMessengers" class="border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 px-4 py-3 space-y-3">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Уведомления в мессенджеры</p>

        <!-- Telegram -->
        <div class="flex items-center justify-between gap-3">
          <div class="flex items-center gap-2 min-w-0">
            <span class="text-base">✈️</span>
            <div class="min-w-0">
              <p class="text-sm font-medium text-gray-900 dark:text-white">Telegram</p>
              <p class="text-xs" :class="tgConnected ? 'text-green-600 dark:text-green-400' : 'text-gray-400'">
                {{ tgConnected ? '✓ Подключён' : 'Не подключён' }}
              </p>
            </div>
          </div>
          <div class="flex-shrink-0">
            <button v-if="!tgConnected && !tgLink"
              @click="connectTelegram"
              :disabled="loadingTg"
              class="text-xs px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
            >{{ loadingTg ? '...' : 'Подключить' }}</button>
            <button v-if="tgConnected"
              @click="disconnectTelegram"
              class="text-xs px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
            >Отключить</button>
          </div>
        </div>
        <!-- Telegram deep-link -->
        <div v-if="tgLink" class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 text-sm">
          <p class="text-blue-700 dark:text-blue-300 mb-2">Откройте ссылку в Telegram:</p>
          <a :href="tgLink" target="_blank"
            class="block break-all text-blue-600 dark:text-blue-400 underline font-mono text-xs"
          >{{ tgLink }}</a>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Действительна 24 часа</p>
        </div>

        <!-- MAX -->
        <div class="flex items-center justify-between gap-3">
          <div class="flex items-center gap-2 min-w-0">
            <span class="text-base">💬</span>
            <div class="min-w-0">
              <p class="text-sm font-medium text-gray-900 dark:text-white">MAX</p>
              <p class="text-xs" :class="maxConnected ? 'text-green-600 dark:text-green-400' : 'text-gray-400'">
                {{ maxConnected ? '✓ Подключён' : 'Не подключён' }}
              </p>
            </div>
          </div>
          <div class="flex-shrink-0">
            <button v-if="!maxConnected && !maxCode"
              @click="connectMax"
              :disabled="loadingMax"
              class="text-xs px-3 py-1.5 rounded-lg bg-purple-600 text-white hover:bg-purple-700 disabled:opacity-50 transition-colors"
            >{{ loadingMax ? '...' : 'Подключить' }}</button>
            <button v-if="maxConnected"
              @click="disconnectMax"
              class="text-xs px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
            >Отключить</button>
          </div>
        </div>
        <!-- MAX code -->
        <div v-if="maxCode" class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-3 text-sm">
          <p class="text-purple-700 dark:text-purple-300 mb-1">Отправьте код боту <b>@{{ maxUsername }}</b> в MAX:</p>
          <p class="text-2xl font-mono font-bold tracking-widest text-center text-purple-800 dark:text-purple-200 py-2">{{ maxCode }}</p>
          <p class="text-xs text-gray-500 dark:text-gray-400 text-center">Действителен 10 минут</p>
        </div>
      </div>
    </header>

    <!-- Content -->
    <main class="flex-1 overflow-auto">
      <RouterView />
    </main>

    <ToastContainer />
  </div>
</template>
