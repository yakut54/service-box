<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import type { SuperadminPricing } from '@/types'

interface PlanEdit {
  price_rubles: string
  max_orders: string
  max_masters: string
  features: string[]
  newFeature: string
}

const pricing = ref<Record<string, SuperadminPricing>>({})
const edits = ref<Record<string, PlanEdit>>({})
const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const success = ref(false)

const planOrder = ['micro', 'start', 'business', 'pro']
const planLabels: Record<string, string> = {
  micro: 'Micro', start: 'Start', business: 'Business', pro: 'Pro',
}
const planColors: Record<string, string> = {
  micro:    'border-gray-200 dark:border-gray-700',
  start:    'border-blue-200 dark:border-blue-800',
  business: 'border-primary-300 dark:border-primary-700',
  pro:      'border-purple-300 dark:border-purple-700',
}
const planBadges: Record<string, string> = {
  micro:    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  start:    'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
  business: 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  pro:      'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
}

function initEdits(p: SuperadminPricing): PlanEdit {
  return {
    price_rubles: String(Math.round(p.price_kopecks / 100)),
    max_orders:   p.max_orders_per_month != null ? String(p.max_orders_per_month) : '',
    max_masters:  p.max_masters != null ? String(p.max_masters) : '',
    features:     [...(p.features ?? [])],
    newFeature:   '',
  }
}

async function load() {
  loading.value = true
  error.value = null
  try {
    pricing.value = await api.superadminGetPricing()
    for (const plan of planOrder) {
      const p = pricing.value[plan]
      if (p) edits.value[plan] = initEdits(p)
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

function addFeature(plan: string) {
  const ed = edits.value[plan]
  const text = ed.newFeature.trim()
  if (!text) return
  ed.features.push(text)
  ed.newFeature = ''
}

function removeFeature(plan: string, idx: number) {
  edits.value[plan].features.splice(idx, 1)
}

function onFeatureKeydown(plan: string, e: KeyboardEvent) {
  if (e.key === 'Enter') {
    e.preventDefault()
    addFeature(plan)
  }
}

async function save() {
  saving.value = true
  success.value = false
  error.value = null
  try {
    const items = planOrder.map(plan => {
      const ed = edits.value[plan]
      return {
        plan,
        price_kopecks:        Math.round((parseFloat(ed.price_rubles) || 0) * 100),
        max_orders_per_month: ed.max_orders ? (parseInt(ed.max_orders) || null) : null,
        max_masters:          ed.max_masters ? (parseInt(ed.max_masters) || null) : null,
        features:             ed.features,
      }
    })
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

onMounted(load)
</script>

<template>
  <div class="flex flex-col gap-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Тарифные планы</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
          Изменения применяются сразу — без деплоя.
        </p>
      </div>
      <button @click="save" :disabled="saving || loading" class="btn-primary px-6">
        {{ saving ? 'Сохранение...' : 'Сохранить всё' }}
      </button>
    </div>

    <div v-if="error" class="p-4 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-lg text-sm">
      {{ error }}
    </div>
    <div v-if="success" class="p-4 bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 rounded-lg text-sm">
      Тарифы обновлены
    </div>

    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <div
        v-for="plan in planOrder"
        :key="plan"
        class="card border-2 flex flex-col gap-5"
        :class="planColors[plan]"
      >
        <!-- Header -->
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold px-2.5 py-1 rounded-full" :class="planBadges[plan]">
            {{ planLabels[plan] }}
          </span>
        </div>

        <!-- Price -->
        <div>
          <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Цена</label>
          <div class="flex items-center gap-2">
            <input
              v-model="edits[plan].price_rubles"
              type="number" min="0" step="100"
              class="input w-32 text-right"
            />
            <span class="text-sm text-gray-500 dark:text-gray-400">₽ / месяц</span>
          </div>
        </div>

        <!-- Limits -->
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
              Заказов в месяц
            </label>
            <input
              v-model="edits[plan].max_orders"
              type="number" min="1"
              placeholder="∞ (без лимита)"
              class="input w-full"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
              Мастеров
            </label>
            <input
              v-model="edits[plan].max_masters"
              type="number" min="1"
              placeholder="∞ (без лимита)"
              class="input w-full"
            />
          </div>
        </div>
        <p class="text-xs text-gray-400 dark:text-gray-500 -mt-3">Оставьте пустым — без ограничений</p>

        <!-- Features -->
        <div class="flex flex-col gap-2">
          <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
            Преимущества (отображаются на странице тарифов)
          </label>

          <div
            v-for="(feat, idx) in edits[plan].features"
            :key="idx"
            class="flex items-center gap-2 group"
          >
            <input
              v-model="edits[plan].features[idx]"
              type="text"
              class="input flex-1 text-sm"
            />
            <button
              @click="removeFeature(plan, idx)"
              class="p-1.5 rounded text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
              title="Удалить"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- Add new feature -->
          <div class="flex items-center gap-2 mt-1">
            <input
              v-model="edits[plan].newFeature"
              type="text"
              placeholder="Добавить строку..."
              class="input flex-1 text-sm"
              @keydown="onFeatureKeydown(plan, $event)"
            />
            <button
              @click="addFeature(plan)"
              class="p-1.5 rounded text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors"
              title="Добавить"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
