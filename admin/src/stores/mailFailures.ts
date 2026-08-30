import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'

/**
 * Счётчик несработавших писем с прошлого визита на вкладку «Уведомления» в
 * Настройках — источник для бейджа в меню (AppLayout.vue). Тот же паттерн, что
 * у useReviewsStore: курсор shops.mail_failures_last_seen_at на бэкенде, не
 * "сколько всего ошибок за всё время".
 */
export const useMailFailuresStore = defineStore('mailFailures', () => {
  const pendingCount = ref(0)

  async function fetchPendingCount() {
    try { pendingCount.value = (await api.getMailFailuresPendingCount()).count }
    catch { /* тихо игнорируем — не критично для бейджа */ }
  }

  return { pendingCount, fetchPendingCount }
})
