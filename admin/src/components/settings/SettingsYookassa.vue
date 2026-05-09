<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import PasswordInput from '@/components/PasswordInput.vue'

const authStore = useAuthStore()

const shopId    = ref('')
const secretKey = ref('')
const saving    = ref(false)
const success   = ref(false)
const error     = ref('')

onMounted(() => {
  shopId.value = authStore.shop?.yookassa_shop_id || ''
})

async function save() {
  if (!shopId.value) { error.value = 'Укажите Shop ID'; return }
  saving.value  = true
  error.value   = ''
  success.value = false
  try {
    const payload: Record<string, string | null> = { yookassa_shop_id: shopId.value }
    if (secretKey.value) payload.yookassa_secret_key = secretKey.value
    const updated = await api.updateShop(payload)
    if (authStore.shop) authStore.shop.yookassa_shop_id = updated.yookassa_shop_id ?? shopId.value
    secretKey.value = ''
    success.value = true
    setTimeout(() => success.value = false, 3000)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">ЮКасса — приём оплаты</h2>

    <div v-if="authStore.shop?.yookassa_shop_id" class="mb-4 flex items-center gap-2 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
      <svg class="w-4 h-4 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <span class="text-sm text-green-700 dark:text-green-400">Подключено · Shop ID: <span class="font-mono font-medium">{{ authStore.shop.yookassa_shop_id }}</span></span>
    </div>

    <div class="space-y-4">
      <div>
        <label class="label">Shop ID <span class="text-gray-400 font-normal">(из личного кабинета ЮКасса)</span></label>
        <input v-model="shopId" type="text" class="input font-mono" placeholder="123456" />
      </div>
      <div>
        <label class="label">Секретный ключ <span class="text-gray-400 font-normal">(оставьте пустым чтобы не менять)</span></label>
        <PasswordInput v-model="secretKey" placeholder="live_xxxxxxxxxxxxxxxxxxxx" />
      </div>

      <div v-if="error"   class="text-sm text-red-600">{{ error }}</div>
      <div v-if="success" class="text-sm text-green-600">Сохранено!</div>

      <button @click="save" :disabled="saving" class="btn-primary">
        {{ saving ? 'Сохранение...' : 'Сохранить' }}
      </button>

      <p class="text-xs text-gray-400 dark:text-gray-500">
        Настройки из раздела «Интеграции → API» в
        <a href="https://yookassa.ru/my" target="_blank" rel="noopener" class="text-primary-500 hover:underline">личном кабинете ЮКасса</a>.
        Секретный ключ нужен для приёма платежей в виджете магазина.
      </p>
    </div>
  </div>
</template>
