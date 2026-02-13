<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useShopStore } from '@/stores/shop'
import { debounce } from '@/lib/utils'
import ProductCard from './ProductCard.vue'

const emit = defineEmits<{ select: [product: any] }>()

const shopStore = useShopStore()
const products = ref<any[]>([])
const loading = ref(true)
const error = ref('')

const search = ref('')
const filterType = ref('')
const filterCategory = ref('')

const categories = computed(() => {
  const cats = new Set<string>()
  for (const p of products.value) {
    if (p.category) cats.add(p.category)
  }
  return Array.from(cats).sort()
})

const filteredProducts = computed(() => {
  let result = products.value

  if (filterType.value) {
    result = result.filter(p => p.type === filterType.value)
  }

  if (filterCategory.value) {
    result = result.filter(p => p.category === filterCategory.value)
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

onMounted(async () => {
  try {
    const resp = await shopStore.getApi().getProducts()
    products.value = resp.data || []
  } catch (e: any) {
    error.value = e.message || 'Failed to load products'
  }
  loading.value = false
})

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
      <input
        v-if="showSearch"
        v-model="search"
        @input="onSearchInput"
        type="text"
        class="sb-input sb-catalog-search"
        placeholder="Поиск товаров и услуг..."
        aria-label="Поиск"
      />

      <div class="sb-filter-row">
        <!-- Type filter chips -->
        <div v-if="activeTypeFilters.length > 2" class="sb-chips">
          <button
            v-for="f in activeTypeFilters"
            :key="f.value"
            :class="['sb-chip', filterType === f.value ? 'sb-chip-active' : '']"
            @click="filterType = f.value"
          >
            {{ f.label }}
          </button>
        </div>

        <!-- Category filter -->
        <select
          v-if="showCategories"
          v-model="filterCategory"
          class="sb-input sb-catalog-category"
          aria-label="Категория"
        >
          <option value="">Все категории</option>
          <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
        </select>
      </div>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="sb-grid sb-grid-2 sb-grid-3">
      <div v-for="i in 6" :key="i" class="sb-card">
        <div class="sb-skeleton sb-skeleton-image"></div>
        <div class="sb-skeleton sb-skeleton-title"></div>
        <div class="sb-skeleton sb-skeleton-text" style="width: 40%;"></div>
        <div class="sb-skeleton sb-skeleton-btn sb-mt-2" style="width: 100%;"></div>
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
