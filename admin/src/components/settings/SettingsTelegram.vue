<script setup lang="ts">
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'

const authStore = useAuthStore()

const connecting    = ref(false)
const awaiting      = ref(false)
const error         = ref('')
const disconnecting = ref(false)
const showConfirm   = ref(false)

const hasFeature = computed(() =>
  ['start', 'business', 'pro'].includes(authStore.shop?.subscription_plan ?? '')
)

async function connect() {
  connecting.value = true
  error.value = ''
  try {
    const resp = await api.generateTelegramCode()
    window.open(`https://t.me/sb_widget_bot?start=${resp.code}`, '_blank')
    awaiting.value = true
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось подключить Telegram')
  }
  connecting.value = false
}

async function checkConnection() {
  try {
    const data = await api.me()
    if (authStore.shop) {
      authStore.shop.telegram_bot_connected = data.shop?.telegram_bot_connected ?? false
      authStore.shop.telegram_chat_id = data.shop?.telegram_chat_id ?? null
    }
    if (data.shop?.telegram_bot_connected) awaiting.value = false
  } catch { /* ignore */ }
}

async function disconnect() {
  disconnecting.value = true
  try {
    await api.disconnectTelegram()
    if (authStore.shop) {
      authStore.shop.telegram_bot_connected = false
      authStore.shop.telegram_chat_id = null
    }
    awaiting.value = false
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось отключить Telegram')
  }
  disconnecting.value = false
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Telegram уведомления</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Мгновенные уведомления о новых записях и заказах.</p>

    <!-- Plan gate -->
    <div v-if="!hasFeature" class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
      <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m2-6a4 4 0 100-8 4 4 0 000 8z" />
      </svg>
      <div>
        <div class="font-medium text-gray-700 dark:text-gray-300 text-sm">Недоступно на тарифе Micro</div>
        <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Доступно на тарифах <span class="font-medium">Start и выше</span>.</div>
      </div>
    </div>

    <!-- Connected -->
    <div v-else-if="authStore.shop?.telegram_bot_connected" class="space-y-3">
      <div class="flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
        <svg class="w-6 h-6 text-green-600 dark:text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        <div>
          <div class="font-medium text-green-800 dark:text-green-300">Telegram подключён</div>
          <div class="text-sm text-green-600 dark:text-green-400">Уведомления активны</div>
        </div>
      </div>
      <button @click="showConfirm = true" :disabled="disconnecting"
        class="btn-secondary text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 border-red-200 dark:border-red-800">
        {{ disconnecting ? 'Отключение...' : 'Отключить Telegram' }}
      </button>
      <div v-if="error" class="text-red-600 dark:text-red-400 text-sm">{{ error }}</div>
    </div>

    <!-- Awaiting confirmation in bot -->
    <div v-else-if="awaiting" class="space-y-3">
      <div class="flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
        <svg class="w-5 h-5 text-blue-500 shrink-0 mt-0.5 animate-pulse" fill="currentColor" viewBox="0 0 24 24">
          <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L7.17 13.5l-2.95-.924c-.64-.203-.654-.64.136-.954l11.57-4.461c.537-.194 1.006.131.968.06z"/>
        </svg>
        <div>
          <div class="font-medium text-blue-800 dark:text-blue-300 text-sm">Telegram открыт — нажмите «Start» в боте</div>
          <div class="text-xs text-blue-600 dark:text-blue-400 mt-0.5">После нажатия вернитесь сюда и проверьте подключение</div>
        </div>
      </div>
      <div class="flex gap-2">
        <button @click="checkConnection" class="btn-primary text-sm">Проверить подключение</button>
        <button @click="connect" :disabled="connecting" class="btn-secondary text-sm">Открыть бота снова</button>
      </div>
      <div v-if="error" class="text-red-600 dark:text-red-400 text-sm">{{ error }}</div>
    </div>

    <!-- Not connected -->
    <div v-else class="space-y-3">
      <button @click="connect" :disabled="connecting" class="btn-primary">
        <svg v-if="!connecting" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
          <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L7.17 13.5l-2.95-.924c-.64-.203-.654-.64.136-.954l11.57-4.461c.537-.194 1.006.131.968.06z"/>
        </svg>
        <svg v-else class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        {{ connecting ? 'Открываем Telegram...' : 'Подключить через Telegram' }}
      </button>
      <div v-if="error" class="text-red-600 dark:text-red-400 text-sm">{{ error }}</div>
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
