import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Общий каркас для бейджа «новое с прошлого визита» (Отзывы, Несработавшие
 * письма, Журнал изменений — один и тот же приём: курсор shops.*_last_seen_at
 * на бэкенде, GET .../pending-count на фронте). Раньше каждый стор копировал
 * одинаковые 4 строки (pendingCount/fetchPendingCount/$reset) — вынесено сюда,
 * чтобы третья копия (Журнал изменений) не плодила код дальше.
 *
 * @param id       имя стора для Pinia (как раньше у defineStore)
 * @param fetchFn  вызов API, возвращающий { count: number } для этой фичи
 */
export function createPendingCountStore(id: string, fetchFn: () => Promise<{ count: number }>) {
  return defineStore(id, () => {
    const pendingCount = ref(0)

    /** Возвращает true, если счётчик вырос — сигнал для звука в AppLayout. */
    async function fetchPendingCount(): Promise<boolean> {
      try {
        const prev = pendingCount.value
        pendingCount.value = (await fetchFn()).count
        return pendingCount.value > prev
      } catch {
        return false
      }
    }

    function $reset() {
      pendingCount.value = 0
    }

    return { pendingCount, fetchPendingCount, $reset }
  })
}
