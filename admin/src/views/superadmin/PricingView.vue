<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import type { SuperadminPricing } from '@/types'

const pricing = ref<Record<string, SuperadminPricing>>({})
const edits = ref<Record<string, string>>({}) // plan → rubles string for input
const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const success = ref(false)

const planOrder = ['micro', 'start', 'business', 'pro']
const planLabels: Record<string, string> = {
  micro: 'Micro', start: 'Start', business: 'Business', pro: 'Pro',
}
const planDescriptions: Record<string, string> = {
  micro:    'До 50 товаров, базовые функции',
  start:    'До 200 товаров, скидки, отзывы',
  business: 'До 1000 товаров, мастера, записи',
  pro:      'Без ограничений, все функции',
}

async function load() {
  loading.value = true
  error.value = null
  try {
    pricing.value = await api.superadminGetPricing()
    for (const plan of planOrder) {
      const p = pricing.value[plan]
      edits.value[plan] = p ? String(Math.round(p.price_kopecks / 100)) : '0'
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  success.value = false
  error.value = null
  try {
    const items = planOrder.map(plan => ({
      plan,
      price_kopecks: Math.round((parseFloat(edits.value[plan] || '0') || 0) * 100),
    }))
    await api.superadminUpdatePricing(items)
    success.value = true
    await load()
    setTimeout(() => { success.value = false }, 3000)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-6 max-w-2xl">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Тарифы платформы</h1>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
      Цены хранятся в базе данных. Изменения применяются сразу — при следующем создании платежа
      будет использована новая цена.
    </p>

    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <div v-if="error" class="p-4 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-lg text-sm">
        {{ error }}
      </div>
      <div v-if="success" class="p-4 bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 rounded-lg text-sm">
        Цены обновлены
      </div>

      <div class="card divide-y divide-gray-100 dark:divide-gray-800">
        <div v-for="plan in planOrder" :key="plan" class="p-5 flex items-center gap-4">
          <div class="flex-1">
            <p class="font-semibold text-gray-900 dark:text-white">{{ planLabels[plan] }}</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ planDescriptions[plan] }}</p>
          </div>
          <div class="flex items-center gap-2">
            <input
              v-model="edits[plan]"
              type="number"
              min="0"
              step="100"
              class="input w-32 text-right"
              placeholder="0"
            />
            <span class="text-sm text-gray-500 dark:text-gray-400">₽/мес</span>
          </div>
        </div>
      </div>

      <div class="flex justify-end">
        <button @click="save" :disabled="saving" class="btn-primary px-8">
          {{ saving ? 'Сохранение...' : 'Сохранить цены' }}
        </button>
      </div>
    </template>
  </div>
</template>
