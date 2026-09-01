<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import UiHint from '@/shared/ui/UiHint.vue'

interface MethodForm {
  enabled:   boolean
  price:     string
  address:   string
  free_from: string
}

const methods = ref<Record<string, MethodForm>>({
  pickup:  { enabled: false, price: '0',  address: '', free_from: '' },
  courier: { enabled: false, price: '0',  address: '', free_from: '' },
  postal:  { enabled: false, price: '0',  address: '', free_from: '' },
})

const loading = ref(true)
const saving  = ref(false)
const success = ref(false)
const error   = ref('')

const labels: Record<string, string> = {
  pickup:  'Самовывоз',
  courier: 'Доставка курьером',
  postal:  'Почта / СДЭК',
}

const icons: Record<string, string> = {
  pickup:  'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 8v-4a1 1 0 011-1h2a1 1 0 011 1v4',
  courier: 'M9 17a2 2 0 11-4 0 2 2 0 014 0zm10 0a2 2 0 11-4 0 2 2 0 014 0zM1 1h4l2.68 13.39a2 2 0 001.98 1.61h9.72a2 2 0 001.98-1.61L23 6H6',
  postal:  'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
}

onMounted(async () => {
  try {
    const res = await api.getDeliverySettings()
    const data = res.data
    for (const key of ['pickup', 'courier', 'postal']) {
      const m = data[key]
      if (m) {
        methods.value[key] = {
          enabled:   !!m.enabled,
          price:     String((m.price ?? 0) / 100),
          address:   m.address ?? '',
          free_from: m.free_from != null ? String(m.free_from / 100) : '',
        }
      }
    }
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось загрузить настройки доставки')
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value  = true
  success.value = false
  error.value   = ''
  try {
    const payload: Record<string, unknown> = {}
    for (const key of ['pickup', 'courier', 'postal']) {
      const m = methods.value[key]
      payload[key] = {
        enabled:   m.enabled,
        price:     Math.round((parseFloat(m.price) || 0) * 100),
        address:   key === 'pickup' ? (m.address.trim() || null) : undefined,
        free_from: key !== 'pickup' && m.free_from
          ? Math.round((parseFloat(m.free_from) || 0) * 100)
          : null,
      }
    }
    await api.updateDeliverySettings(payload)
    success.value = true
    setTimeout(() => { success.value = false }, 3000)
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось сохранить настройки доставки')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="card">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Доставка</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Способы получения физических товаров.</p>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- Курьер и Почта/СДЭК скрыты (2026-08-20) — пока в фокусе самовывоз и
           Яндекс.Доставка; их состояние никуда не делось и сохраняется на save() как есть. -->
      <div class="flex flex-col gap-4">
        <div
          v-for="key in ['pickup']"
          :key="key"
          class="rounded-lg border transition-colors"
          :class="methods[key].enabled
            ? 'border-primary-300 dark:border-primary-700 bg-primary-50/30 dark:bg-primary-900/10'
            : 'border-gray-200 dark:border-gray-700'"
        >
          <!-- Toggle row -->
          <div class="flex items-center justify-between p-3 cursor-pointer select-none" @click="methods[key].enabled = !methods[key].enabled">
            <div class="flex items-center gap-2.5">
              <svg class="w-4 h-4 text-gray-500 dark:text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icons[key]" />
              </svg>
              <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ labels[key] }}</span>
            </div>
            <button
              type="button"
              class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none"
              :class="methods[key].enabled ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-600'"
              @click.stop="methods[key].enabled = !methods[key].enabled"
            >
              <span
                class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                :class="methods[key].enabled ? 'translate-x-4' : 'translate-x-0'"
              />
            </button>
          </div>

          <!-- Fields (shown when enabled) -->
          <div v-if="methods[key].enabled" class="px-3 pb-3 flex flex-col gap-2.5 border-t border-gray-200 dark:border-gray-700 pt-3">

            <!-- Pickup: address + price (usually 0) -->
            <template v-if="key === 'pickup'">
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Адрес самовывоза</label>
                <input
                  v-model="methods[key].address"
                  type="text"
                  class="input text-sm"
                  placeholder="ул. Ленина, 1 — оставьте пустым если не нужно"
                />
              </div>
              <div class="w-40">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Стоимость</label>
                <div class="flex items-center gap-2">
                  <input v-model="methods[key].price" type="number" min="0" step="10" class="input text-sm w-24 text-right" />
                  <span class="text-sm text-gray-400">₽</span>
                </div>
              </div>
            </template>

            <!-- Courier / Postal: price + free_from -->
            <template v-else>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Стоимость</label>
                  <div class="flex items-center gap-2">
                    <input v-model="methods[key].price" type="number" min="0" step="50" class="input text-sm text-right" />
                    <span class="text-sm text-gray-400">₽</span>
                  </div>
                </div>
                <div>
                  <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 flex items-center gap-1">
                    Бесплатно от
                    <UiHint>Оставьте пустым — порог не действует</UiHint>
                  </label>
                  <div class="flex items-center gap-2">
                    <input
                      v-model="methods[key].free_from"
                      type="number" min="0" step="100"
                      placeholder="—"
                      class="input text-sm text-right"
                    />
                    <span class="text-sm text-gray-400">₽</span>
                  </div>
                </div>
              </div>
            </template>

          </div>
        </div>
      </div>

      <div v-if="error"   class="mt-3 text-sm text-red-600 dark:text-red-400">{{ error }}</div>
      <div v-if="success" class="mt-3 text-sm text-green-600 dark:text-green-400">Сохранено!</div>

      <button @click="save" :disabled="saving" class="btn-primary mt-4">
        {{ saving ? 'Сохранение...' : 'Сохранить' }}
      </button>
    </template>
  </div>
</template>
