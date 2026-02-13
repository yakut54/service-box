import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/lib/api'

export const useProductsStore = defineStore('products', () => {
  const products = ref<any[]>([])
  const loading = ref(false)

  const activeProducts = computed(() => products.value.filter(p => p.is_active))

  const categories = computed(() => {
    const cats = new Set<string>()
    products.value.forEach(p => {
      if (p.category) cats.add(p.category)
    })
    return Array.from(cats).sort()
  })

  async function fetchProducts(params?: Record<string, string>) {
    loading.value = true
    try {
      const data = await api.getProducts(params)
      products.value = data.data
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
  }

  return {
    products,
    loading,
    activeProducts,
    categories,
    fetchProducts,
    deleteProduct,
    $reset,
  }
})
