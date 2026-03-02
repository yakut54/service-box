import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api } from '@/lib/api'
import type { Category } from '@/types'

export const useCategoriesStore = defineStore('categories', () => {
  const categories = ref<Category[]>([])
  const loading = ref(false)
  const error = ref('')

  // Плоский список всех категорий (родители + дочерние)
  const allCategories = computed(() => {
    const result: Category[] = []
    for (const cat of categories.value) {
      result.push(cat)
      if (cat.children) {
        result.push(...cat.children)
      }
    }
    return result
  })

  // Опции для CustomSelect (с визуальным отступом для дочерних)
  const categoryOptions = computed(() => [
    { value: '', label: 'Без категории' },
    ...allCategories.value.map(c => ({
      value: c.id,
      label: c.parent_id ? `— ${c.name}` : c.name,
    })),
  ])

  // Только родительские категории (для выбора родителя при создании)
  const parentOptions = computed(() =>
    categories.value
      .filter(c => !c.parent_id)
      .map(c => ({ value: c.id, label: c.name }))
  )

  async function fetchCategories() {
    loading.value = true
    error.value = ''
    try {
      const data = await api.getCategories()
      categories.value = data.data
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Ошибка загрузки категорий'
    } finally {
      loading.value = false
    }
  }

  function getCategoryName(id: string | null | undefined): string {
    if (!id) return ''
    return allCategories.value.find(c => c.id === id)?.name ?? ''
  }

  function $reset() {
    categories.value = []
    loading.value = false
    error.value = ''
  }

  return {
    categories,
    loading,
    error,
    allCategories,
    categoryOptions,
    parentOptions,
    fetchCategories,
    getCategoryName,
    $reset,
  }
})
