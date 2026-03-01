const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api'

export class ApiError extends Error {
  constructor(public status: number, message: string) {
    super(message)
    this.name = 'ApiError'
  }
}

class ApiClient {
  private token: string | null = null
  private unauthorizedHandler: (() => void) | null = null

  constructor() {
    this.token = localStorage.getItem('auth_token')
  }

  setToken(token: string | null) {
    this.token = token
    if (token) {
      localStorage.setItem('auth_token', token)
    } else {
      localStorage.removeItem('auth_token')
    }
  }

  getToken(): string | null {
    return this.token
  }

  setUnauthorizedHandler(handler: () => void) {
    this.unauthorizedHandler = handler
  }

  private async request<T>(
    endpoint: string,
    options: RequestInit = {}
  ): Promise<T> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...(options.headers as Record<string, string>),
    }

    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`
    }

    const response = await fetch(`${API_BASE_URL}${endpoint}`, {
      ...options,
      headers,
    })

    if (!response.ok) {
      const error = await response.json().catch(() => ({ message: 'Unknown error' }))

      if (response.status === 401 && this.token) {
        this.setToken(null)
        this.unauthorizedHandler?.()
      }

      throw new ApiError(response.status, error.message || 'Request failed')
    }

    return response.json()
  }

  // ==========================================
  // UPLOAD
  // ==========================================

  async uploadImage(file: File): Promise<{ url: string }> {
    const formData = new FormData()
    formData.append('image', file)
    const response = await fetch(`${API_BASE_URL}/admin/upload/image`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        ...(this.token ? { 'Authorization': `Bearer ${this.token}` } : {}),
      },
      body: formData,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new ApiError(response.status, err.message || 'Ошибка загрузки')
    }
    return response.json()
  }

  // ==========================================
  // AUTH
  // ==========================================

  async register(data: {
    name: string
    email: string
    password: string
    password_confirmation: string
    shop_name: string
    timezone: string
  }) {
    return this.request<{ user: any; shop: any; token: string }>('/auth/register', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async login(data: { email: string; password: string }) {
    return this.request<{ user: any; shop: any; token: string }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async logout() {
    try {
      await this.request('/auth/logout', { method: 'POST' })
    } finally {
      this.setToken(null)
    }
  }

  async me() {
    return this.request<{ user: any; shop: any }>('/auth/me')
  }

  async refreshToken() {
    return this.request<{ token: string }>('/auth/refresh', { method: 'POST' })
  }

  // ==========================================
  // SHOP
  // ==========================================

  async getShop() {
    return this.request<any>('/admin/shop')
  }

  async updateShop(data: Record<string, any>) {
    return this.request<any>('/admin/shop', {
      method: 'PUT',
      body: JSON.stringify(data),
    })
  }

  // ==========================================
  // PRODUCTS
  // ==========================================

  async getProducts(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: any[]; count: number }>(`/admin/products${query}`)
  }

  async getProduct(id: string) {
    return this.request<{ data: any }>(`/admin/products/${id}`)
  }

  async createProduct(data: Record<string, any>) {
    return this.request<{ message: string; data: any }>('/admin/products', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateProduct(id: string, data: Record<string, any>) {
    return this.request<{ message: string; data: any }>(`/admin/products/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    })
  }

  async deleteProduct(id: string) {
    return this.request<{ message: string }>(`/admin/products/${id}`, {
      method: 'DELETE',
    })
  }

  // ==========================================
  // ORDERS
  // ==========================================

  async getOrders(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: any[]; count: number }>(`/admin/orders${query}`)
  }

  async getOrder(id: string) {
    return this.request<{ data: any }>(`/admin/orders/${id}`)
  }

  async getOrderStats(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<any>(`/admin/orders/stats${query}`)
  }

  async getOrderChart(days = 30) {
    return this.request<{ data: Array<{ date: string; orders: number; revenue: number }> }>(
      `/admin/orders/chart?days=${days}`
    )
  }

  async updateOrderStatus(id: string, status: string) {
    return this.request<{ message: string; data: any }>(`/admin/orders/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
    })
  }

  // ==========================================
  // CUSTOMERS
  // ==========================================

  async getCustomers(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: any[]; count: number }>(`/admin/customers${query}`)
  }

  async getCustomer(id: string) {
    return this.request<{ data: any }>(`/admin/customers/${id}`)
  }

  async deleteCustomer(id: string) {
    return this.request<{ message: string }>(`/admin/customers/${id}`, {
      method: 'DELETE',
    })
  }

  // ==========================================
  // BOOKINGS
  // ==========================================

  async getBookingStats() {
    return this.request<{
      total_bookings: number
      pending_bookings: number
      confirmed_bookings: number
      completed_bookings: number
      cancelled_bookings: number
      no_show_bookings: number
    }>('/admin/bookings/stats')
  }

  async getBookings(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: any[]; count: number }>(`/admin/bookings${query}`)
  }

  async getBooking(id: string) {
    return this.request<{ data: any }>(`/admin/bookings/${id}`)
  }

  async createBooking(data: Record<string, any>) {
    return this.request<{ message: string; data: any }>('/admin/bookings', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateBookingStatus(id: string, status: string) {
    return this.request<{ message: string; data: any }>(`/admin/bookings/${id}`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
    })
  }

  async deleteBooking(id: string) {
    return this.request<{ message: string }>(`/admin/bookings/${id}`, { method: 'DELETE' })
  }

  async deleteOrder(id: string) {
    return this.request<{ message: string }>(`/admin/orders/${id}`, { method: 'DELETE' })
  }

  async getAvailableSlots(params: Record<string, string>) {
    const query = new URLSearchParams(params).toString()
    return this.request<any>(`/admin/bookings/available-slots?${query}`)
  }

  async getMaster(id: string) {
    return this.request<{ data: any }>(`/admin/masters/${id}`)
  }

  async getMasters(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: any[]; count: number }>(`/admin/masters${query}`)
  }

  async createMaster(data: Record<string, any>) {
    return this.request<{ data: any }>('/admin/masters', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateMaster(id: string, data: Record<string, any>) {
    return this.request<{ data: any }>(`/admin/masters/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    })
  }

  async deleteMaster(id: string) {
    return this.request<{ message: string }>(`/admin/masters/${id}`, {
      method: 'DELETE',
    })
  }

  // legacy: used by BookingsView for slot-master dropdown
  async getBookingMasters() {
    return this.request<{ data: any[] }>('/admin/bookings/masters')
  }

  // ==========================================
  // DISCOUNTS
  // ==========================================

  async getDiscounts(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: any[] }>(`/admin/discounts${query}`)
  }

  async getDiscount(id: string) {
    return this.request<{ data: any }>(`/admin/discounts/${id}`)
  }

  async createDiscount(data: Record<string, any>) {
    return this.request<{ data: any }>('/admin/discounts', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateDiscount(id: string, data: Record<string, any>) {
    return this.request<{ data: any }>(`/admin/discounts/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  }

  async deleteDiscount(id: string) {
    return this.request<{ message: string }>(`/admin/discounts/${id}`, {
      method: 'DELETE',
    })
  }

  // ==========================================
  // SUBSCRIPTION
  // ==========================================

  async getSubscription() {
    return this.request<any>('/admin/subscription')
  }

  async getSubscriptionPayments() {
    return this.request<any[]>('/admin/subscription/payments')
  }

  async createSubscriptionPayment(data: { plan: string; period_months?: number }) {
    return this.request<any>('/admin/subscription/create-payment', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  // ==========================================
  // TELEGRAM
  // ==========================================

  async getTelegramStatus() {
    return this.request<any>('/admin/telegram/status')
  }

  async generateTelegramCode() {
    return this.request<{ code: string; expires_in_minutes: number; bot_username: string }>(
      '/admin/telegram/generate-code',
      { method: 'POST' }
    )
  }

  async disconnectTelegram() {
    return this.request<{ message: string }>('/admin/telegram/disconnect', {
      method: 'POST',
    })
  }

  // ==========================================
  // CATEGORIES
  // ==========================================

  async getCategories() {
    return this.request<{ data: any[] }>('/admin/categories')
  }

  async createCategory(data: Record<string, any>) {
    return this.request<{ data: any }>('/admin/categories', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateCategory(id: string, data: Record<string, any>) {
    return this.request<{ data: any }>(`/admin/categories/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  }

  async deleteCategory(id: string, params: Record<string, string>) {
    const query = new URLSearchParams(params).toString()
    return this.request<{ message: string }>(`/admin/categories/${id}?${query}`, {
      method: 'DELETE',
    })
  }

  async reorderCategories(items: Array<{ id: string; sort_order: number }>) {
    return this.request<{ message: string }>('/admin/categories/reorder', {
      method: 'PATCH',
      body: JSON.stringify({ items }),
    })
  }

  // ==========================================
  // REVIEWS
  // ==========================================

  async getReviews(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: any[]; count: number }>(`/admin/reviews${query}`)
  }

  async updateReview(id: string, data: { is_published: boolean }) {
    return this.request<{ data: any }>(`/admin/reviews/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  }

  async deleteReview(id: string) {
    return this.request<{ message: string }>(`/admin/reviews/${id}`, {
      method: 'DELETE',
    })
  }
}

export const api = new ApiClient()
