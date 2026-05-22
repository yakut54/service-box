import type {
  User, Shop,
  Category,
  Product,
  Order, OrderStats, OrderChartPoint,
  Customer,
  Booking, BookingStats, AvailableSlotsResponse,
  Master,
  Discount,
  Review,
  StaffMember,
  SubscriptionStatus,
  SubscriptionPayment,
  TelegramStatus,
  PaginatedResponse,
  SuperadminShop,
  SuperadminRevenue,
  SuperadminPricing,
} from '@/types'

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
    // Restore token from whichever storage it was saved to
    this.token = localStorage.getItem('auth_token') ?? sessionStorage.getItem('auth_token')
  }

  setToken(token: string | null, remember = true) {
    this.token = token
    if (token) {
      if (remember) {
        localStorage.setItem('auth_token', token)
        sessionStorage.removeItem('auth_token')
      } else {
        sessionStorage.setItem('auth_token', token)
        localStorage.removeItem('auth_token')
      }
    } else {
      localStorage.removeItem('auth_token')
      sessionStorage.removeItem('auth_token')
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
      const error = await response.json().catch(() => ({ message: 'Неизвестная ошибка' }))

      if (response.status === 401 && this.token) {
        this.setToken(null)
        this.unauthorizedHandler?.()
      }

      throw new ApiError(response.status, error.message || 'Ошибка запроса')
    }

    return response.json()
  }

  // ==========================================
  // UPLOAD
  // ==========================================

  async deleteImage(url: string): Promise<void> {
    await this.request('/admin/upload/image', {
      method: 'DELETE',
      body: JSON.stringify({ url }),
    })
  }

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

  private async download(endpoint: string, params?: Record<string, string>): Promise<void> {
    const url = new URL(`${API_BASE_URL}${endpoint}`, window.location.origin)
    if (params) Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))

    const response = await fetch(url.toString(), {
      headers: { ...(this.token ? { 'Authorization': `Bearer ${this.token}` } : {}) },
    })

    if (!response.ok) throw new ApiError(response.status, 'Ошибка экспорта')

    const blob = await response.blob()
    const disposition = response.headers.get('Content-Disposition') ?? ''
    const match = disposition.match(/filename="?([^"]+)"?/)
    const filename = match?.[1] ?? 'export.csv'

    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = filename
    link.click()
    URL.revokeObjectURL(link.href)
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
    return this.request<{ user: User; shop: Shop; token: string }>('/auth/register', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async login(data: { email: string; password: string }) {
    return this.request<{ user: User; shop: Shop; token: string }>('/auth/login', {
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
    return this.request<{ user: User; shop: Shop }>('/auth/me')
  }

  async refreshToken() {
    return this.request<{ token: string }>('/auth/refresh', { method: 'POST' })
  }

  async changePassword(currentPassword: string, password: string, passwordConfirmation: string) {
    return this.request<{ message: string }>('/auth/change-password', {
      method: 'POST',
      body: JSON.stringify({ current_password: currentPassword, password, password_confirmation: passwordConfirmation }),
    })
  }

  async forgotPassword(email: string) {
    return this.request<{ message: string }>('/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email }),
    })
  }

  async resetPassword(token: string, email: string, password: string, passwordConfirmation: string) {
    return this.request<{ message: string }>('/auth/reset-password', {
      method: 'POST',
      body: JSON.stringify({ token, email, password, password_confirmation: passwordConfirmation }),
    })
  }

  // ==========================================
  // SHOP
  // ==========================================

  async getShop() {
    return this.request<Shop>('/admin/shop')
  }

  async updateShop(data: Record<string, unknown>) {
    return this.request<Shop>('/admin/shop', {
      method: 'PUT',
      body: JSON.stringify(data),
    })
  }

  async regenerateApiKey() {
    return this.request<{ api_key: string }>('/admin/api-key/regenerate', { method: 'POST' })
  }

  // ==========================================
  // PRODUCTS
  // ==========================================

  async getProducts(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<PaginatedResponse<Product>>(`/admin/products${query}`)
  }

  async getProduct(id: string) {
    return this.request<{ data: Product }>(`/admin/products/${id}`)
  }

  async createProduct(data: Record<string, unknown>) {
    return this.request<{ message: string; data: Product }>('/admin/products', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateProduct(id: string, data: Record<string, unknown>) {
    return this.request<{ message: string; data: Product }>(`/admin/products/${id}`, {
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
    return this.request<PaginatedResponse<Order>>(`/admin/orders${query}`)
  }

  async getOrder(id: string) {
    return this.request<{ data: Order }>(`/admin/orders/${id}`)
  }

  async getOrderStats(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<OrderStats>(`/admin/orders/stats${query}`)
  }

  async getOrderChart(days = 30) {
    return this.request<{ data: OrderChartPoint[] }>(
      `/admin/orders/chart?days=${days}`
    )
  }

  async updateOrderStatus(id: string, status: string) {
    return this.request<{ message: string; data: Order }>(`/admin/orders/${id}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
    })
  }

  async exportOrders(params?: Record<string, string>) {
    return this.download('/admin/orders/export', params)
  }

  // ==========================================
  // CUSTOMERS
  // ==========================================

  async getCustomers(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<PaginatedResponse<Customer>>(`/admin/customers${query}`)
  }

  async getCustomer(id: string) {
    return this.request<{ data: Customer }>(`/admin/customers/${id}`)
  }

  async deleteCustomer(id: string) {
    return this.request<{ message: string }>(`/admin/customers/${id}`, {
      method: 'DELETE',
    })
  }

  async exportCustomers(params?: Record<string, string>) {
    return this.download('/admin/customers/export', params)
  }

  // ==========================================
  // BOOKINGS
  // ==========================================

  async getBookingStats() {
    return this.request<BookingStats>('/admin/bookings/stats')
  }

  async getBookings(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<PaginatedResponse<Booking>>(`/admin/bookings${query}`)
  }

  async getBooking(id: string) {
    return this.request<{ data: Booking }>(`/admin/bookings/${id}`)
  }

  async createBooking(data: Record<string, unknown>) {
    return this.request<{ message: string; data: Booking }>('/admin/bookings', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateBookingStatus(id: string, status: string) {
    return this.request<{ message: string; data: Booking }>(`/admin/bookings/${id}`, {
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
    return this.request<AvailableSlotsResponse>(`/admin/bookings/available-slots?${query}`)
  }

  async getMaster(id: string) {
    return this.request<{ data: Master }>(`/admin/masters/${id}`)
  }

  async getMasters(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<PaginatedResponse<Master>>(`/admin/masters${query}`)
  }

  async createMaster(data: Record<string, unknown>) {
    return this.request<{ data: Master }>('/admin/masters', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateMaster(id: string, data: Record<string, unknown>) {
    return this.request<{ data: Master }>(`/admin/masters/${id}`, {
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
    return this.request<{ data: Master[] }>('/admin/bookings/masters')
  }

  async getMasterServices(id: string) {
    return this.request<{ data: string[] }>(`/admin/masters/${id}/services`)
  }

  async updateMasterServices(id: string, serviceIds: string[]) {
    return this.request<{ data: string[] }>(`/admin/masters/${id}/services`, {
      method: 'PUT',
      body: JSON.stringify({ service_ids: serviceIds }),
    })
  }

  // ==========================================
  // DISCOUNTS
  // ==========================================

  async getDiscounts(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: Discount[] }>(`/admin/discounts${query}`)
  }

  async getDiscount(id: string) {
    return this.request<{ data: Discount }>(`/admin/discounts/${id}`)
  }

  async createDiscount(data: Record<string, unknown>) {
    return this.request<{ data: Discount }>('/admin/discounts', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateDiscount(id: string, data: Record<string, unknown>) {
    return this.request<{ data: Discount }>(`/admin/discounts/${id}`, {
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
    return this.request<SubscriptionStatus>('/admin/subscription')
  }

  async getSubscriptionPayments() {
    return this.request<SubscriptionPayment[]>('/admin/subscription/payments')
  }

  async createSubscriptionPayment(data: { plan: string; period_months?: number }) {
    return this.request<{ payment_url: string; payment_id: string }>('/admin/subscription/create-payment', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  // ==========================================
  // TELEGRAM
  // ==========================================

  async getTelegramStatus() {
    return this.request<TelegramStatus>('/admin/telegram/status')
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

  async getMaxStatus() {
    return this.request<{ connected: boolean; user_id: number | null }>('/admin/max/status')
  }

  async generateMaxCode() {
    return this.request<{ code: string; expires_in_minutes: number; bot_username: string | null }>(
      '/admin/max/generate-code',
      { method: 'POST' }
    )
  }

  async disconnectMax() {
    return this.request<{ message: string }>('/admin/max/disconnect', { method: 'POST' })
  }

  // ==========================================
  // DELIVERY SETTINGS
  // ==========================================

  async getDeliverySettings() {
    return this.request<{ data: Record<string, { enabled: boolean; price: number; address?: string; free_from?: number | null }> }>('/admin/delivery-settings')
  }

  async updateDeliverySettings(data: Record<string, unknown>) {
    return this.request<{ data: Record<string, unknown> }>('/admin/delivery-settings', {
      method: 'PUT',
      body: JSON.stringify(data),
    })
  }

  // ==========================================
  // CATEGORIES
  // ==========================================

  async getCategories() {
    return this.request<{ data: Category[] }>('/admin/categories')
  }

  async createCategory(data: Record<string, unknown>) {
    return this.request<{ data: Category }>('/admin/categories', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async updateCategory(id: string, data: Record<string, unknown>) {
    return this.request<{ data: Category }>(`/admin/categories/${id}`, {
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
    return this.request<PaginatedResponse<Review>>(`/admin/reviews${query}`)
  }

  async updateReview(id: string, data: { is_published: boolean }) {
    return this.request<{ data: Review }>(`/admin/reviews/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  }

  async deleteReview(id: string) {
    return this.request<{ message: string }>(`/admin/reviews/${id}`, {
      method: 'DELETE',
    })
  }

  // ==========================================
  // STAFF
  // ==========================================

  async getStaff() {
    return this.request<{ data: StaffMember[] }>('/admin/staff')
  }

  async inviteStaff(email: string) {
    return this.request<{ message: string }>('/admin/staff', {
      method: 'POST',
      body: JSON.stringify({ email }),
    })
  }

  async inviteMaster(masterId: string, email: string) {
    return this.request<{ message: string }>('/admin/staff', {
      method: 'POST',
      body: JSON.stringify({ email, role: 'master', master_id: masterId }),
    })
  }

  async revokeStaff(id: string) {
    return this.request<{ message: string }>(`/admin/staff/${id}`, {
      method: 'DELETE',
    })
  }

  async validateInvite(token: string) {
    return this.request<{ shop_name: string; email: string; requires_registration: boolean }>(`/invite/${token}`)
  }

  async acceptInvite(data: { token: string; name?: string; password?: string; password_confirmation?: string }) {
    return this.request<{ user: User; shop: Shop; token: string }>('/invite/accept', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  // ==========================================
  // SUPERADMIN
  // ==========================================

  async superadminGetShops(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: SuperadminShop[]; total: number; per_page: number; current_page: number }>(`/superadmin/shops${query}`)
  }

  async superadminGetShop(id: string) {
    return this.request<SuperadminShop>(`/superadmin/shops/${id}`)
  }

  async superadminUpdatePlan(id: string, data: { plan: string; subscription_ends_at?: string | null }) {
    return this.request<{ message: string; shop: SuperadminShop & { subscription_ends_at: string | null } }>(`/superadmin/shops/${id}/plan`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  }

  async superadminGetRevenue() {
    return this.request<SuperadminRevenue>('/superadmin/revenue')
  }

  async getPricing() {
    return this.request<Record<string, SuperadminPricing>>('/pricing')
  }

  async superadminGetPricing() {
    return this.request<Record<string, SuperadminPricing>>('/superadmin/pricing')
  }

  async superadminUpdatePricing(items: Array<{ plan: string; price_kopecks: number; max_orders_per_month: number | null; max_masters: number | null; features: string[] }>) {
    return this.request<{ message: string }>('/superadmin/pricing', {
      method: 'PUT',
      body: JSON.stringify(items),
    })
  }

  async getWidgetAnalytics(days: 7 | 30 | 90 = 30) {
    return this.request<{
      days: number
      funnel: Array<{ event: string; label: string; sessions: number; pct_top: number }>
    }>(`/admin/widget/analytics?days=${days}`)
  }
}

export const api = new ApiClient()
