import { api } from '@/lib/api'
import { createPendingCountStore } from './createPendingCountStore'

/**
 * Счётчик несработавших писем с прошлого визита на вкладку «Уведомления» в
 * Настройках — источник для бейджа в меню (AppLayout.vue). Тот же паттерн, что
 * у useReviewsStore: курсор shops.mail_failures_last_seen_at на бэкенде, не
 * "сколько всего ошибок за всё время".
 */
export const useMailFailuresStore = createPendingCountStore('mailFailures', () => api.getMailFailuresPendingCount())
