export class WidgetApiError extends Error {
  constructor(public status: number, message: string) {
    super(message)
    this.name = 'WidgetApiError'
  }
}

export class WidgetApi {
  private baseUrl: string
  private shopId: string

  constructor(shopId: string, baseUrl: string) {
    this.shopId = shopId
    this.baseUrl = baseUrl
  }

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-Shop-ID': this.shopId,
      ...(options.headers as Record<string, string>),
    }

    const controller = new AbortController()
    const timeout = setTimeout(() => controller.abort(), 10000)

    try {
      const response = await fetch(`${this.baseUrl}${endpoint}`, {
        ...options,
        headers,
        signal: controller.signal,
      })

      if (!response.ok) {
        const body = await response.json().catch(() => ({ message: 'Request failed' }))
        throw new WidgetApiError(response.status, body.message || `HTTP ${response.status}`)
      }

      return response.json()
    } catch (e) {
      if (e instanceof WidgetApiError) throw e
      if ((e as Error).name === 'AbortError') {
        throw new WidgetApiError(0, 'Request timed out')
      }
      throw new WidgetApiError(0, (e as Error).message || 'Network error')
    } finally {
      clearTimeout(timeout)
    }
  }

  // Shop
  async getShop() {
    return this.request<{ id: string; name: string; widget_config: Record<string, any> | null }>('/widget/shop')
  }

  // Products
  async getProducts(params?: Record<string, string>) {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    return this.request<{ data: any[]; count: number }>(`/widget/products${query}`)
  }

  async getProduct(id: string) {
    return this.request<{ data: any }>(`/widget/products/${id}`)
  }

  // Orders
  async createOrder(data: Record<string, any>) {
    return this.request<{ data: any }>('/widget/orders', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async getOrder(id: string) {
    return this.request<{ data: any }>(`/widget/orders/${id}`)
  }

  // Bookings
  async getAvailableSlots(params: Record<string, string>) {
    const query = new URLSearchParams(params).toString()
    return this.request<any>(`/widget/bookings/available-slots?${query}`)
  }

  async createBooking(data: Record<string, any>) {
    return this.request<{ data: any }>('/widget/bookings', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  async getBooking(id: string) {
    return this.request<{ data: any }>(`/widget/bookings/${id}`)
  }

  // Phone verification (OTP)
  async requestPhoneCode(phone: string) {
    return this.request<{ message: string; masked_phone: string; expires_in: number; _dev_code?: string }>('/widget/phone/request-code', {
      method: 'POST',
      body: JSON.stringify({ phone }),
    })
  }

  async verifyPhoneCode(phone: string, code: string) {
    return this.request<{ message: string; token: string; expires_in: number }>('/widget/phone/verify', {
      method: 'POST',
      body: JSON.stringify({ phone, code }),
    })
  }

  // Customer history (by phone, requires verified token)
  async getOrdersByPhone(phone: string, token: string) {
    const query = new URLSearchParams({ phone }).toString()
    return this.request<{ data: any[] }>(`/widget/orders?${query}`, {
      headers: { 'X-Phone-Token': token },
    })
  }

  async getBookingsByPhone(phone: string, token: string) {
    const query = new URLSearchParams({ phone }).toString()
    return this.request<{ data: any[] }>(`/widget/bookings?${query}`, {
      headers: { 'X-Phone-Token': token },
    })
  }

  async cancelBooking(bookingId: string, phone: string, token: string) {
    const query = new URLSearchParams({ phone }).toString()
    return this.request<{ message: string; status: string }>(
      `/widget/bookings/${bookingId}/cancel?${query}`,
      { method: 'PATCH', headers: { 'X-Phone-Token': token } }
    )
  }

  // Reviews
  async getProductReviews(productId: string) {
    return this.request<{
      data: Array<{ id: string; customer_name: string; rating: number; text: string | null; created_at: string | null }>
      stats: { count: number; average: number | null; distribution: Record<number, number> }
    }>(`/widget/reviews/${productId}`)
  }

  async submitReview(data: {
    product_id: string
    rating: number
    text?: string
    customer_name: string
    customer_phone?: string
  }) {
    return this.request<{ message: string; data: any }>('/widget/reviews', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  }

  // Discounts
  async validateDiscount(code: string, cartAmount: number) {
    return this.request<{
      valid: boolean
      discount_id: string
      name: string
      type: string
      value: number
      discount_amount: number
    }>('/widget/discount/validate', {
      method: 'POST',
      body: JSON.stringify({ code, cart_amount: cartAmount }),
    })
  }
}
