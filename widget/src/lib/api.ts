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
}
