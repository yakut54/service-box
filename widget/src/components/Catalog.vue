<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useShopStore } from '@/stores/shop'
import { debounce } from '@/lib/utils'
import ProductCard from './ProductCard.vue'
import SbSelect from './SbSelect.vue'

const props = defineProps<{
  activeCategory?: string
}>()

const emit = defineEmits<{
  select: [product: any]
  'open-cart': []
  book: [product: any]
  'categories-loaded': [categories: Array<{ id: string; name: string }>]
}>()

const shopStore = useShopStore()
const products = ref<any[]>([])
const apiCategories = ref<Array<{ id: string; name: string }>>([])
const loading = ref(true)
const error = ref('')

const search = ref('')
const filterType = ref('')
const filterCategory = ref('')

// Категории: сначала с API, дополняем из продуктов (если API пустой или категория не вошла)
const categories = computed(() => {
  const seen = new Map<string, string>()
  // Приоритет — категории с сервера
  for (const c of apiCategories.value) {
    seen.set(c.id, c.name)
  }
  // Добавляем из продуктов (если вдруг не попали в API-список)
  for (const p of products.value) {
    if (p.category && p.category.id && p.category.name && !seen.has(p.category.id)) {
      seen.set(p.category.id, p.category.name)
    }
  }
  return Array.from(seen.entries())
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => a.name.localeCompare(b.name))
})

const filteredProducts = computed(() => {
  let result = products.value

  if (filterType.value) {
    result = result.filter(p => p.type === filterType.value)
  }

  if (filterCategory.value) {
    result = result.filter(p => p.category?.id === filterCategory.value)
  }

  if (search.value.trim()) {
    const q = search.value.trim().toLowerCase()
    result = result.filter(p =>
      p.name.toLowerCase().includes(q) ||
      (p.description && p.description.toLowerCase().includes(q))
    )
  }

  return result
})

const showSearch = computed(() => shopStore.config.show_search !== false)
const showCategories = computed(() => shopStore.config.show_categories !== false && categories.value.length > 0)

const typeFilters = [
  { value: '', label: 'Все' },
  { value: 'service', label: 'Услуги' },
  { value: 'physical', label: 'Товары' },
  { value: 'digital', label: 'Цифровые' },
]

// Only show type filters that have products
const activeTypeFilters = computed(() => {
  const types = new Set(products.value.map(p => p.type))
  return typeFilters.filter(f => f.value === '' || types.has(f.value))
})

// Show type select only when multiple types exist (>1 actual type)
const showTypeSelect = computed(() => activeTypeFilters.value.length > 2)

const typeSelectOptions = computed(() =>
  activeTypeFilters.value.map(f => ({
    value: f.value,
    label: f.value === '' ? 'Все типы' : f.label,
  }))
)

const categorySelectOptions = computed(() => [
  { value: '', label: 'Все категории' },
  ...categories.value.map(c => ({ value: c.id, label: c.name })),
])

const showFilters = computed(() => showTypeSelect.value || showCategories.value)

onMounted(async () => {
  try {
    const [productsResp, categoriesResp] = await Promise.allSettled([
      shopStore.getApi().getProducts({ active: 'true' }),
      shopStore.getApi().getCategories(),
    ])
    if (productsResp.status === 'fulfilled') {
      products.value = productsResp.value.data || []
    } else {
      error.value = (productsResp.reason as any)?.message || 'Failed to load products'
    }
    if (categoriesResp.status === 'fulfilled') {
      // Flatten: родители + дочерние
      const flat: Array<{ id: string; name: string }> = []
      for (const cat of categoriesResp.value.data || []) {
        flat.push({ id: cat.id, name: cat.name })
        for (const child of cat.children || []) {
          flat.push({ id: child.id, name: child.name })
        }
      }
      apiCategories.value = flat
    }
  } catch (e: any) {
    error.value = e.message || 'Failed to load products'
  }
  loading.value = false
})

// Watch activeCategory prop from sidebar
watch(() => props.activeCategory, (cat) => {
  if (cat !== undefined) {
    filterCategory.value = cat
  }
})

// Emit categories to parent (for sidebar) when products load
watch(categories, (cats) => {
  emit('categories-loaded', cats)
}, { immediate: true })

const onSearchInput = debounce(() => {
  // Local filtering — no API call needed
}, 300)

function handleSelect(product: any) {
  emit('select', product)
}
</script>

<template>
  <div class="sb-catalog sb-grid-container">
    <!-- Filters -->
    <div v-if="!loading && products.length > 0" class="sb-catalog-filters">
      <!-- Search -->
      <div v-if="showSearch" class="sb-search-wrap">
        <svg class="sb-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input
          v-model="search"
          @input="onSearchInput"
          type="text"
          class="sb-input sb-catalog-search"
          placeholder="Поиск..."
          aria-label="Поиск"
        />
      </div>

      <!-- Select dropdowns row -->
      <div v-if="showFilters" class="sb-catalog-selects">
        <SbSelect
          v-if="showTypeSelect"
          v-model="filterType"
          :options="typeSelectOptions"
        />
        <SbSelect
          v-if="showCategories"
          v-model="filterCategory"
          :options="categorySelectOptions"
          :searchable="true"
        />
      </div>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="sb-grid sb-grid-2 sb-grid-3">
      <div v-for="i in 6" :key="i" class="sb-pc-skeleton">
        <div class="sb-skeleton sb-skeleton-pc-img"></div>
        <div class="sb-pc-skeleton-body">
          <div class="sb-skeleton sb-skeleton-category"></div>
          <div class="sb-skeleton sb-skeleton-title"></div>
          <div class="sb-skeleton sb-skeleton-title" style="width: 70%;"></div>
          <div class="sb-skeleton sb-skeleton-price"></div>
        </div>
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="sb-empty">
      <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
      </svg>
      <p class="sb-empty-title">Ошибка загрузки</p>
      <p class="sb-empty-text">{{ error }}</p>
    </div>

    <!-- Products grid -->
    <div v-else-if="filteredProducts.length" class="sb-grid sb-grid-2 sb-grid-3">
      <ProductCard
        v-for="product in filteredProducts"
        :key="product.id"
        :product="product"
        @select="handleSelect"
        @open-cart="emit('open-cart')"
        @book="(p) => emit('book', p)"
      />
    </div>

    <!-- Empty: no products match filter -->
    <div v-else-if="products.length > 0" class="sb-empty">
      <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
      </svg>
      <p class="sb-empty-title">Ничего не найдено</p>
      <p class="sb-empty-text">Попробуйте изменить фильтры или поисковый запрос</p>
      <button class="sb-btn sb-btn-secondary sb-mt-4" @click="search = ''; filterType = ''; filterCategory = ''">
        Сбросить фильтры
      </button>
    </div>

    <!-- Empty: shop has no products -->
    <div v-else class="sb-empty">
      <svg class="sb-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
      </svg>
      <p class="sb-empty-title">Каталог пуст</p>
      <p class="sb-empty-text">Скоро здесь появятся товары и услуги</p>
    </div>
  </div>
</template>
