<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useProductsStore } from '@/stores/products'

const productsStore = useProductsStore()
const deleteConfirm = ref<string | null>(null)
const filterType = ref('')
const filterSearch = ref('')

function formatPrice(kopecks: number): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(kopecks / 100)
}

const typeLabels: Record<string, string> = { physical: 'Физический', digital: 'Цифровой', service: 'Услуга' }

onMounted(() => { productsStore.fetchProducts() })

async function applyFilters() {
  const params: Record<string, string> = {}
  if (filterType.value) params.type = filterType.value
  if (filterSearch.value) params.search = filterSearch.value
  await productsStore.fetchProducts(params)
}

async function handleDelete(id: string) {
  await productsStore.deleteProduct(id)
  deleteConfirm.value = null
}

function getStockBadge(product: any) {
  if (product.type !== 'physical') return null
  const stock = product.physical?.stock_quantity ?? 0
  if (stock === 0) return { cls: 'bg-red-100 text-red-800', text: 'Нет в наличии' }
  if (stock < 5) return { cls: 'bg-yellow-100 text-yellow-800', text: `Мало: ${stock}` }
  return { cls: 'bg-green-100 text-green-800', text: `В наличии: ${stock}` }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Товары и услуги</h1>
        <p class="text-gray-500 mt-1">{{ productsStore.products.length }} позиций</p>
      </div>
      <RouterLink to="/products/new" class="btn-primary">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Добавить
      </RouterLink>
    </div>

    <div class="card mb-6">
      <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
          <input v-model="filterSearch" @input="applyFilters" type="text" class="input" placeholder="Поиск по названию..." />
        </div>
        <select v-model="filterType" @change="applyFilters" class="input w-full sm:w-48">
          <option value="">Все типы</option>
          <option value="physical">Физические</option>
          <option value="digital">Цифровые</option>
          <option value="service">Услуги</option>
        </select>
      </div>
    </div>

    <div v-if="productsStore.loading" class="card py-12 text-center">
      <div class="animate-spin w-8 h-8 border-4 border-primary-600 border-t-transparent rounded-full mx-auto"></div>
      <p class="text-gray-500 mt-4">Загрузка...</p>
    </div>

    <div v-else-if="productsStore.products.length === 0" class="card py-12 text-center">
      <h3 class="text-lg font-medium text-gray-900 mb-2">Нет товаров</h3>
      <p class="text-gray-500 mb-4">Добавьте первый товар или услугу</p>
      <RouterLink to="/products/new" class="btn-primary">Добавить товар</RouterLink>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="product in productsStore.products" :key="product.id" class="card group hover:shadow-md transition-shadow">
        <div class="aspect-video bg-gray-100 rounded-lg mb-4 overflow-hidden">
          <img v-if="product.image_url" :src="product.image_url" :alt="product.name" class="w-full h-full object-cover" />
          <div v-else class="w-full h-full flex items-center justify-center">
            <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          </div>
        </div>

        <div class="flex items-start justify-between mb-2">
          <div>
            <h3 class="font-medium text-gray-900 line-clamp-1">{{ product.name }}</h3>
            <p class="text-sm text-gray-500">{{ typeLabels[product.type] || product.type }}</p>
          </div>
          <span :class="['badge', product.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800']">
            {{ product.is_active ? 'Активен' : 'Скрыт' }}
          </span>
        </div>

        <div class="flex items-center justify-between mb-4">
          <span class="text-lg font-semibold text-gray-900">{{ formatPrice(product.price) }}</span>
          <span v-if="getStockBadge(product)" :class="['badge', getStockBadge(product)!.cls]">{{ getStockBadge(product)!.text }}</span>
          <span v-else-if="product.type === 'service' && product.service" class="text-sm text-gray-500">{{ product.service.duration_minutes }} мин</span>
        </div>

        <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
          <RouterLink :to="`/products/${product.id}/edit`" class="btn-secondary btn-sm flex-1">Редактировать</RouterLink>
          <button @click="deleteConfirm = product.id" class="btn-ghost btn-sm text-red-600 hover:bg-red-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Delete modal -->
    <div v-if="deleteConfirm" class="fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50 p-4" @click.self="deleteConfirm = null">
      <div class="bg-white rounded-xl p-6 max-w-sm w-full">
        <h3 class="text-lg font-semibold text-gray-900 mb-2">Удалить товар?</h3>
        <p class="text-gray-500 mb-6">Это действие нельзя отменить.</p>
        <div class="flex gap-3">
          <button @click="deleteConfirm = null" class="btn-secondary flex-1">Отмена</button>
          <button @click="handleDelete(deleteConfirm!)" class="btn-danger flex-1">Удалить</button>
        </div>
      </div>
    </div>
  </div>
</template>
