<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useCategoryChangesStore } from '@/stores/categoryChanges'
import { useHighlightNew } from '@/composables/useHighlightNew'
import CustomSelect from '@/components/CustomSelect.vue'
import { UiSkeleton, UiEmptyState, UiPagination, UiHint } from '@/shared/ui'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import { formatDateTime, formatCategoryChangeValue } from '@/shared/lib/format'
import { CATEGORY_CHANGE_FIELD_LABELS, CATEGORY_CHANGE_ACTION_LABELS } from '@/shared/lib/labels'
import type { CategoryChange } from '@/types'

const authStore = useAuthStore()
const categoryChangesStore = useCategoryChangesStore()
const { markNew, highlightClass } = useHighlightNew<CategoryChange>()

const seenHandled = ref(false)

const rows    = ref<CategoryChange[]>([])
const loading = ref(false)
const error   = ref('')

const page    = ref(1)
const perPage = ref(25)
const meta    = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 })

const filterCategory = ref('')
const categoryOptions = ref<{ value: string; label: string }[]>([{ value: '', label: 'Все категории' }])

const filterActor = ref('')
const actorOptions = ref<{ value: string; label: string }[]>([{ value: '', label: 'Все сотрудники' }])

function buildParams() {
  const params: Record<string, string> = { page: String(page.value), per_page: String(perPage.value) }
  if (filterCategory.value) params.category_id = filterCategory.value
  if (filterActor.value) params.actor_user_id = filterActor.value
  return params
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getCategoryChanges(buildParams())
    rows.value = res.data
    if (res.meta) meta.value = res.meta

    res.data.forEach(r => {
      if (r.actor_user_id && !actorOptions.value.some(o => o.value === r.actor_user_id)) {
        actorOptions.value.push({ value: r.actor_user_id, label: r.actor_name })
      }
    })

    if (!seenHandled.value) {
      seenHandled.value = true
      markNew(res.data, res.category_changes_last_seen_at)
      await api.markCategoryChangesSeen().catch(() => {})
      categoryChangesStore.fetchPendingCount()
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
  try { await api.exportCategoryChanges({ ...buildParams(), format }) } finally { exporting.value = false }
}

// ── Выборочное удаление (владелец) ──────────────────────────────
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
    await api.deleteCategoryChanges([...selectedIds.value])
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

defineExpose({ doExport, toggleSelectMode, selectMode })

onMounted(async () => {
  load()
  try {
    const res = await api.getCategories()
    categoryOptions.value = [
      { value: '', label: 'Все категории' },
      ...res.data.map((c: any) => ({ value: c.id, label: c.name })),
    ]
  } catch {
    // Фильтр по категории — необязательное удобство, список и так загрузится.
  }
})
</script>

<template>
  <div class="space-y-6">
    <div class="card flex flex-wrap items-center gap-3 py-3">
      <UiHint>Экспортируется с учётом применённых ниже фильтров. Чтобы выгрузить весь журнал — сначала нажмите «Сбросить».</UiHint>
      <div class="flex items-center gap-2 ml-auto">
        <button @click="doExport('csv')" :disabled="exporting" class="btn-secondary text-sm">CSV</button>
        <button @click="doExport('xlsx')" :disabled="exporting" class="btn-secondary text-sm">Excel</button>
        <button v-if="authStore.isOwner" @click="toggleSelectMode" class="btn-secondary text-sm">
          {{ selectMode ? 'Отмена' : 'Выбрать' }}
        </button>
      </div>
    </div>

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
      <CustomSelect v-model="filterCategory" :options="categoryOptions" placeholder="Все категории" class="flex-1 sm:max-w-xs" searchable @change="applyFilters" />
      <CustomSelect v-model="filterActor" :options="actorOptions" placeholder="Все сотрудники" class="sm:w-56" @change="applyFilters" />
      <button
        v-if="filterCategory || filterActor"
        @click="filterCategory = ''; filterActor = ''; applyFilters()"
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
      description="Здесь появятся записи, когда кто-то создаст, переименует, переместит, скроет или удалит категорию"
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
          <p v-if="row.category_id" class="font-medium text-gray-900 dark:text-white truncate">{{ row.category_name }}</p>
          <p v-else class="font-medium text-gray-400 dark:text-gray-500 truncate">{{ row.category_name }} <span class="text-xs">(категория удалена)</span></p>
        </div>
        <p v-if="row.action === 'updated'" class="text-sm text-gray-700 dark:text-gray-300 sm:whitespace-nowrap">
          <span class="text-gray-400 dark:text-gray-500">{{ CATEGORY_CHANGE_FIELD_LABELS[row.field ?? ''] ?? row.field }}:</span>
          {{ formatCategoryChangeValue(row.field, row.old_value) }}
          →
          {{ formatCategoryChangeValue(row.field, row.new_value) }}
        </p>
        <p v-else class="text-sm font-medium text-gray-700 dark:text-gray-300 sm:whitespace-nowrap">
          {{ CATEGORY_CHANGE_ACTION_LABELS[row.action] ?? row.action }}
        </p>
        <p class="text-sm text-gray-500 dark:text-gray-400 min-w-0 flex-1 truncate">{{ row.actor_name }}</p>
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
