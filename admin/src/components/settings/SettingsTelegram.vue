<script setup lang="ts">
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'

const authStore = useAuthStore()

const telegramCode  = ref('')
const generating    = ref(false)
const error         = ref('')
const disconnecting = ref(false)
const showConfirm   = ref(false)

const hasFeature = computed(() =>
  ['start', 'business', 'pro'].includes(authStore.shop?.subscription_plan ?? '')
)

async function generateCode() {
  if (!authStore.shop) return
  generating.value = true
  error.value = ''
  try {
    const resp = await api.generateTelegramCode()
    telegramCode.value = resp.code
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка генерации кода'
  }
  generating.value = false
}

async function disconnect() {
  if (!authStore.shop) return
  disconnecting.value = true
  try {
    await api.disconnectTelegram()
    if (authStore.shop) {
      authStore.shop.telegram_bot_connected = false
      authStore.shop.telegram_chat_id = null
    }
    telegramCode.value = ''
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка отключения'
  }
  disconnecting.value = false
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Telegram уведомления</h2>

    <div v-if="!hasFeature" class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
      <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m2-6a4 4 0 100-8 4 4 0 000 8z" />
      </svg>
      <div>
        <div class="font-medium text-gray-700 dark:text-gray-300 text-sm">Недоступно на тарифе Micro</div>
        <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Telegram-уведомления о новых записях и заказах доступны на тарифах <span class="font-medium">Start и выше</span>.</div>
      </div>
    </div>

    <div v-else-if="authStore.shop?.telegram_bot_connected" class="space-y-3">
      <div class="flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
        <svg class="w-6 h-6 text-green-600 dark:text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        <div class="flex-1">
          <div class="font-medium text-green-800 dark:text-green-300">Telegram подключён</div>
          <div class="text-sm text-green-600 dark:text-green-400">Уведомления о новых записях и заказах активны</div>
        </div>
      </div>
      <button @click="showConfirm = true" :disabled="disconnecting" class="btn-secondary text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 border-red-200 dark:border-red-800">
        {{ disconnecting ? 'Отключение...' : 'Отключить Telegram' }}
      </button>
      <div v-if="error" class="text-red-600 text-sm">{{ error }}</div>
    </div>

    <div v-else class="space-y-4">
      <p class="text-gray-500 dark:text-gray-400 text-sm">Подключите Telegram для получения уведомлений о новых заказах и записях.</p>
      <div v-if="telegramCode" class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
        <div class="text-sm text-blue-800 dark:text-blue-300 mb-2">1. Откройте бота <a href="https://t.me/sb_widget_bot" target="_blank" class="font-semibold underline">@sb_widget_bot</a></div>
        <div class="text-sm text-blue-800 dark:text-blue-300 mb-1">2. Отправьте код:</div>
        <div class="font-mono text-lg font-bold text-blue-900 dark:text-blue-200 tracking-widest">{{ telegramCode }}</div>
        <div class="text-xs text-blue-600 dark:text-blue-400 mt-2">Код действителен 10 минут</div>
      </div>
      <button v-if="!telegramCode" @click="generateCode" :disabled="generating" class="btn-primary">
        {{ generating ? 'Генерация...' : 'Получить код подключения' }}
      </button>
      <div v-if="error" class="text-red-600 text-sm">{{ error }}</div>
    </div>
  </div>

  <UiConfirmDialog
    v-model="showConfirm"
    title="Отключить Telegram?"
    confirm-label="Отключить"
    :danger="true"
    @confirm="showConfirm = false; disconnect()"
  >
    Уведомления о новых записях и заказах перестанут приходить. Подключить заново можно в любой момент.
  </UiConfirmDialog>
</template>
