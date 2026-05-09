<script setup lang="ts">
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'

const authStore = useAuthStore()

const connecting    = ref(false)
const awaiting      = ref(false)
const error         = ref('')
const disconnecting = ref(false)
const showConfirm   = ref(false)
const maxCode       = ref('')
const botUrl        = ref('')
const codeCopied    = ref(false)

const hasFeature = computed(() =>
  ['start', 'business', 'pro'].includes(authStore.shop?.subscription_plan ?? '')
)

async function connect() {
  connecting.value = true
  error.value = ''
  try {
    const resp = await api.generateMaxCode()
    maxCode.value = resp.code
    botUrl.value  = resp.bot_username ? `https://max.ru/${resp.bot_username}` : 'https://max.ru'
    await navigator.clipboard.writeText(resp.code)
    codeCopied.value = true
    window.open(botUrl.value, '_blank')
    awaiting.value = true
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка подключения'
  }
  connecting.value = false
}

async function checkConnection() {
  try {
    const data = await api.me()
    if (authStore.shop) {
      authStore.shop.max_bot_connected = data.shop?.max_bot_connected ?? false
      authStore.shop.max_chat_id = data.shop?.max_chat_id ?? null
    }
    if (data.shop?.max_bot_connected) awaiting.value = false
  } catch { /* ignore */ }
}

function copyCode() {
  navigator.clipboard.writeText(maxCode.value)
  codeCopied.value = true
}

async function disconnect() {
  disconnecting.value = true
  try {
    await api.disconnectMax()
    if (authStore.shop) {
      authStore.shop.max_bot_connected = false
      authStore.shop.max_chat_id = null
    }
    awaiting.value = false
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка отключения'
  }
  disconnecting.value = false
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">MAX уведомления</h2>
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
    <div v-else-if="authStore.shop?.max_bot_connected" class="space-y-3">
      <div class="flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
        <svg class="w-6 h-6 text-green-600 dark:text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        <div>
          <div class="font-medium text-green-800 dark:text-green-300">MAX подключён</div>
          <div class="text-sm text-green-600 dark:text-green-400">Уведомления активны</div>
        </div>
      </div>
      <button @click="showConfirm = true" :disabled="disconnecting"
        class="btn-secondary text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 border-red-200 dark:border-red-800">
        {{ disconnecting ? 'Отключение...' : 'Отключить MAX' }}
      </button>
      <div v-if="error" class="text-red-600 dark:text-red-400 text-sm">{{ error }}</div>
    </div>

    <!-- Awaiting confirmation in bot -->
    <div v-else-if="awaiting" class="space-y-3">
      <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800 space-y-3">
        <div class="text-sm font-medium text-blue-800 dark:text-blue-300">Бот открыт в новой вкладке</div>
        <div>
          <div class="text-xs text-blue-600 dark:text-blue-400 mb-1">Отправьте этот код боту:</div>
          <div class="flex items-center gap-2">
            <span class="font-mono text-xl font-bold tracking-widest text-blue-900 dark:text-blue-100">{{ maxCode }}</span>
            <button @click="copyCode"
              class="text-xs px-2 py-1 bg-blue-100 dark:bg-blue-800 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 transition-colors">
              {{ codeCopied ? '✓ Скопировано' : 'Копировать' }}
            </button>
          </div>
        </div>
        <ol class="text-xs text-blue-600 dark:text-blue-400 space-y-1 list-decimal list-inside">
          <li>В боте введите код в поле чата и отправьте</li>
          <li>Вернитесь сюда и нажмите «Проверить»</li>
        </ol>
      </div>
      <div class="flex gap-2">
        <button @click="checkConnection" class="btn-primary text-sm">Проверить подключение</button>
        <a :href="botUrl" target="_blank" class="btn-secondary text-sm">Открыть бота снова</a>
      </div>
      <div v-if="error" class="text-red-600 dark:text-red-400 text-sm">{{ error }}</div>
    </div>

    <!-- Not connected -->
    <div v-else class="space-y-3">
      <button @click="connect" :disabled="connecting" class="btn-primary">
        <svg v-if="!connecting" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
        <svg v-else class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        {{ connecting ? 'Открываем MAX...' : 'Подключить через MAX' }}
      </button>
      <div v-if="error" class="text-red-600 dark:text-red-400 text-sm">{{ error }}</div>
    </div>
  </div>

  <UiConfirmDialog
    v-model="showConfirm"
    title="Отключить MAX?"
    confirm-label="Отключить"
    :danger="true"
    @confirm="showConfirm = false; disconnect()"
  >
    Уведомления о новых записях и заказах перестанут приходить. Подключить заново можно в любой момент.
  </UiConfirmDialog>
</template>
