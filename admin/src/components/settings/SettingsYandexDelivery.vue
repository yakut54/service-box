<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import PasswordInput from '@/components/PasswordInput.vue'

const enabled           = ref(false)
const apiToken          = ref('')
const hasToken          = ref(false)
const warehouseAddress  = ref('')
const freeFrom          = ref('')

const loading = ref(true)
const saving  = ref(false)
const success = ref(false)
const error   = ref('')

onMounted(async () => {
  try {
    const res = await api.getDeliverySettings()
    const y = (res.data as Record<string, any>).yandex
    if (y) {
      enabled.value         = !!y.enabled
      hasToken.value        = !!y.api_token
      warehouseAddress.value = y.warehouse_address ?? ''
      freeFrom.value         = y.free_from != null ? String(y.free_from) : ''
    }
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось загрузить настройки')
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value  = true
  success.value = false
  error.value   = ''
  try {
    const payload: Record<string, unknown> = {
      enabled,
      warehouse_address: warehouseAddress.value.trim() || null,
      free_from: freeFrom.value ? Math.round(parseFloat(freeFrom.value) || 0) : null,
    }
    // Не перезаписываем сохранённый токен пустым — только если ввели новый.
    if (apiToken.value) payload.api_token = apiToken.value

    const res = await api.updateDeliverySettings({ yandex: payload })
    const y = (res.data as Record<string, any>).yandex
    hasToken.value = !!y?.api_token
    apiToken.value = ''
    success.value = true
    setTimeout(() => { success.value = false }, 3000)
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось сохранить настройки')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Яндекс.Доставка</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Автоматический расчёт стоимости и заказ курьера.</p>

    <div class="mb-4 flex gap-2.5 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
      <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0zM12 9v4m0 4h.01"/>
      </svg>
      <p class="text-sm text-amber-800 dark:text-amber-300">
        В разработке — ждём API-ключ от Яндекса. Настройки уже можно заполнить,
        они сохранятся и заработают сами, как только интеграция будет готова с нашей стороны.
      </p>
    </div>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <div v-else class="space-y-4">
      <div class="flex items-center justify-between cursor-pointer select-none" @click="enabled = !enabled">
        <div>
          <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Подключить Яндекс.Доставку</p>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Пока не влияет на витрину — только сохраняет намерение</p>
        </div>
        <button
          type="button"
          class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none"
          :class="enabled ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-600'"
        >
          <span
            class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
            :class="enabled ? 'translate-x-4' : 'translate-x-0'"
          />
        </button>
      </div>

      <div>
        <p class="label">
          API-токен
          <span class="text-gray-400 font-normal">
            ({{ hasToken ? 'сохранён — оставьте пустым, чтобы не менять' : 'из личного кабинета dostavka.yandex.ru' }})
          </span>
        </p>
        <PasswordInput v-model="apiToken" placeholder="y0_xxxxxxxxxxxxxxxxxxxx" />
      </div>

      <div>
        <p class="label">Адрес склада <span class="text-gray-400 font-normal">(откуда забирает курьер)</span></p>
        <input v-model="warehouseAddress" type="text" class="input" placeholder="Красноярская 49" />
      </div>

      <div class="w-48">
        <p class="label">Бесплатно от, ₽</p>
        <input v-model="freeFrom" type="number" min="0" step="100" placeholder="—" class="input text-right" />
        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Пусто — порог не действует, доставка всегда платная</p>
      </div>

      <div v-if="error"   class="text-sm text-red-600">{{ error }}</div>
      <div v-if="success" class="text-sm text-green-600">Сохранено!</div>

      <button @click="save" :disabled="saving" class="btn-primary">
        {{ saving ? 'Сохранение...' : 'Сохранить' }}
      </button>
    </div>
  </div>
</template>
