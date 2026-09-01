<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/parseApiError'
import UiHint from '@/shared/ui/UiHint.vue'

const authStore = useAuthStore()

const hidePhone = ref(authStore.shop?.hide_customer_phone ?? false)
const saving    = ref(false)
const success   = ref(false)
const error     = ref('')

watch(() => authStore.shop?.hide_customer_phone, (val) => {
  if (val !== undefined) hidePhone.value = val
})

async function save() {
  saving.value  = true
  error.value   = ''
  success.value = false
  try {
    await authStore.updateShop({ hide_customer_phone: hidePhone.value })
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
  <div class="card p-5">
    <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Мастера</h2>

    <!-- Hide customer phone toggle -->
    <div class="flex items-start justify-between gap-4">
      <p class="text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center gap-1">
        Скрывать телефон клиента
        <UiHint>Мастер не видит номер телефона в своём портале и уведомлениях. Защита клиентской базы.</UiHint>
      </p>
      <button
        type="button"
        @click="hidePhone = !hidePhone"
        :class="['relative flex-shrink-0 w-11 h-6 rounded-full transition-colors', hidePhone ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600']"
      >
        <span :class="['absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition-transform', hidePhone ? 'translate-x-5' : '']" />
      </button>
    </div>

    <div v-if="error" class="mt-3 text-xs text-red-500">{{ error }}</div>

    <div class="mt-4 flex items-center gap-3">
      <button @click="save" :disabled="saving" class="btn-primary btn-sm">
        {{ saving ? 'Сохранение...' : 'Сохранить' }}
      </button>
      <span v-if="success" class="text-xs text-emerald-600 dark:text-emerald-400">Сохранено</span>
    </div>
  </div>
</template>
