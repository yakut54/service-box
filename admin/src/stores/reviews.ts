import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'

/**
 * Счётчик НОВЫХ (появившихся с прошлого визита на страницу «Отзывы») и всё
 * ещё не опубликованных отзывов — общий источник для бейджа в меню
 * (AppLayout.vue) и баннера на дашборде (DashboardView.vue), чтобы не
 * дублировать один и тот же запрос в двух местах.
 *
 * Специально не "сколько не опубликовано" (это семантика бейджа заказов —
 * "сколько ещё не обработано") — если владелец магазина уже открывал
 * страницу и осознанно отложил публикацию, бейдж не должен бесконечно висеть
 * (баг найден 2026-08-25 живым тестом). См. ReviewController::pendingCount
 * на бэкенде — считает по shops.reviews_last_seen_at.
 */
export const useReviewsStore = defineStore('reviews', () => {
  const pendingCount = ref(0)

  async function fetchPendingCount() {
    try { pendingCount.value = (await api.getReviewsPendingCount()).count }
    catch { /* тихо игнорируем — не критично для бейджа/баннера */ }
  }

  return { pendingCount, fetchPendingCount }
})
