<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { formatPrice, plural } from '@/lib/utils'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import type { WidgetProduct } from '@/types'

const props = defineProps<{ product: WidgetProduct }>()
const emit = defineEmits<{
  back: []
  booking: [product: WidgetProduct]
  goToCart: []
}>()

const cartStore = useCartStore()
const shopStore = useShopStore()

const isService  = computed(() => props.product.type === 'service')
const isPhysical = computed(() => props.product.type === 'physical')
const isDigital  = computed(() => props.product.type === 'digital')

const maxStock = computed(() => {
  if (!isPhysical.value || !props.product.physical) return Infinity
  return props.product.physical.stock_quantity ?? 0
})

const isOutOfStock = computed(() => isPhysical.value && maxStock.value === 0)
const inCart       = computed(() => cartStore.hasItem(props.product.id))
const inCartQty    = computed(() => cartStore.getItemQuantity(props.product.id))

const badge = computed(() => {
  const p = props.product
  if (isService.value && p.service) return { cls: 'sb-pd-badge sb-pd-badge-info', text: `${p.service.duration_minutes} мин` }
  if (isPhysical.value && p.physical) {
    const stock = p.physical.stock_quantity ?? 0
    if (stock === 0) return { cls: 'sb-pd-badge sb-pd-badge-danger', text: 'Нет в наличии' }
    if (stock <= 5) return { cls: 'sb-pd-badge sb-pd-badge-warning', text: `Остал${stock === 1 ? 'ся' : 'ось'} ${stock} ${plural(stock, 'товар', 'товара', 'товаров')}` }
  }
  if (p.type === 'digital') return { cls: 'sb-pd-badge sb-pd-badge-info', text: 'Цифровой' }
  return null
})

const hasDiscount = computed(() =>
  props.product.compare_price != null && props.product.compare_price > props.product.price
)
const discountPercent = computed(() => {
  if (!hasDiscount.value || props.product.compare_price == null) return 0
  return Math.round((1 - props.product.price / props.product.compare_price) * 100)
})

function handleAddToCart() { cartStore.addItem(props.product) }
function handleIncrement()  { cartStore.updateQuantity(props.product.id, inCartQty.value + 1) }
function handleDecrement()  { cartStore.updateQuantity(props.product.id, inCartQty.value - 1) }

const deliveryTypeLabel: Record<string, string> = {
  download: 'Скачивание',
  link: 'Ссылка',
  code: 'Код доступа',
}

// ─── Reviews ─────────────────────────────────────────────────────────────────

interface ReviewItem {
  id: string
  customer_name: string
  rating: number
  text: string | null
  created_at: string | null
}

interface ReviewStats {
  count: number
  average: number | null
  distribution: Record<number, number>
}

const reviews      = ref<ReviewItem[]>([])
const reviewStats  = ref<ReviewStats>({ count: 0, average: null, distribution: {} })
const reviewsLoading = ref(false)

// Form
const formExpanded  = ref(false)
const formName      = ref('')
const formRating    = ref(0)
const formText      = ref('')
const hoverRating   = ref(0)
const formLoading   = ref(false)
const formSuccess   = ref(false)
const formError     = ref('')

async function loadReviews() {
  reviewsLoading.value = true
  try {
    const res = await shopStore.getApi().getProductReviews(props.product.id)
    reviews.value     = res.data
    reviewStats.value = res.stats
  } catch {
    // silent — отзывы не критичны
  } finally {
    reviewsLoading.value = false
  }
}

onMounted(loadReviews)
watch(() => props.product.id, loadReviews)

function setRating(r: number) { formRating.value = r }

async function submitReview() {
  if (!formName.value.trim() || formRating.value === 0) return
  formLoading.value = true
  formError.value   = ''
  try {
    await shopStore.getApi().submitReview({
      product_id:    props.product.id,
      rating:        formRating.value,
      text:          formText.value.trim() || undefined,
      customer_name: formName.value.trim(),
    })
    formSuccess.value  = true
    formExpanded.value = false
    formName.value     = ''
    formRating.value   = 0
    formText.value     = ''
  } catch (e: unknown) {
    formError.value = e instanceof Error ? e.message : 'Ошибка отправки'
  } finally {
    formLoading.value = false
  }
}

function formatReviewDate(s: string | null): string {
  if (!s) return ''
  return new Date(s).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

const displayRating  = computed(() => {
  const r = props.product.rating ?? reviewStats.value.average
  return r != null ? parseFloat(String(r)) : null
})
const displayCount   = computed(() => props.product.review_count ?? reviewStats.value.count)
</script>

<template>
  <div class="sb-pd sb-grid-container">
    <!-- Back -->
    <button class="sb-pd-back sb-btn sb-btn-ghost" @click="emit('back')">
      <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      Назад
    </button>

    <!-- 2-col layout -->
    <div class="sb-pd-layout">

      <!-- Left: image -->
      <div class="sb-pd-col-img">
        <div class="sb-pd-img-wrap">
          <img
            v-if="product.image_url"
            :src="product.image_url"
            :alt="product.name"
            class="sb-pd-img"
          />
          <div v-else class="sb-pd-img-placeholder">
            <svg width="56" height="56" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          </div>
          <span v-if="badge" :class="badge.cls">{{ badge.text }}</span>
          <span v-if="hasDiscount && !isOutOfStock" class="sb-pd-discount">−{{ discountPercent }}%</span>
        </div>
      </div>

      <!-- Right: info + actions -->
      <div class="sb-pd-col-info">
        <p v-if="product.category?.name" class="sb-pd-category">{{ product.category.name }}</p>
        <h2 class="sb-pd-name">{{ product.name }}</h2>

        <!-- Rating -->
        <div v-if="displayRating" class="sb-pd-rating">
          <span class="sb-pd-stars" aria-hidden="true">
            <svg v-for="i in Math.floor(displayRating)" :key="'f'+i" class="sb-pd-star sb-pd-star-full" viewBox="0 0 20 20">
              <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" />
            </svg>
            <svg v-for="i in (5 - Math.floor(displayRating))" :key="'e'+i" class="sb-pd-star sb-pd-star-empty" viewBox="0 0 20 20">
              <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" />
            </svg>
          </span>
          <span class="sb-pd-rating-val">{{ Number(displayRating).toFixed(1) }}</span>
          <span v-if="displayCount" class="sb-pd-rating-count">({{ displayCount }} {{ plural(displayCount, 'отзыв', 'отзыва', 'отзывов') }})</span>
        </div>

        <!-- Price -->
        <div class="sb-pd-price-row">
          <span class="sb-pd-price">{{ formatPrice(product.price) }}</span>
          <span v-if="hasDiscount" class="sb-pd-old-price">{{ formatPrice(product.compare_price!) }}</span>
        </div>

        <!-- Description -->
        <p v-if="product.description" class="sb-pd-description">{{ product.description }}</p>

        <!-- Meta -->
        <div v-if="isService && product.service" class="sb-pd-meta">
          <div class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Длительность</span>
            <span>{{ product.service.duration_minutes }} мин</span>
          </div>
          <div v-if="(product.service.break_minutes ?? 0) > 0" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Перерыв</span>
            <span>{{ product.service.break_minutes }} мин</span>
          </div>
          <div v-if="(product.service.max_concurrent ?? 0) > 1" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Мест одновременно</span>
            <span>{{ product.service.max_concurrent }}</span>
          </div>
          <div v-if="product.service.requires_prepayment" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Предоплата</span>
            <span>Требуется</span>
          </div>
        </div>
        <div v-if="isDigital && product.digital" class="sb-pd-meta">
          <div v-if="product.digital.delivery_type" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Доставка</span>
            <span>{{ deliveryTypeLabel[product.digital.delivery_type] ?? product.digital.delivery_type }}</span>
          </div>
          <div v-if="product.digital.file_format" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Формат</span>
            <span>{{ product.digital.file_format }}</span>
          </div>
          <div v-if="product.digital.file_size_mb" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Размер файла</span>
            <span>{{ product.digital.file_size_mb }} МБ</span>
          </div>
          <div v-if="product.digital.access_days" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Срок доступа</span>
            <span>{{ product.digital.access_days }} {{ plural(product.digital.access_days, 'день', 'дня', 'дней') }}</span>
          </div>
        </div>
        <div v-if="isPhysical && product.physical" class="sb-pd-meta">
          <div v-if="product.physical.sku" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Артикул</span>
            <span>{{ product.physical.sku }}</span>
          </div>
          <div v-if="product.physical.brand" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Бренд</span>
            <span>{{ product.physical.brand }}</span>
          </div>
          <div v-if="product.physical.color" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Цвет</span>
            <span>{{ product.physical.color }}</span>
          </div>
          <div v-if="product.physical.material" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Материал</span>
            <span>{{ product.physical.material }}</span>
          </div>
          <div v-if="product.physical.weight_grams" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Вес</span>
            <span>{{ product.physical.weight_grams }} г</span>
          </div>
          <div v-if="product.physical.dimensions" class="sb-pd-meta-row">
            <span class="sb-pd-meta-label">Размер</span>
            <span>{{ product.physical.dimensions }}</span>
          </div>
        </div>

        <!-- Actions -->
        <div class="sb-pd-actions">
          <template v-if="isService">
            <button class="sb-btn sb-btn-primary sb-btn-block" @click="emit('booking', product)">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              Записаться
            </button>
          </template>
          <template v-else-if="inCart">
            <div v-if="isPhysical" class="sb-pd-qty-row">
              <div class="sb-quantity">
                <button class="sb-quantity-btn" @click="handleDecrement">−</button>
                <span class="sb-quantity-value">{{ inCartQty }}</span>
                <button class="sb-quantity-btn" :disabled="inCartQty >= maxStock" @click="handleIncrement">+</button>
              </div>
              <span class="sb-pd-qty-total">{{ formatPrice(product.price * inCartQty) }}</span>
            </div>
            <button class="sb-btn sb-btn-primary sb-btn-block" @click="emit('goToCart')">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
              </svg>
              Перейти в корзину
            </button>
          </template>
          <template v-else>
            <button class="sb-btn sb-btn-primary sb-btn-block" :disabled="isOutOfStock" @click="handleAddToCart">
              {{ isOutOfStock ? 'Нет в наличии' : 'В корзину' }}
            </button>
          </template>
        </div>
      </div>
    </div>

    <!-- ─── Reviews section ──────────────────────────────────── -->
    <div class="sb-reviews">

      <!-- Header -->
      <div class="sb-reviews-header">
        <h3 class="sb-reviews-title">
          Отзывы
          <span v-if="reviewStats.count > 0" class="sb-reviews-count">{{ reviewStats.count }}</span>
        </h3>
        <button
          v-if="!formExpanded && !formSuccess"
          class="sb-btn sb-btn-secondary sb-btn-sm"
          @click="formExpanded = true"
        >
          Оставить отзыв
        </button>
      </div>

      <!-- Average rating bar -->
      <div v-if="reviewStats.average" class="sb-reviews-avg">
        <span class="sb-reviews-avg-val">{{ reviewStats.average.toFixed(1) }}</span>
        <div class="sb-reviews-avg-stars">
          <svg
            v-for="i in 5" :key="i"
            class="sb-reviews-avg-star"
            :class="i <= Math.round(reviewStats.average!) ? 'sb-star-full' : 'sb-star-empty'"
            viewBox="0 0 20 20"
          >
            <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" />
          </svg>
        </div>
        <span class="sb-reviews-avg-label">
          {{ reviewStats.count }} {{ plural(reviewStats.count, 'отзыв', 'отзыва', 'отзывов') }}
        </span>
      </div>

      <!-- Thank you message after submit -->
      <div v-if="formSuccess" class="sb-review-success">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
        </svg>
        Спасибо! Отзыв отправлен на модерацию.
      </div>

      <!-- Review form -->
      <div v-if="formExpanded" class="sb-review-form">
        <!-- Star rating picker -->
        <div class="sb-review-form-stars-label">Ваша оценка <span class="sb-review-required">*</span></div>
        <div class="sb-review-form-stars">
          <button
            v-for="i in 5" :key="i"
            type="button"
            class="sb-review-star-btn"
            @mouseenter="hoverRating = i"
            @mouseleave="hoverRating = 0"
            @click="setRating(i)"
            :aria-label="`${i} звезд`"
          >
            <svg
              class="sb-review-star-ico"
              :class="i <= (hoverRating || formRating) ? 'sb-star-full' : 'sb-star-empty'"
              viewBox="0 0 20 20"
            >
              <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" />
            </svg>
          </button>
        </div>

        <!-- Name -->
        <div class="sb-review-form-field">
          <label class="sb-review-form-label">Ваше имя <span class="sb-review-required">*</span></label>
          <input
            v-model="formName"
            type="text"
            class="sb-review-input"
            placeholder="Иван"
            maxlength="100"
          />
        </div>

        <!-- Text -->
        <div class="sb-review-form-field">
          <label class="sb-review-form-label">Отзыв</label>
          <textarea
            v-model="formText"
            class="sb-review-textarea"
            placeholder="Поделитесь впечатлениями..."
            rows="3"
            maxlength="2000"
          />
        </div>

        <p v-if="formError" class="sb-review-form-error">{{ formError }}</p>

        <div class="sb-review-form-actions">
          <button class="sb-btn sb-btn-ghost" @click="formExpanded = false; formError = ''">Отмена</button>
          <button
            class="sb-btn sb-btn-primary"
            :disabled="formLoading || !formName.trim() || formRating === 0"
            @click="submitReview"
          >
            {{ formLoading ? 'Отправка...' : 'Отправить' }}
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="reviewsLoading" class="sb-reviews-loading">
        <div class="sb-spinner"></div>
      </div>

      <!-- Empty -->
      <p v-else-if="!reviewsLoading && reviews.length === 0 && !formExpanded" class="sb-reviews-empty">
        Отзывов пока нет. Будьте первым!
      </p>

      <!-- Reviews list -->
      <div v-else class="sb-review-list">
        <div v-for="r in reviews" :key="r.id" class="sb-review-item">
          <div class="sb-review-avatar">
            {{ r.customer_name.charAt(0).toUpperCase() }}
          </div>
          <div class="sb-review-body">
            <div class="sb-review-meta">
              <span class="sb-review-name">{{ r.customer_name }}</span>
              <div class="sb-review-stars">
                <svg
                  v-for="i in 5" :key="i"
                  class="sb-review-star"
                  :class="i <= r.rating ? 'sb-star-full' : 'sb-star-empty'"
                  viewBox="0 0 20 20"
                >
                  <polygon points="10,1 12.9,7 19.5,7.6 14.5,12 16.2,18.5 10,15 3.8,18.5 5.5,12 0.5,7.6 7.1,7" />
                </svg>
              </div>
              <span class="sb-review-date">{{ formatReviewDate(r.created_at) }}</span>
            </div>
            <p v-if="r.text" class="sb-review-text">{{ r.text }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Mobile sticky footer -->
    <div class="sb-pd-footer">
      <div class="sb-pd-footer-price">
        <span class="sb-pd-footer-amount">{{ formatPrice(inCart ? product.price * inCartQty : product.price) }}</span>
        <span v-if="hasDiscount && !inCart" class="sb-pd-footer-old">{{ formatPrice(product.compare_price!) }}</span>
      </div>
      <template v-if="isService">
        <button class="sb-btn sb-btn-primary" @click="emit('booking', product)">Записаться</button>
      </template>
      <template v-else-if="inCart">
        <div v-if="isPhysical" class="sb-quantity sb-quantity-sm">
          <button class="sb-quantity-btn" @click="handleDecrement">−</button>
          <span class="sb-quantity-value">{{ inCartQty }}</span>
          <button class="sb-quantity-btn" :disabled="inCartQty >= maxStock" @click="handleIncrement">+</button>
        </div>
        <button class="sb-btn sb-btn-primary" @click="emit('goToCart')">В корзину</button>
      </template>
      <template v-else>
        <button class="sb-btn sb-btn-primary" :disabled="isOutOfStock" @click="handleAddToCart">
          {{ isOutOfStock ? 'Нет в наличии' : 'В корзину' }}
        </button>
      </template>
    </div>
  </div>
</template>
