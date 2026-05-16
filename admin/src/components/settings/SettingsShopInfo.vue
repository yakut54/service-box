<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/lib/api'

const authStore = useAuthStore()

const shopName    = ref('')
const shopDomain  = ref('')
const saving      = ref(false)
const success     = ref(false)
const error       = ref('')

onMounted(() => {
  shopName.value   = authStore.shop?.name   || ''
  shopDomain.value = authStore.shop?.domain || ''
})

async function save() {
  if (!shopName.value.trim()) { error.value = 'Название не может быть пустым'; return }
  saving.value  = true
  error.value   = ''
  success.value = false
  try {
    const updated = await api.updateShop({ name: shopName.value.trim(), domain: shopDomain.value.trim() || null })
    if (authStore.shop) { authStore.shop.name = updated.name; authStore.shop.domain = updated.domain }
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
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Информация о магазине</h2>
    <div class="space-y-4">
      <div>
        <p class="label">Название магазина</p>
        <input v-model="shopName" type="text" class="input" placeholder="Название магазина" />
      </div>
      <div>
        <p class="label">Сайт магазина <span class="text-gray-400 font-normal">(используется в Telegram-уведомлениях)</span></p>
        <input v-model="shopDomain" type="url" class="input" placeholder="https://example.com" />
      </div>
      <div>
        <button @click="save" :disabled="saving" class="btn-primary">
          {{ saving ? 'Сохраняем…' : success ? 'Сохранено!' : 'Сохранить' }}
        </button>
        <p v-if="error" class="text-red-500 text-xs mt-1">{{ error }}</p>
      </div>
      <div>
        <p class="label">API ключ</p>
        <input type="text" :value="authStore.shop?.api_key" class="input font-mono text-sm" disabled />
      </div>
      <div>
        <p class="label">Тарифный план</p>
        <div class="flex items-center gap-3">
          <span class="badge bg-primary-100 dark:bg-primary-900/30 text-primary-800 dark:text-primary-300 text-sm px-3 py-1">
            {{ authStore.shop?.subscription_plan?.toUpperCase() || 'MICRO' }}
          </span>
          <span class="text-sm text-gray-500 dark:text-gray-400">
            Действует до: {{ authStore.shop?.subscription_expires_at
              ? new Date(authStore.shop.subscription_expires_at).toLocaleDateString('ru-RU')
              : 'бессрочно' }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
