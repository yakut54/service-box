// ==========================================
// AUTH
// ==========================================

export interface User {
  id: string
  name: string
  email: string
  is_superadmin: boolean
  role: 'owner' | 'admin' | 'master' | 'collector'
  created_at: string
}

// ==========================================
// COMMISSION
// ==========================================

export interface Commission {
  commission_percent: number
  min_order_amount_kopecks: number
  commission_total_kopecks: number
  commission_total_rubles: number
  commission_last_30d_kopecks: number
  commission_last_30d_rubles: number
  commission_month_kopecks: number
  commission_month_rubles: number
  recent_orders: Array<{
    id: string
    total_price: number
    commission_amount: number
    status: string
    created_at: string
  }>
}

// ==========================================
// SUPERADMIN
// ==========================================

export interface SuperadminShop {
  id: string
  name: string
  domain: string | null
  created_at: string
  user?: { id: string; name: string; email: string; created_at: string } | null
}

export interface SuperadminRevenue {
  commission_total_kopecks: number
  commission_total_rubles: number
  commission_last_30d_kopecks: number
  commission_last_30d_rubles: number
  total_shops: number
  new_shops_30d: number
  recent_orders: Array<{
    shop_name: string
    order_id: string
    commission_kopecks: number
    total_kopecks: number
    status: string
    created_at: string
  }>
}

export interface WidgetConfig {
  preset?: 'light' | 'dark' | 'minimal' | null
  primary_color?: string
  bg_color?: string | null
  page_bg_color?: string | null
  text_color?: string | null
  font_family?: 'system' | 'inter' | 'roboto' | 'montserrat' | 'georgia' | null
  sidebar_position?: 'left' | 'right' | null
  logo_url?: string | null
  logo_fit?: 'contain' | 'cover' | null
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
  max_chat_id: string | null
  max_bot_connected: boolean
  payment_provider: string | null
  yookassa_shop_id: string | null
  robokassa_login: string | null
  widget_config: WidgetConfig | null
  legal_config: LegalConfig | null
  work_start: string | null
  work_end: string | null
  slot_duration: number | null
  min_booking_notice: number | null
  timezone: string | null
  prepayment_enabled: boolean
  prepayment_amount: number
  hide_customer_phone: boolean
  chat_customer_delete_enabled: boolean
  delivery_settings: Record<string, unknown> | null
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
  sale_mode: 'piece' | 'weight_fixed' | 'weight_variable'
  weight_step_grams: number | null
  weight_min_grams: number | null
  weight_max_grams: number | null
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

export interface ProductImage {
  id: string
  product_id: string
  url: string
  sort_order: number
}

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
  images?: ProductImage[]
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
  weight_grams?: number | null
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
  delivery_method: string | null
  delivery_price: number
  notes: string | null
  paid_at: string | null
  items?: OrderItem[]
  customer?: Customer | null
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
// CHAT
// ==========================================

export interface ChatThread {
  id: string
  customer_id: string
  last_message_at: string | null
  last_message_preview: string | null
  unread_by_shop: number
  unread_by_customer: number
  shop_last_read_at: string | null
  customer_last_read_at: string | null
  is_blocked_by_shop: boolean
  created_at: string
  customer?: Pick<Customer, 'id' | 'name' | 'phone'> & {
    avatar_url?: string | null
    total_orders?: number
    total_spent?: number
  }
}

export interface ChatMessage {
  id: string
  thread_id: string
  sender_type: 'customer' | 'shop'
  sender_staff_id: string | null
  client_message_id: string
  body: string | null
  image_url: string | null
  status: 'sent' | 'read'
  created_at: string
  reply_to_message_id?: string | null
  reply_to?: ChatMessage | null
  edited_at?: string | null
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
  user_id: string | null
  salary_type: 'fixed' | 'percent'
  salary_rate: number
  created_at: string
  service_ids?: string[]
  services?: { id: string; name: string }[]
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
  priority: number
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
// STAFF
// ==========================================

export interface StaffMember {
  id: string
  role: 'admin' | 'master' | 'collector'
  master_id: string | null
  invite_email: string | null
  invite_name: string | null
  avatar_url: string | null
  phone: string | null
  last_login_at: string | null
  accepted_at: string | null
  invite_expires_at: string | null
  is_pending: boolean
  is_expired: boolean
  user: {
    id: string
    name: string
    email: string
    created_at: string
  } | null
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
