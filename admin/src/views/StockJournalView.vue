<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import CustomSelect from '@/components/CustomSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { UiSkeleton, UiEmptyState, UiPagination } from '@/shared/ui'
import { formatDateTime, formatWeight } from '@/shared/lib/format'
import type { StockAdjustment } from '@/types'

const authStore = useAuthStore()

const rows    = ref<StockAdjustment[]>([])
const loading = ref(false)
const error   = ref('')

const page    = ref(1)
const perPage = ref(25)
const meta    = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 })

const filterProduct = ref('')
const productOptions = ref<{ value: string; label: string }[]>([{ value: '', label: 'Все товары' }])

const filterActor = ref('')
// Только реально встреченные в журнале сотрудники (а не весь теоретический
// состав команды) — проще и честнее, чем тянуть права на /admin/staff ещё
// раз (у админа они и так урезаны до его собственных сборщиков).
const actorOptions = ref<{ value: string; label: string }[]>([{ value: '', label: 'Все сотрудники' }])

function buildParams() {
  const params: Record<string, string> = { page: String(page.value), per_page: String(perPage.value) }
  if (filterProduct.value) params.product_id = filterProduct.value
  if (filterActor.value) params.actor_user_id = filterActor.value
  return params
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getStockJournal(buildParams())
    rows.value = res.data
    if (res.meta) meta.value = res.meta

    res.data.forEach(r => {
      if (r.actor_user_id && !actorOptions.value.some(o => o.value === r.actor_user_id)) {
        actorOptions.value.push({ value: r.actor_user_id, label: r.actor_name })
      }
    })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

async function applyFilters() {
  page.value = 1
  await load()
}

async function goToPage(p: number) {
  page.value = p
  await load()
}

async function changePerPage(n: number) {
  perPage.value = n
  page.value = 1
  await load()
}

onMounted(async () => {
  load()
  try {
    const res = await api.getProducts({ limit: '200' })
    productOptions.value = [
      { value: '', label: 'Все товары' },
      ...res.data.map((p: any) => ({ value: p.id, label: p.name })),
    ]
  } catch {
    // Фильтр по товару — необязательное удобство, список и так загрузится.
  }
})
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Журнал остатков" subtitle="Ручные правки остатка товаров — кто, когда и что изменил" />

    <div class="flex flex-col sm:flex-row gap-3">
      <CustomSelect v-model="filterProduct" :options="productOptions" placeholder="Все товары" class="flex-1 sm:max-w-xs" searchable @change="applyFilters" />
      <CustomSelect v-model="filterActor" :options="actorOptions" placeholder="Все сотрудники" class="sm:w-56" @change="applyFilters" />
      <button
        v-if="filterProduct || filterActor"
        @click="filterProduct = ''; filterActor = ''; applyFilters()"
        class="btn-ghost btn-sm whitespace-nowrap"
      >
        Сбросить
      </button>
    </div>

    <div v-if="loading" class="space-y-2">
      <div v-for="i in 6" :key="i" class="card flex items-center gap-4">
        <UiSkeleton width="10rem" height="1rem" />
        <UiSkeleton width="6rem" height="1rem" />
        <UiSkeleton width="8rem" height="1rem" class="ml-auto" />
      </div>
    </div>

    <div v-else-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <UiEmptyState
      v-else-if="rows.length === 0"
      title="Правок остатка ещё не было"
      description="Здесь появятся записи, когда кто-то вручную поправит остаток товара на складе"
    >
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </template>
    </UiEmptyState>

    <div v-else class="space-y-2">
      <div v-for="row in rows" :key="row.id" class="card flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
        <div class="min-w-0 flex-1">
          <RouterLink
            v-if="row.product_id"
            :to="`/products/${row.product_id}/edit`"
            class="font-medium text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 truncate block"
          >{{ row.product_name }}</RouterLink>
          <p v-else class="font-medium text-gray-400 dark:text-gray-500 truncate">{{ row.product_name }} <span class="text-xs">(товар удалён)</span></p>
          <p v-if="row.variant_label" class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ row.variant_label }}</p>
        </div>
        <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">
          {{ row.unit === 'g' ? formatWeight(row.old_value) : row.old_value }}
          →
          {{ row.unit === 'g' ? formatWeight(row.new_value) : row.new_value }}
        </p>
        <p class="text-sm text-gray-500 dark:text-gray-400 min-w-0 flex-1 truncate">{{ row.actor_name }}{{ row.reason ? ` · ${row.reason}` : '' }}</p>
        <p class="text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap shrink-0">{{ formatDateTime(row.created_at, authStore.shop?.timezone ?? undefined) }}</p>
      </div>

      <UiPagination
        :current-page="meta.current_page"
        :last-page="meta.last_page"
        :total="meta.total"
        :per-page="meta.per_page"
        @update:current-page="goToPage"
        @update:per-page="changePerPage"
      />
    </div>
  </div>
</template>
