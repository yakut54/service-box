<script setup lang="ts">
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'

const authStore = useAuthStore()

const isPro    = computed(() => authStore.shop?.subscription_plan === 'pro')
const apiKey   = computed(() => authStore.shop?.api_key ?? '')
const apiBase  = computed(() => (import.meta.env.VITE_API_URL || '/api').replace(/\/api$/, '/api'))

const revealed     = ref(false)
const copied       = ref(false)
const showConfirm  = ref(false)
const regenerating = ref(false)
const error        = ref('')

const maskedKey = computed(() =>
  apiKey.value ? apiKey.value.slice(0, 8) + '••••••••••••••••••••••••••••' : ''
)

const exampleCurl = computed(() =>
  `curl ${apiBase.value}/v1/bookings \\\n  -H "X-API-Key: ${apiKey.value}"`
)

function copyKey() {
  navigator.clipboard.writeText(apiKey.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}

async function regenerate() {
  regenerating.value = true
  error.value = ''
  try {
    const res = await api.regenerateApiKey()
    if (authStore.shop) authStore.shop.api_key = res.api_key
    revealed.value = false
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка'
  } finally {
    regenerating.value = false
    showConfirm.value = false
  }
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">API доступ</h2>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">
      Ваш ключ для подключения внешних систем: CRM, боты, скрипты
    </p>

    <!-- Not Pro -->
    <div v-if="!isPro" class="rounded-xl bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 p-4 text-sm text-indigo-700 dark:text-indigo-300">
      Доступно на тарифе <strong>Pro</strong>. Перейдите в раздел «Подписка» для смены тарифа.
    </div>

    <!-- Pro -->
    <template v-else>
      <!-- Key display -->
      <div class="mb-4">
        <p class="label mb-1">Ваш API-ключ</p>
        <div class="flex items-center gap-2">
          <div class="flex-1 font-mono text-sm bg-gray-100 dark:bg-gray-800 rounded-lg px-3 py-2 text-gray-800 dark:text-gray-200 break-all select-all">
            {{ revealed ? apiKey : maskedKey }}
          </div>
          <button type="button" class="btn-ghost btn-sm flex-shrink-0" @click="revealed = !revealed">
            {{ revealed ? 'Скрыть' : 'Показать' }}
          </button>
          <button type="button" class="btn-ghost btn-sm flex-shrink-0" @click="copyKey">
            <svg v-if="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
            <svg v-else class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            {{ copied ? 'Скопировано!' : 'Копировать' }}
          </button>
        </div>
      </div>

      <!-- Regenerate -->
      <div class="mb-5">
        <button type="button" class="btn-secondary btn-sm text-red-600 dark:text-red-400 border-red-200 dark:border-red-800 hover:bg-red-50 dark:hover:bg-red-900/20"
                @click="showConfirm = true" :disabled="regenerating">
          Перегенерировать ключ
        </button>
        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
          Старый ключ перестанет работать немедленно
        </p>
        <p v-if="error" class="text-xs text-red-500 mt-1">{{ error }}</p>
      </div>

      <!-- Usage example -->
      <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
        <p class="label mb-2">Пример запроса</p>
        <div class="relative">
          <pre class="bg-gray-900 text-green-400 rounded-lg p-3 text-xs overflow-x-auto whitespace-pre">{{ exampleCurl }}</pre>
        </div>
        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">
          Передавайте ключ в заголовке <code class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">X-API-Key</code> каждого запроса
        </p>
      </div>
    </template>

    <UiConfirmDialog
      v-model="showConfirm"
      title="Перегенерировать API-ключ?"
      confirm-label="Перегенерировать"
      :loading="regenerating"
      :danger="false"
      @confirm="regenerate"
    >
      Старый ключ перестанет работать сразу. Все подключённые системы нужно будет обновить вручную.
    </UiConfirmDialog>
  </div>
</template>
