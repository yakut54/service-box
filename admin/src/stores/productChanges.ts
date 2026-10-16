import { api } from '@/lib/api'
import { createPendingCountStore } from './createPendingCountStore'

/**
 * Счётчик НОВЫХ строк Журнала изменений с прошлого визита на страницу — тот
 * же паттерн, что useReviewsStore/useMailFailuresStore. Курсор на бэкенде —
 * shops.product_changes_last_seen_at (см. ProductChangeController::pendingCount).
 */
export const useProductChangesStore = createPendingCountStore('productChanges', () => api.getProductChangesPendingCount())
