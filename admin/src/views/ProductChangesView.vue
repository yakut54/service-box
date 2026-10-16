<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useProductChangesStore } from '@/stores/productChanges'
import { useHighlightNew } from '@/composables/useHighlightNew'
import CustomSelect from '@/components/CustomSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import { UiSkeleton, UiEmptyState, UiPagination, UiHint } from '@/shared/ui'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import { formatDateTime, formatProductChangeValue } from '@/shared/lib/format'
import { PRODUCT_CHANGE_FIELD_LABELS, PRODUCT_CHANGE_ACTION_LABELS } from '@/shared/lib/labels'
import type { ProductChange } from '@/types'

const authStore = useAuthStore()
const productChangesStore = useProductChangesStore()
const { markNew, highlightClass } = useHighlightNew<ProductChange>()

// Отметка "просмотрено" и подсветка новых — только на САМОМ ПЕРВОМ заходе
// на страницу, не на каждой перелистке/смене фильтра (в отличие от
// Отзывов, где load() вызывается один раз). Иначе смена страницы
// пагинации сдвигала бы курсор "просмотрено" на бэкенде.
const seenHandled = ref(false)

const rows    = ref<ProductChange[]>([])
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
    const res = await api.getProductChanges(buildParams())
    rows.value = res.data
    if (res.meta) meta.value = res.meta

    res.data.forEach(r => {
      if (r.actor_user_id && !actorOptions.value.some(o => o.value === r.actor_user_id)) {
        actorOptions.value.push({ value: r.actor_user_id, label: r.actor_name })
      }
    })

    if (!seenHandled.value) {
      seenHandled.value = true
      markNew(res.data, res.product_changes_last_seen_at)
      // Важно: сначала markSeen, потом pendingCount — тот же приём, что в
      // ReviewsView.vue (гонка иначе отдаёт устаревший бейдж).
      await api.markProductChangesSeen().catch(() => {})
      productChangesStore.fetchPendingCount()
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

async function applyFilters() {
  page.value = 1
  selectedIds.value = new Set()
  await load()
}

async function goToPage(p: number) {
  page.value = p
  selectedIds.value = new Set()
  await load()
}

async function changePerPage(n: number) {
  perPage.value = n
  page.value = 1
  selectedIds.value = new Set()
  await load()
}

const exporting = ref(false)
async function doExport(format: 'csv' | 'xlsx') {
  exporting.value = true
  try { await api.exportProductChanges({ ...buildParams(), format }) } finally { exporting.value = false }
}

// ── Выборочное удаление (владелец) ──────────────────────────────
// Один механизм и на одну запись, и на несколько — чекбоксы + общая
// кнопка «Удалить», без отдельной урны на каждой строке.
const selectMode  = ref(false)
const selectedIds = ref<Set<string>>(new Set())
const confirmOpen = ref(false)
const deleting    = ref(false)

function toggleSelectMode() {
  selectMode.value = !selectMode.value
  selectedIds.value = new Set()
}

function toggleRow(id: string) {
  const next = new Set(selectedIds.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selectedIds.value = next
}

const allOnPageSelected = computed(() => rows.value.length > 0 && rows.value.every(r => selectedIds.value.has(r.id)))

function toggleSelectAllOnPage() {
  selectedIds.value = allOnPageSelected.value ? new Set() : new Set(rows.value.map(r => r.id))
}

async function doDelete() {
  deleting.value = true
  try {
    await api.deleteProductChanges([...selectedIds.value])
    confirmOpen.value = false
    selectMode.value = false
    selectedIds.value = new Set()
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  } finally {
    deleting.value = false
  }
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
    <PageHeader title="Журнал изменений" subtitle="Кто, когда и что поменял в товарах — остаток, цена, название, категория, видимость">
      <UiHint align="end">Экспортируется с учётом применённых выше фильтров. Чтобы выгрузить весь журнал — сначала нажмите «Сбросить».</UiHint>
      <button @click="doExport('csv')" :disabled="exporting" class="btn-secondary text-sm">CSV</button>
      <button @click="doExport('xlsx')" :disabled="exporting" class="btn-secondary text-sm">Excel</button>
      <button v-if="authStore.isOwner" @click="toggleSelectMode" class="btn-secondary text-sm">
        {{ selectMode ? 'Отмена' : 'Выбрать' }}
      </button>
    </PageHeader>

    <div v-if="selectMode" class="card flex flex-wrap items-center gap-3 py-3">
      <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer select-none">
        <input type="checkbox" :checked="allOnPageSelected" @change="toggleSelectAllOnPage" class="w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500" />
        Выбрать все на странице
      </label>
      <span class="text-sm text-gray-500 dark:text-gray-400">Выбрано: {{ selectedIds.size }}</span>
      <button
        @click="confirmOpen = true"
        :disabled="selectedIds.size === 0"
        class="btn-danger text-sm ml-auto"
      >
        Удалить ({{ selectedIds.size }})
      </button>
    </div>

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
      title="Изменений ещё не было"
      description="Здесь появятся записи, когда кто-то поправит товар — остаток, цену, название, категорию или видимость"
    >
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </template>
    </UiEmptyState>

    <div v-else class="space-y-2">
      <div
        v-for="row in rows"
        :key="row.id"
        :class="['card flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 transition-colors duration-[3000ms]', highlightClass(row.id)]"
      >
        <input
          v-if="selectMode"
          type="checkbox"
          :checked="selectedIds.has(row.id)"
          @change="toggleRow(row.id)"
          class="w-4 h-4 shrink-0 rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500"
        />
        <div class="min-w-0 flex-1">
          <RouterLink
            v-if="row.product_id"
            :to="`/products/${row.product_id}/edit`"
            class="font-medium text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 truncate block"
          >{{ row.product_name }}</RouterLink>
          <p v-else class="font-medium text-gray-400 dark:text-gray-500 truncate">{{ row.product_name }} <span class="text-xs">(товар удалён)</span></p>
          <p v-if="row.variant_label" class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ row.variant_label }}</p>
        </div>
        <p v-if="row.action === 'updated'" class="text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">
          <span class="text-gray-400 dark:text-gray-500">{{ PRODUCT_CHANGE_FIELD_LABELS[row.field ?? ''] ?? row.field }}:</span>
          {{ formatProductChangeValue(row.field, row.unit, row.old_value) }}
          →
          {{ formatProductChangeValue(row.field, row.unit, row.new_value) }}
        </p>
        <p v-else class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
          {{ PRODUCT_CHANGE_ACTION_LABELS[row.action] ?? row.action }}
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

    <UiConfirmDialog
      v-model="confirmOpen"
      :title="`Удалить ${selectedIds.size} ${selectedIds.size === 1 ? 'запись' : 'записей'}?`"
      :loading="deleting"
      @confirm="doDelete"
    >
      Действие необратимо — удалённые записи журнала нельзя будет восстановить.
    </UiConfirmDialog>
  </div>
</template>
