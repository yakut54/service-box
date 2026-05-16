// ==========================================
// WIDGET VIEWS
// ==========================================

export type WidgetView = 'loading' | 'error' | 'catalog' | 'product' | 'booking' | 'booking-success' | 'cart' | 'checkout' | 'success' | 'orders' | 'bookings-list'

// ==========================================
// SHOP / CONFIG
// ==========================================

export interface WidgetConfig {
  preset?: 'light' | 'dark' | 'minimal' | null
  primary_color?: string
  bg_color?: string | null
  font_family?: 'system' | 'inter' | 'roboto' | 'montserrat' | 'georgia' | null
  sidebar_position?: 'left' | 'right' | null
  logo_url?: string | null
  border_radius?: number
  show_price?: boolean
  show_duration?: boolean
  show_master_name?: boolean
  show_description?: boolean
  show_search?: boolean
  show_categories?: boolean
  white_label?: boolean
  custom_css?: string | null
}

export interface WidgetLegal {
  has_docs: boolean
  offer_url: string
  privacy_url: string
  personal_data_url: string
  contact_email: string | null
  contact_phone: string | null
}

export type DeliveryMethodKey = 'pickup' | 'courier' | 'postal'

export interface DeliveryMethod {
  enabled: boolean
  price: number
  address?: string | null
  free_from?: number | null
}

export type DeliverySettings = Partial<Record<DeliveryMethodKey, DeliveryMethod>>

export interface WidgetShop {
  id: string
  name: string
  widget_config: WidgetConfig | null
  timezone: string | null
  prepayment_enabled: boolean
  prepayment_amount: number
  legal: WidgetLegal | null
  delivery_settings: DeliverySettings | null
}

// ==========================================
// CATEGORIES
// ==========================================

export interface WidgetCategory {
  id: string
  parent_id: string | null
  name: string
  slug: string
  description: string | null
  image_url: string | null
  is_visible: boolean
  sort_order: number
  children?: WidgetCategory[]
}

// ==========================================
// PRODUCTS
// ==========================================

export interface WidgetProductPhysical {
  product_id: string
  sku: string | null
  stock_quantity: number
  allow_backorder: boolean
  brand?: string | null
  color?: string | null
  material?: string | null
  weight_grams?: number | null
  dimensions?: string | null
}

export interface WidgetProductDigital {
  product_id: string
  delivery_type: string | null
  access_days: number | null
  file_format: string | null
  file_size_mb?: number | null
}

export interface WidgetProductService {
  product_id: string
  duration_minutes: number | null
  max_concurrent: number | null
  requires_booking: boolean
  break_minutes: number | null
  requires_prepayment: boolean
}

export type WidgetProductType = 'physical' | 'digital' | 'service'

export interface WidgetProduct {
  id: string
  type: WidgetProductType
  name: string
  description: string | null
  price: number
  compare_price: number | null
  currency: string
  image_url: string | null
  is_active: boolean
  category_id: string | null
  sort_order: number
  category?: WidgetCategory | null
  physical?: WidgetProductPhysical | null
  digital?: WidgetProductDigital | null
  service?: WidgetProductService | null
  rating?: number | string | null
  review_count?: number | null
  created_at: string
  updated_at: string
}

// ==========================================
// ORDERS
// ==========================================

export interface WidgetOrderItem {
  id: string
  product_id: string | null
  quantity: number
  price: number
  product_name: string
  product_type: string
}

export interface WidgetShippingAddress {
  city: string
  street: string
  building: string
  apartment?: string | null
  postal_code?: string | null
}

export type WidgetOrderStatus = 'pending' | 'paid' | 'processing' | 'completed' | 'cancelled'

export interface WidgetOrder {
  id: string
  status: WidgetOrderStatus
  total_price: number
  discount_id: string | null
  discount_code: string | null
  discount_amount: number
  payment_url: string | null
  customer_name: string
  customer_email: string | null
  customer_phone: string
  shipping_address: WidgetShippingAddress | null
  notes: string | null
  paid_at: string | null
  items?: WidgetOrderItem[]
  created_at: string
  updated_at: string
}

// ==========================================
// BOOKINGS
// ==========================================

export type WidgetBookingStatus = 'pending' | 'confirmed' | 'completed' | 'cancelled' | 'no_show'

export interface WidgetBookingService {
  id: string
  name: string
  image_url?: string | null
}

export interface WidgetBookingMaster {
  id: string
  name: string
}

export interface WidgetBooking {
  id: string
  service_id: string
  master_id: string | null
  start_time: string
  end_time: string
  status: WidgetBookingStatus
  payment_id: string | null
  customer_name: string
  customer_phone: string
  customer_email: string | null
  notes: string | null
  service?: WidgetBookingService | null
  master?: WidgetBookingMaster | null
  created_at: string
  updated_at: string
  payment_url?:   string | null
  telegram_link?: string | null
  max_link?:      string | null
  max_code?:      string | null
}

// ==========================================
// BOOKING SLOTS
// ==========================================

export interface SlotMaster {
  id: string
  name: string
}

export interface BookingSlot {
  time: string
  datetime: string
  available: boolean
  masters: SlotMaster[]
}

export interface AvailableSlotsResponse {
  date: string
  service_id: string
  service_name: string
  duration_minutes: number
  slots: BookingSlot[]
}

// ==========================================
// REVIEWS
// ==========================================

export interface WidgetReview {
  id: string
  customer_name: string
  rating: number
  text: string | null
  created_at: string | null
}

export interface ReviewStats {
  count: number
  average: number | null
  distribution: Record<number, number>
}
