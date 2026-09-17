<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useProductsStore } from '@/stores/products'
import { useCategoriesStore } from '@/stores/categories'
import CustomSelect from '@/components/CustomSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import ProductCard from '@/components/products/ProductCard.vue'
import UiSkeleton from '@/shared/ui/UiSkeleton.vue'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import UiPagination from '@/shared/ui/UiPagination.vue'
import UiTooltip from '@/shared/ui/UiTooltip.vue'
import { plural } from '@/lib/utils'
import { formatPrice } from '@/shared/lib/format'

const route = useRoute()
const productsStore = useProductsStore()
const categoriesStore = useCategoriesStore()
const deleteConfirm = ref<string | null>(null)
const filterSearch = ref('')
const filterCategory = ref('')
const sortBy = ref('')
const page = ref(1)
const perPage = ref(25)

// Вид — плитка (фото-карточки, удобно листать глазами) или список (плотные
// строки, удобно сравнивать много позиций разом) — общепринятый паттерн
// (Google Drive/Notion/Figma). Запоминаем на этом устройстве, не на
// сервере — это чисто предпочтение конкретного сотрудника за конкретным
// экраном, не часть данных магазина.
const VIEW_MODE_KEY = 'products_view_mode'
const viewMode = ref<'grid' | 'list'>('grid')
try {
  const saved = localStorage.getItem(VIEW_MODE_KEY)
  if (saved === 'grid' || saved === 'list') viewMode.value = saved
} catch {
  // localStorage недоступен (приватный режим и т.п.) — остаёмся на плитке
}
function setViewMode(mode: 'grid' | 'list') {
  viewMode.value = mode
  try { localStorage.setItem(VIEW_MODE_KEY, mode) } catch { /* см. выше */ }
}

const sortOptions = [
  { value: '', label: 'По умолчанию' },
  { value: 'name', label: 'А→Я по названию' },
  { value: 'rating_desc', label: 'По рейтингу ↓' },
  { value: 'price_desc', label: 'По цене: дорогие' },
  { value: 'price_asc', label: 'По цене: дешёвые' },
]

const categoryOptions = computed(() => [
  { value: '', label: 'Все категории' },
  ...categoriesStore.allCategories.map(c => ({
    value: c.id,
    label: c.parent_id ? `— ${c.name}` : c.name,
  })),
])

function buildParams(): Record<string, string> {
  const params: Record<string, string> = { page: String(page.value), per_page: String(perPage.value) }
  if (filterSearch.value) params.search = filterSearch.value
  if (filterCategory.value) params.category_id = filterCategory.value
  if (sortBy.value) params.sort = sortBy.value
  return params
}

onMounted(async () => {
  if (route.query.category_id) filterCategory.value = route.query.category_id as string
  await Promise.all([
    productsStore.fetchProducts(buildParams()),
    categoriesStore.categories.length ? Promise.resolve() : categoriesStore.fetchCategories(),
  ])
})

async function applyFilters() {
  page.value = 1
  await productsStore.fetchProducts(buildParams())
}

async function goToPage(p: number) {
  page.value = p
  await productsStore.fetchProducts(buildParams())
}

async function changePerPage(n: number) {
  perPage.value = n
  page.value = 1
  await productsStore.fetchProducts(buildParams())
}

async function handleDelete(id: string) {
  await productsStore.deleteProduct(id)
  deleteConfirm.value = null
}
</script>

<template>
  <div>
    <PageHeader
      class="mb-6"
      title="Товары"
      :subtitle="`${productsStore.meta?.total ?? productsStore.products.length} ${plural(productsStore.meta?.total ?? productsStore.products.length, 'позиция', 'позиции', 'позиций')}`"
    >
      <RouterLink to="/products/new" class="btn-primary shrink-0">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Добавить</span>
      </RouterLink>
    </PageHeader>

    <div class="card mb-6">
      <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
          <input v-model="filterSearch" @input="applyFilters" type="text" class="input" placeholder="Поиск по названию..." />
        </div>
        <CustomSelect
          v-if="categoriesStore.allCategories.length > 0"
          v-model="filterCategory" @change="applyFilters"
          :options="categoryOptions" class="w-full sm:w-64" searchable
        />
        <CustomSelect v-model="sortBy" @change="applyFilters" :options="sortOptions" class="w-full sm:w-48" />

        <!-- Плитка/список — скрыт на мобильном: список там всё равно
             отображается как плитка (см. ниже), переключать нечего. -->
        <div class="hidden sm:flex items-center gap-1 rounded-lg border border-gray-200 dark:border-gray-700 p-1 shrink-0">
          <UiTooltip>
            <button
              @click="setViewMode('grid')"
              :class="['p-1.5 rounded-md transition-colors', viewMode === 'grid' ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300']"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
              </svg>
            </button>
            <template #content>Плитка</template>
          </UiTooltip>
          <UiTooltip>
            <button
              @click="setViewMode('list')"
              :class="['p-1.5 rounded-md transition-colors', viewMode === 'list' ? 'bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300']"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
              </svg>
            </button>
            <template #content>Список</template>
          </UiTooltip>
        </div>
      </div>
    </div>

    <div v-if="productsStore.loading">
      <!-- Плитка -->
      <div v-if="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 3xl:grid-cols-4 gap-4">
        <div v-for="i in 8" :key="i" class="card flex flex-col">
          <UiSkeleton height="auto" class="aspect-video mb-4" rounded="lg" />
          <UiSkeleton height="0.875rem" />
          <UiSkeleton width="60%" height="0.875rem" class="mt-2" />
          <UiSkeleton width="40%" height="1.25rem" class="mt-3" />
        </div>
      </div>
      <!-- Список -->
      <template v-else>
        <div class="sm:hidden grid grid-cols-1 gap-4">
          <div v-for="i in 4" :key="i" class="card flex flex-col">
            <UiSkeleton height="auto" class="aspect-video mb-4" rounded="lg" />
            <UiSkeleton height="0.875rem" />
            <UiSkeleton width="40%" height="1.25rem" class="mt-3" />
          </div>
        </div>
        <div class="hidden sm:block card overflow-hidden p-0 divide-y divide-gray-100 dark:divide-gray-800">
          <div v-for="i in 6" :key="i" class="flex items-center gap-4 px-4 py-3">
            <UiSkeleton width="2.5rem" height="2.5rem" rounded="lg" />
            <UiSkeleton width="10rem" height="0.875rem" />
            <UiSkeleton width="6rem" height="0.875rem" />
            <UiSkeleton width="4rem" height="0.875rem" />
            <UiSkeleton width="4.5rem" height="1.25rem" rounded="full" />
          </div>
        </div>
      </template>
    </div>

    <div v-else-if="productsStore.products.length === 0" class="card py-12 text-center">
      <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Нет товаров</h3>
      <p class="text-gray-500 dark:text-gray-400 mb-4">Добавьте первый товар или услугу</p>
      <RouterLink to="/products/new" class="btn-primary">Добавить товар</RouterLink>
    </div>

    <template v-else>
      <!-- Плитка — всегда на мобильном (<640px), и на любом экране, если
           выбран этот вид. -->
      <div
        v-if="viewMode === 'grid'"
        class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 3xl:grid-cols-4 gap-4"
      >
        <ProductCard
          v-for="product in productsStore.products" :key="product.id"
          :product="product" @delete="deleteConfirm = $event"
        />
      </div>

      <template v-else>
        <div class="sm:hidden grid grid-cols-1 gap-4">
          <ProductCard
            v-for="product in productsStore.products" :key="product.id"
            :product="product" @delete="deleteConfirm = $event"
          />
        </div>

        <div class="hidden sm:block card overflow-hidden p-0">
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr><th></th><th>Товар</th><th>Категория</th><th>Цена</th><th>Статус</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="product in productsStore.products" :key="product.id">
                  <td class="w-14">
                    <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-700 overflow-hidden shrink-0">
                      <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="w-full h-full object-cover" />
                    </div>
                  </td>
                  <td>
                    <RouterLink :to="`/products/${product.id}/edit`" class="font-medium text-gray-900 dark:text-gray-100 hover:text-primary-600 dark:hover:text-primary-400">
                      {{ product.name }}
                    </RouterLink>
                  </td>
                  <td class="text-sm text-gray-500 dark:text-gray-400">{{ product.category?.name ?? '—' }}</td>
                  <td class="font-semibold dark:text-gray-100">{{ formatPrice(product.price) }}</td>
                  <td>
                    <span :class="['badge', product.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800']">
                      {{ product.is_active ? 'Активен' : 'Скрыт' }}
                    </span>
                  </td>
                  <td>
                    <div class="flex items-center gap-1 justify-end">
                      <RouterLink :to="`/products/${product.id}/edit`" class="btn-ghost btn-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                      </RouterLink>
                      <UiTooltip>
                        <button @click="deleteConfirm = product.id" class="btn-ghost btn-sm text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20">
                          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                        <template #content>Удалить</template>
                      </UiTooltip>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </template>

    <UiPagination
      v-if="productsStore.meta"
      :current-page="productsStore.meta.current_page"
      :last-page="productsStore.meta.last_page"
      :total="productsStore.meta.total"
      :per-page="productsStore.meta.per_page"
      @update:current-page="goToPage"
      @update:per-page="changePerPage"
    />

    <UiConfirmDialog
      :modelValue="!!deleteConfirm"
      @update:modelValue="!$event && (deleteConfirm = null)"
      title="Удалить товар?"
      @confirm="handleDelete(deleteConfirm!)"
    >
      Это действие нельзя отменить.
    </UiConfirmDialog>
  </div>
</template>
