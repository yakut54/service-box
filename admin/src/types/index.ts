// ==========================================
// AUTH
// ==========================================

export interface User {
  id: string
  name: string
  email: string
  is_superadmin: boolean
  created_at: string
}

// ==========================================
// SUPERADMIN
// ==========================================

export interface SuperadminShop {
  id: string
  name: string
  domain: string | null
  plan: string | null
  subscription_ends_at: string | null
  created_at: string
  user?: { id: string; name: string; email: string; created_at: string } | null
}

export interface SuperadminRevenue {
  mrr_kopecks: number
  mrr_rubles: number
  total_shops: number
  new_shops_30d: number
  shops_by_plan: Record<string, number>
  recent_payments: Array<{
    id: string
    amount_kopecks: number
    status: string
    created_at: string
    shop_name: string
    plan: string
  }>
}

export interface SuperadminPricing {
  plan: string
  price_kopecks: number
  price_rubles: number
}

export interface WidgetConfig {
  primary_color?: string
  secondary_color?: string
  font_family?: string
  logo_url?: string | null
  border_radius?: number
  show_search?: boolean
  show_categories?: boolean
}

export interface LegalConfig {
  public_offer_text?: string | null
  privacy_policy_text?: string | null
  personal_data_consent_text?: string | null
  marketing_consent_text?: string | null
  requisites?: string | null
  contact_email?: string | null
  contact_phone?: string | null
  legal_updated_at?: string | null
}

export interface Shop {
  id: string
  name: string
  domain: string | null
  schema_name: string
  api_key?: string
  telegram_chat_id: string | null
  telegram_bot_connected: boolean
  payment_provider: string | null
  yookassa_shop_id: string | null
  robokassa_login: string | null
  subscription_plan: string | null
  subscription_expires_at: string | null
  widget_config: WidgetConfig | null
  legal_config: LegalConfig | null
  work_start: string | null
  work_end: string | null
  slot_duration: number | null
  timezone: string | null
  created_at: string
  updated_at: string
}

// ==========================================
// CATEGORIES
// ==========================================

export interface Category {
  id: string
  parent_id: string | null
  name: string
  slug: string
  description: string | null
  image_url: string | null
  is_visible: boolean
  sort_order: number
  products_count?: number
  children?: Category[]
  created_at: string
  updated_at: string
}

// ==========================================
// PRODUCTS
// ==========================================

export interface ProductPhysical {
  product_id: string
  sku: string | null
  stock_quantity: number
  allow_backorder: boolean
  weight_grams: number | null
  length_cm: number | null
  width_cm: number | null
  height_cm: number | null
  color: string | null
  brand: string | null
  material: string | null
  dimensions: string | null
}

export interface ProductDigital {
  product_id: string
  delivery_type: string | null
  access_days: number | null
  download_url: string | null
  file_size_bytes: number | null
  file_size_mb: number | null
  file_format: string | null
}

export interface ProductService {
  product_id: string
  duration_minutes: number | null
  max_concurrent: number | null
  requires_booking: boolean
  break_minutes: number | null
  requires_prepayment: boolean
}

export type ProductType = 'physical' | 'digital' | 'service'

export interface Product {
  id: string
  type: ProductType
  name: string
  description: string | null
  price: number
  compare_price: number | null
  currency: string
  image_url: string | null
  is_active: boolean
  category_id: string | null
  sort_order: number
  rating?: number | null
  category?: Category | null
  physical?: ProductPhysical | null
  digital?: ProductDigital | null
  service?: ProductService | null
  created_at: string
  updated_at: string
}

// ==========================================
// ORDERS
// ==========================================

export interface OrderItem {
  id: string
  order_id: string
  product_id: string | null
  quantity: number
  price: number
  product_name: string
  product_type: string
  product?: Product | null
}

export interface ShippingAddress {
  city: string
  street: string
  building: string
  apartment?: string | null
  postal_code?: string | null
}

export type OrderStatus = 'pending' | 'paid' | 'processing' | 'completed' | 'cancelled'

export interface Order {
  id: string
  customer_id: string | null
  status: OrderStatus
  total_price: number
  discount_id: string | null
  discount_code: string | null
  discount_amount: number
  payment_id: string | null
  payment_url: string | null
  customer_name: string
  customer_email: string | null
  customer_phone: string
  shipping_address: ShippingAddress | null
  notes: string | null
  paid_at: string | null
  items?: OrderItem[]
  created_at: string
  updated_at: string
}

export interface OrderStats {
  total_orders: number
  total_revenue: number
  pending_orders: number
  paid_orders: number
  processing_orders: number
  completed_orders: number
  cancelled_orders: number
  average_order_value: number
  prev_revenue?: number
  prev_orders?: number
}

export interface OrderChartPoint {
  date: string
  orders: number
  revenue: number
}

// ==========================================
// CUSTOMERS
// ==========================================

export interface Customer {
  id: string
  name: string
  email: string | null
  phone: string
  notes: string | null
  total_orders: number
  total_spent: number
  last_order_at: string | null
  created_at: string
  orders?: Order[]
  bookings?: Booking[]
}

// ==========================================
// MASTERS
// ==========================================

export interface Master {
  id: string
  name: string
  phone: string | null
  email: string | null
  specialization: string | null
  avatar_url: string | null
  is_active: boolean
  sort_order: number
  created_at: string
}

// ==========================================
// BOOKINGS
// ==========================================

export type BookingStatus = 'pending' | 'confirmed' | 'completed' | 'cancelled' | 'no_show'

export interface Booking {
  id: string
  service_id: string
  customer_id: string | null
  master_id: string | null
  start_time: string
  end_time: string
  status: BookingStatus
  payment_id: string | null
  customer_name: string
  customer_phone: string
  customer_email: string | null
  notes: string | null
  service_name?: string | null
  service?: Product | null
  customer?: Customer | null
  master?: Master | null
  created_at: string
  updated_at: string
}

export interface BookingStats {
  total_bookings: number
  pending_bookings: number
  confirmed_bookings: number
  completed_bookings: number
  cancelled_bookings: number
  no_show_bookings: number
}

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
// DISCOUNTS
// ==========================================

export type DiscountType = 'percent' | 'fixed'
export type DiscountScope = 'cart' | 'product' | 'category'

export interface Discount {
  id: string
  name: string
  type: DiscountType
  value: number
  code: string | null
  scope: DiscountScope
  scope_value: string | null
  min_order_amount: number
  max_discount_amount: number | null
  usage_limit: number | null
  usage_count: number
  per_user_limit: number
  is_active: boolean
  starts_at: string | null
  ends_at: string | null
  created_at: string | null
  updated_at: string
}

// ==========================================
// REVIEWS
// ==========================================

export interface Review {
  id: string
  product_id: string
  customer_id: string | null
  order_id: string | null
  customer_name: string
  rating: number
  text: string | null
  is_published: boolean
  created_at: string | null
}

// ==========================================
// SUBSCRIPTION
// ==========================================

export interface SubscriptionLimits {
  max_orders_per_month: number | null
  max_masters: number | null
}

export interface SubscriptionStatus {
  plan: string
  is_active: boolean
  is_expiring_soon: boolean
  days_until_expiration: number
  expires_at: string | null
  limits: SubscriptionLimits | null
}

export interface SubscriptionPayment {
  id: string
  plan: string
  amount: number
  status: string
  period_start: string | null
  period_end: string | null
  created_at: string
}

export interface SubscriptionInfo {
  id: string
  shop_id: string
  plan: string
  amount_kopecks: number
  payment_id: string | null
  payment_provider: string | null
  paid_at: string | null
  expires_at: string | null
  created_at: string
  updated_at: string
}

// ==========================================
// TELEGRAM
// ==========================================

export interface TelegramStatus {
  connected: boolean
  chat_id: string | null
  bot_username?: string | null
}

// ==========================================
// GENERIC
// ==========================================

export interface PaginatedResponse<T> {
  data: T[]
  count: number
}
