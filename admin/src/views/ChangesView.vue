<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import ProductChangesTab from '@/components/changes/ProductChangesTab.vue'
import CategoryChangesTab from '@/components/changes/CategoryChangesTab.vue'

type Tab = 'products' | 'categories'

const route = useRoute()
const router = useRouter()

const activeTab = ref<Tab>(route.query.tab === 'categories' ? 'categories' : 'products')

// Вкладка — в query, чтобы ссылку можно было сохранить/переслать сразу на нужную.
watch(activeTab, tab => {
  router.replace({ query: { ...route.query, tab } })
})

const subtitle: Record<Tab, string> = {
  products: 'Кто, когда и что поменял в товарах — остаток, цена, название, описание, фото, категория, видимость',
  categories: 'Кто, когда создал, переименовал, переместил, скрыл или удалил категорию',
}
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Журнал изменений" :subtitle="subtitle[activeTab]" />

    <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700">
      <button
        @click="activeTab = 'products'"
        :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          activeTab === 'products'
            ? 'border-primary-600 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200']"
      >
        Товары
      </button>
      <button
        @click="activeTab = 'categories'"
        :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          activeTab === 'categories'
            ? 'border-primary-600 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200']"
      >
        Категории
      </button>
    </div>

    <KeepAlive>
      <ProductChangesTab v-if="activeTab === 'products'" />
      <CategoryChangesTab v-else />
    </KeepAlive>
  </div>
</template>
