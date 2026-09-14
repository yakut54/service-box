import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/lib/api'
import type { Product, PaginationMeta } from '@/types'

export const useProductsStore = defineStore('products', () => {
  const products = ref<Product[]>([])
  const loading = ref(false)
  const meta = ref<PaginationMeta | null>(null)

  const activeProducts = computed(() => products.value.filter(p => p.is_active))

  async function fetchProducts(params?: Record<string, string>) {
    loading.value = true
    try {
      const data = await api.getProducts(params)
      products.value = data.data
      meta.value = data.meta ?? null
    } finally {
      loading.value = false
    }
  }

  async function deleteProduct(id: string) {
    await api.deleteProduct(id)
    products.value = products.value.filter(p => p.id !== id)
  }

  function $reset() {
    products.value = []
    loading.value = false
    meta.value = null
  }

  return {
    products,
    loading,
    meta,
    activeProducts,
    fetchProducts,
    deleteProduct,
    $reset,
  }
})
