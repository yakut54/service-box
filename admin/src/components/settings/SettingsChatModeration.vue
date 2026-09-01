<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/parseApiError'
import UiCheckbox from '@/shared/ui/UiCheckbox.vue'
import UiHint from '@/shared/ui/UiHint.vue'

const authStore = useAuthStore()

const customerDeleteEnabled = ref(authStore.shop?.chat_customer_delete_enabled ?? false)
const saving = ref(false)
const success = ref(false)
const error = ref('')

watch(() => authStore.shop?.chat_customer_delete_enabled, (val) => {
  if (val !== undefined) customerDeleteEnabled.value = val
})

async function save() {
  saving.value = true
  error.value = ''
  success.value = false
  try {
    await authStore.updateShop({ chat_customer_delete_enabled: customerDeleteEnabled.value })
    success.value = true
    setTimeout(() => { success.value = false }, 2000)
  } catch (e) {
    error.value = parseApiError(e, 'Не удалось сохранить')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Чат с покупателями</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Модерация переписки</p>

    <UiCheckbox v-model="customerDeleteEnabled" align="start">
      <span class="text-sm font-medium text-gray-700 dark:text-gray-300 inline-flex items-center gap-1">
        Разрешить покупателям удалять свои сообщения
        <UiHint>
          По умолчанию выключено — удалять сообщения в чате может только магазин
          (модерация). Сообщения магазина покупатель не может удалить в любом случае.
        </UiHint>
      </span>
    </UiCheckbox>

    <div v-if="error" class="mt-3 text-xs text-red-500">{{ error }}</div>

    <div class="mt-4 flex items-center gap-3">
      <button @click="save" :disabled="saving" class="btn-primary btn-sm">
        {{ saving ? 'Сохранение...' : 'Сохранить' }}
      </button>
      <span v-if="success" class="text-xs text-emerald-600 dark:text-emerald-400">Сохранено</span>
    </div>
  </div>
</template>
