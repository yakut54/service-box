import { api } from '@/lib/api'
import { createPendingCountStore } from './createPendingCountStore'

/**
 * Счётчик НОВЫХ строк журнала изменений категорий с прошлого визита —
 * калька useProductChangesStore. Курсор на бэкенде — user_seen_cursors
 * (cursor_key='category_changes', см. CategoryChangeController::pendingCount).
 */
export const useCategoryChangesStore = createPendingCountStore('categoryChanges', () => api.getCategoryChangesPendingCount())
