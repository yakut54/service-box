<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { api } from '@/lib/api'
import CustomSelect from '@/components/CustomSelect.vue'
import UiSpinner from '@/shared/ui/UiSpinner.vue'
import UiEmptyState from '@/shared/ui/UiEmptyState.vue'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import { formatDate } from '@/shared/lib/format'

interface Review {
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

// ── State ────────────────────────────────────────────────────
const reviews  = ref<Review[]>([])
const products = ref<{ id: string; name: string }[]>([])
const loading  = ref(false)
const error    = ref('')

// ── Filters ───────────────────────────────────────────────────
const filterStatus  = ref('all')
const filterRating  = ref('all')
const filterProduct = ref('all')

// ── Delete ────────────────────────────────────────────────────
const deleteTarget = ref<Review | null>(null)
const deleting     = ref(false)

// ── Filter options ────────────────────────────────────────────
const statusOptions = [
  { value: 'all',       label: 'Все статусы' },
  { value: 'pending',   label: 'На модерации' },
  { value: 'published', label: 'Опубликованные' },
]

const ratingOptions = [
  { value: 'all', label: 'Все оценки' },
  { value: '5',   label: '★★★★★ 5' },
  { value: '4',   label: '★★★★ 4' },
  { value: '3',   label: '★★★ 3' },
  { value: '2',   label: '★★ 2' },
  { value: '1',   label: '★ 1' },
]

const productOptions = computed(() => [
  { value: 'all', label: 'Все товары' },
  ...products.value.map(p => ({ value: p.id, label: p.name })),
])

// ── Filtered list ─────────────────────────────────────────────
const filtered = computed(() => {
  let list = reviews.value
  if (filterStatus.value === 'pending')   list = list.filter(r => !r.is_published)
  if (filterStatus.value === 'published') list = list.filter(r => r.is_published)
  if (filterRating.value !== 'all')       list = list.filter(r => r.rating === Number(filterRating.value))
  if (filterProduct.value !== 'all')      list = list.filter(r => r.product_id === filterProduct.value)
  return list
})

const pendingCount   = computed(() => reviews.value.filter(r => !r.is_published).length)
const publishedCount = computed(() => reviews.value.filter(r => r.is_published).length)

// ── Load ──────────────────────────────────────────────────────
async function load() {
  loading.value = true
  error.value   = ''
  try {
    const [revRes, prodRes] = await Promise.all([
      api.getReviews(),
      api.getProducts({ limit: '200' }),
    ])
    reviews.value  = revRes.data
    products.value = prodRes.data.map((p: any) => ({ id: p.id, name: p.name }))
  } catch (e: any) {
    error.value = e.message || 'Не удалось загрузить'
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ── Helpers ───────────────────────────────────────────────────
function productName(productId: string): string {
  return products.value.find(p => p.id === productId)?.name ?? '—'
}

// ── Toggle publish ────────────────────────────────────────────
async function togglePublish(r: Review) {
  try {
    const res = await api.updateReview(r.id, { is_published: !r.is_published })
    const idx = reviews.value.findIndex(x => x.id === r.id)
    if (idx !== -1) reviews.value[idx] = res.data
  } catch { /* silent */ }
}

// ── Delete ────────────────────────────────────────────────────
async function doDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await api.deleteReview(deleteTarget.value.id)
    reviews.value  = reviews.value.filter(r => r.id !== deleteTarget.value!.id)
    deleteTarget.value = null
  } catch (e: any) {
    error.value = e.message || 'Ошибка удаления'
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Отзывы</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          {{ reviews.length }} {{ reviews.length === 1 ? 'отзыв' : reviews.length < 5 ? 'отзыва' : 'отзывов' }},
          <span v-if="pendingCount > 0" class="text-amber-600 dark:text-amber-400 font-medium">{{ pendingCount }} ждут модерации</span>
          <span v-else>все проверены</span>
        </p>
      </div>
    </div>

    <!-- Stats row -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="card p-4">
        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Всего</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ reviews.length }}</p>
      </div>
      <div class="card p-4">
        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Опубликовано</p>
        <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ publishedCount }}</p>
      </div>
      <div class="card p-4">
        <p class="text-xs text-amber-600 dark:text-amber-400 uppercase tracking-wider mb-1">На модерации</p>
        <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ pendingCount }}</p>
      </div>
      <div class="card p-4">
        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Средняя оценка</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white">
          {{ reviews.length > 0 ? (reviews.reduce((s, r) => s + r.rating, 0) / reviews.length).toFixed(1) : '—' }}
        </p>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-col sm:flex-row gap-3">
      <CustomSelect v-model="filterStatus"  :options="statusOptions"  class="sm:w-52" />
      <CustomSelect v-model="filterRating"  :options="ratingOptions"  class="sm:w-44" />
      <CustomSelect v-model="filterProduct" :options="productOptions" class="flex-1 sm:max-w-xs" searchable />
      <button
        v-if="filterStatus !== 'all' || filterRating !== 'all' || filterProduct !== 'all'"
        @click="filterStatus = 'all'; filterRating = 'all'; filterProduct = 'all'"
        class="btn-ghost btn-sm whitespace-nowrap"
      >
        Сбросить
      </button>
    </div>

    <!-- Error -->
    <div v-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="card flex items-center justify-center py-16">
      <UiSpinner />
    </div>

    <!-- Empty (no reviews at all) -->
    <UiEmptyState v-else-if="reviews.length === 0" title="Нет отзывов" description="Клиенты ещё не оставили отзывы">
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
      </template>
    </UiEmptyState>

    <!-- Empty filter -->
    <div v-else-if="filtered.length === 0" class="card text-center py-10">
      <p class="text-gray-500 dark:text-gray-400">Нет отзывов по выбранным фильтрам</p>
      <button @click="filterStatus = 'all'; filterRating = 'all'; filterProduct = 'all'" class="btn-ghost btn-sm mt-3">Сбросить фильтры</button>
    </div>

    <!-- Reviews list -->
    <div v-else class="space-y-3">
      <div
        v-for="r in filtered"
        :key="r.id"
        :class="[
          'card p-4 transition-all',
          !r.is_published ? 'border-l-4 border-l-amber-400 dark:border-l-amber-500' : ''
        ]"
      >
        <div class="flex items-start gap-4">

          <!-- Avatar -->
          <div class="flex-shrink-0 w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center">
            <span class="text-sm font-semibold text-gray-600 dark:text-gray-300">
              {{ r.customer_name.charAt(0).toUpperCase() }}
            </span>
          </div>

          <!-- Content -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-medium text-gray-900 dark:text-white text-sm">{{ r.customer_name }}</span>

              <!-- Stars -->
              <span class="flex gap-0.5">
                <svg
                  v-for="i in 5" :key="i"
                  class="w-4 h-4"
                  :class="i <= r.rating ? 'text-amber-400' : 'text-gray-200 dark:text-gray-700'"
                  fill="currentColor" viewBox="0 0 20 20"
                >
                  <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                </svg>
              </span>

              <!-- Status badge -->
              <span
                :class="[
                  'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                  r.is_published
                    ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400'
                    : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400'
                ]"
              >
                {{ r.is_published ? 'Опубликован' : 'На модерации' }}
              </span>
            </div>

            <!-- Product -->
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
              {{ productName(r.product_id) }} · {{ formatDate(r.created_at) }}
            </p>

            <!-- Review text -->
            <p v-if="r.text" class="text-sm text-gray-700 dark:text-gray-300 mt-2 leading-relaxed">
              {{ r.text }}
            </p>
            <p v-else class="text-sm text-gray-400 italic mt-2">Без текста</p>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-2 flex-shrink-0">
            <!-- Publish toggle -->
            <button
              @click="togglePublish(r)"
              :class="[
                'btn-sm text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors border',
                r.is_published
                  ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-200 dark:hover:bg-gray-600'
                  : 'bg-emerald-500 hover:bg-emerald-600 text-white border-transparent'
              ]"
            >
              {{ r.is_published ? 'Скрыть' : 'Опубликовать' }}
            </button>

            <!-- Delete -->
            <button @click="deleteTarget = r" class="text-gray-400 hover:text-red-500 transition-colors p-1.5">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <UiConfirmDialog
      :modelValue="!!deleteTarget"
      @update:modelValue="if (!$event) deleteTarget = null"
      title="Удалить отзыв?"
      :loading="deleting"
      @confirm="doDelete"
    >
      Отзыв от <strong>{{ deleteTarget?.customer_name }}</strong> будет удалён без возможности восстановления.
    </UiConfirmDialog>

  </div>
</template>
