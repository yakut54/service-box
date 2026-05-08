import { useShopStore } from '@/stores/shop'

export type AnalyticsEvent =
  | 'widget_opened'
  | 'catalog_viewed'
  | 'service_selected'
  | 'booking_started'
  | 'booking_step_phone'
  | 'booking_completed'
  | 'booking_abandoned'

export function useAnalytics() {
  const shopStore = useShopStore()

  function track(event: AnalyticsEvent, productId?: string | null) {
    if (!shopStore.sessionId || !shopStore.shopId) return
    // Fire-and-forget: silent failures, non-blocking
    fetch(`${shopStore.apiUrl}/widget/analytics`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Shop-ID': shopStore.shopId,
      },
      body: JSON.stringify({
        event,
        session_id: shopStore.sessionId,
        product_id: productId ?? null,
      }),
    }).catch(() => {})
  }

  return { track }
}
