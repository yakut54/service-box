<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api } from '@/lib/api'
import { useMailFailuresStore } from '@/stores/mailFailures'
import { parseApiError } from '@/lib/parseApiError'
import { formatDateTime } from '@/shared/lib/format'
import UiSpinner from '@/shared/ui/UiSpinner.vue'
import type { MailFailure } from '@/types'

const mailFailuresStore = useMailFailuresStore()

const failures = ref<MailFailure[]>([])
const loading = ref(true)
const marking = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    failures.value = (await api.getMailFailures()).data
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось загрузить список')
  }
  loading.value = false
}

async function markSeen() {
  marking.value = true
  try {
    await api.markMailFailuresSeen()
    await mailFailuresStore.fetchPendingCount()
  } catch { /* бейдж не критичен — тихо игнорируем */ }
  marking.value = false
}

function describe(f: MailFailure): string {
  if (f.entity_type === 'config') {
    return 'Почта на сервере не настроена — письма никому не доходят'
  }
  const what = f.entity_type === 'order' ? 'заказе' : 'записи'
  const who = f.recipient_type === 'shop_owner' ? 'вам' : 'покупателю'
  const shortId = f.entity_id ? f.entity_id.slice(0, 8) : '—'
  return `Не удалось отправить письмо ${who} о ${what} №${shortId}`
}

onMounted(load)
</script>

<template>
  <div class="card">
    <div class="flex items-start justify-between gap-3 mb-1">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Ошибки отправки писем</h2>
      <button
        v-if="failures.length > 0"
        @click="markSeen"
        :disabled="marking"
        class="btn-secondary text-sm shrink-0"
      >
        {{ marking ? 'Отмечаем...' : 'Отметить прочитанным' }}
      </button>
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
      Письма о заказах и записях, которые не удалось доставить.
    </p>

    <div v-if="loading" class="flex items-center justify-center py-10">
      <UiSpinner />
    </div>

    <div v-else-if="error" class="text-red-600 dark:text-red-400 text-sm">{{ error }}</div>

    <div v-else-if="failures.length === 0" class="flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
      <svg class="w-6 h-6 text-green-600 dark:text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <div>
        <div class="font-medium text-green-800 dark:text-green-300">Ошибок нет</div>
        <div class="text-sm text-green-600 dark:text-green-400">Все письма доставляются успешно</div>
      </div>
    </div>

    <ul v-else class="space-y-2">
      <li
        v-for="f in failures"
        :key="f.id"
        class="flex items-start gap-3 p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg border border-amber-200 dark:border-amber-800"
      >
        <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <div class="min-w-0">
          <div class="text-sm font-medium text-amber-900 dark:text-amber-200">{{ describe(f) }}</div>
          <div v-if="f.recipient_email" class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">{{ f.recipient_email }}</div>
          <div v-if="f.error_message" class="text-xs text-amber-600 dark:text-amber-500 mt-1 break-words">{{ f.error_message }}</div>
          <div class="text-xs text-amber-500 dark:text-amber-600 mt-1">{{ formatDateTime(f.created_at) }}</div>
        </div>
      </li>
    </ul>
  </div>
</template>
