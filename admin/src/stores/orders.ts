import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/lib/api'

export const useOrdersStore = defineStore('orders', () => {
  const orders = ref<any[]>([])
  const stats = ref<any>({
    total_orders: 0,
    total_revenue: 0,
    pending_orders: 0,
    paid_orders: 0,
    completed_orders: 0,
    cancelled_orders: 0,
    average_order_value: 0,
  })
  const loading = ref(false)

  const pendingOrders = computed(() => orders.value.filter(o => o.status === 'pending'))
  const paidOrders = computed(() => orders.value.filter(o => o.status === 'paid'))

  async function fetchOrders(params?: Record<string, string>) {
    loading.value = true
    try {
      const data = await api.getOrders(params)
      orders.value = data.data
    } finally {
      loading.value = false
    }
  }

  async function fetchStats(params?: Record<string, string>) {
    try {
      stats.value = await api.getOrderStats(params)
    } catch {
      // ignore
    }
  }

  async function updateStatus(id: string, status: string) {
    const data = await api.updateOrderStatus(id, status)
    const idx = orders.value.findIndex(o => o.id === id)
    if (idx !== -1) {
      orders.value[idx] = data.data
    }
  }

  function $reset() {
    orders.value = []
    stats.value = {
      total_orders: 0,
      total_revenue: 0,
      pending_orders: 0,
      paid_orders: 0,
      completed_orders: 0,
      cancelled_orders: 0,
      average_order_value: 0,
    }
    loading.value = false
  }

  return {
    orders,
    stats,
    loading,
    pendingOrders,
    paidOrders,
    fetchOrders,
    fetchStats,
    updateStatus,
    $reset,
  }
})
