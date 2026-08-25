import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'

/**
 * Счётчик отзывов на модерации — общий источник для бейджа в меню
 * (AppLayout.vue) и баннера на дашборде (DashboardView.vue), чтобы не
 * дублировать один и тот же запрос в двух местах.
 */
export const useReviewsStore = defineStore('reviews', () => {
  const pendingCount = ref(0)

  async function fetchPendingCount() {
    try { pendingCount.value = (await api.getReviews({ is_published: 'false' })).count }
    catch { /* тихо игнорируем — не критично для бейджа/баннера */ }
  }

  return { pendingCount, fetchPendingCount }
})
