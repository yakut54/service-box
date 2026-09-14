import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'

/**
 * Серые счётчики «всего» рядом с пунктами меню сайдбара (AppLayout.vue) —
 * не тревога, не звенит, не нужен «вырос ли счётчик» — просто фоновая
 * справочная цифра, обновляется по таймеру (см. NavCountsController).
 */
export const useNavCountsStore = defineStore('navCounts', () => {
  const counts = ref<Record<string, number>>({})

  async function fetch() {
    try { counts.value = await api.getNavCounts() }
    catch { /* тихо игнорируем — не критично для сайдбара */ }
  }

  function $reset() {
    counts.value = {}
  }

  return { counts, fetch, $reset }
})
