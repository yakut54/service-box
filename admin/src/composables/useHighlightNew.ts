import { ref } from 'vue'

/**
 * Подсветка строк, появившихся с прошлого визита (появляется на 4 сек и сама
 * плавно гаснет через CSS transition) — общий кусок, который раньше был
 * только в ReviewsView.vue, теперь нужен и обеим вкладкам ChangesView.vue
 * (ProductChangesTab/CategoryChangesTab). Логика завязана на серверный
 * курсор *_last_seen_at (см. ReviewController / ProductChangeController /
 * CategoryChangeController): считаем "новое" по значению ДО текущего захода,
 * а не по всему списку разом — иначе первый визит подсветил бы всё.
 */

const HIGHLIGHT_ACTIVE_CLASS = 'ring-2 ring-blue-400 bg-blue-50/60 dark:bg-blue-900/20'
const HIGHLIGHT_DURATION_MS = 4000

export function useHighlightNew<T extends { id: string; created_at?: string | null }>() {
  const newIds = ref<Set<string>>(new Set())

  /** Вызывать сразу после загрузки списка, с lastSeenAt ДО markSeen(). */
  function markNew(rows: T[], lastSeenAt: string | null) {
    const since = lastSeenAt ? new Date(lastSeenAt) : null
    newIds.value = since
      ? new Set(rows.filter(r => r.created_at && new Date(r.created_at) > since).map(r => r.id))
      : new Set()

    if (newIds.value.size > 0) {
      setTimeout(() => { newIds.value = new Set() }, HIGHLIGHT_DURATION_MS)
    }
  }

  function highlightClass(id: string): string {
    return newIds.value.has(id) ? HIGHLIGHT_ACTIVE_CLASS : ''
  }

  return { newIds, markNew, highlightClass }
}
