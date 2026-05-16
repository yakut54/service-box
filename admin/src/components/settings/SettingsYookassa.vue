<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'
import PasswordInput from '@/components/PasswordInput.vue'

const authStore = useAuthStore()

const shopId            = ref('')
const secretKey         = ref('')
const prepaymentEnabled = ref(false)
const prepaymentAmount  = ref(0)
const saving            = ref(false)
const success           = ref(false)
const error             = ref('')

onMounted(() => {
  shopId.value            = authStore.shop?.yookassa_shop_id || ''
  prepaymentEnabled.value = authStore.shop?.prepayment_enabled ?? false
  prepaymentAmount.value  = authStore.shop?.prepayment_amount ?? 0
})

async function save() {
  if (!shopId.value) { error.value = 'Укажите Shop ID'; return }
  saving.value  = true
  error.value   = ''
  success.value = false
  try {
    const payload: Record<string, unknown> = {
      yookassa_shop_id:   shopId.value,
      prepayment_enabled: prepaymentEnabled.value,
      prepayment_amount:  prepaymentEnabled.value ? (prepaymentAmount.value || 0) : 0,
    }
    if (secretKey.value) payload.yookassa_secret_key = secretKey.value
    const updated = await api.updateShop(payload)
    if (authStore.shop) {
      authStore.shop.yookassa_shop_id   = updated.yookassa_shop_id ?? shopId.value
      authStore.shop.prepayment_enabled = updated.prepayment_enabled ?? prepaymentEnabled.value
      authStore.shop.prepayment_amount  = updated.prepayment_amount  ?? prepaymentAmount.value
    }
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
        <p class="label">Shop ID <span class="text-gray-400 font-normal">(из личного кабинета ЮКасса)</span></p>
        <input v-model="shopId" type="text" class="input font-mono" placeholder="123456" />
      </div>
      <div>
        <p class="label">Секретный ключ <span class="text-gray-400 font-normal">(оставьте пустым чтобы не менять)</span></p>
        <PasswordInput v-model="secretKey" placeholder="live_xxxxxxxxxxxxxxxxxxxx" />
      </div>

      <hr class="border-gray-200 dark:border-gray-700" />

      <!-- Prepayment -->
      <div>
        <div
          class="flex items-center justify-between cursor-pointer select-none"
          @click="prepaymentEnabled = !prepaymentEnabled"
        >
          <div>
            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Предоплата при записи</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Байер оплачивает онлайн перед визитом</p>
          </div>
          <button
            type="button"
            class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none"
            :class="prepaymentEnabled ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-600'"
          >
            <span
              class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
              :class="prepaymentEnabled ? 'translate-x-4' : 'translate-x-0'"
            />
          </button>
        </div>

        <div v-if="prepaymentEnabled" class="mt-3">
          <p class="label">Сумма предоплаты, ₽ <span class="text-gray-400 font-normal">(0 = полная стоимость услуги)</span></p>
          <input
            v-model.number="prepaymentAmount"
            type="number"
            min="0"
            class="input w-40"
            placeholder="0"
          />
        </div>
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
