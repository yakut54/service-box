import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'

export const useBookingsStore = defineStore('bookings', () => {
  const bookings = ref<any[]>([])
  const masters = ref<any[]>([])
  const loading = ref(false)

  async function fetchBookings(params?: Record<string, string>) {
    loading.value = true
    try {
      const data = await api.getBookings(params)
      bookings.value = data.data
    } finally {
      loading.value = false
    }
  }

  async function fetchMasters() {
    try {
      const data = await api.getMasters()
      masters.value = data.data
    } catch {
      // ignore
    }
  }

  async function updateStatus(id: string, status: string) {
    const data = await api.updateBookingStatus(id, status)
    const idx = bookings.value.findIndex(b => b.id === id)
    if (idx !== -1) {
      bookings.value[idx] = data.data
    }
  }

  async function createBooking(payload: Record<string, any>) {
    const data = await api.createBooking(payload)
    bookings.value.unshift(data.data)
    return data.data
  }

  return {
    bookings,
    masters,
    loading,
    fetchBookings,
    fetchMasters,
    updateStatus,
    createBooking,
  }
})
