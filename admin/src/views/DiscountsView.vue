<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/composables/useToast'
import { useCategoriesStore } from '@/stores/categories'
import CustomSelect from '@/components/CustomSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import DiscountFormModal from '@/components/modals/DiscountFormModal.vue'
import DiscountGuideModal from '@/components/modals/DiscountGuideModal.vue'
import UiSpinner from '@/shared/ui/UiSpinner.vue'
import UiEmptyState from '@/shared/ui/UiEmptyState.vue'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import { formatPrice } from '@/shared/lib/format'
import type { Discount, DiscountScope } from '@/types'

const toast = useToast()

const categoriesStore = useCategoriesStore()

// ── State ────────────────────────────────────────────────────
const discounts = ref<Discount[]>([])
const products  = ref<{ id: string; name: string }[]>([])
const loading   = ref(false)
const error     = ref('')

// ── Modal ────────────────────────────────────────────────────
const showModal       = ref(false)
const editingDiscount = ref<Discount | null>(null)
const showGuide        = ref(false)

// ── Delete ────────────────────────────────────────────────────
const deleteTarget = ref<Discount | null>(null)
const deleting     = ref(false)

// ── Filters ───────────────────────────────────────────────────
const filterSearch = ref('')
const filterType   = ref('all')
const filterStatus = ref('all')

const typeOptions = [
  { value: 'all',     label: 'Все типы' },
  { value: 'percent', label: 'Процент' },
  { value: 'fixed',   label: 'Фиксированная' },
]

const statusOptions = [
  { value: 'all',      label: 'Все статусы' },
  { value: 'active',   label: 'Активные' },
  { value: 'inactive', label: 'Неактивные' },
]

// ── Helpers ───────────────────────────────────────────────────
function rub(kopecks: number | null): string {
  return kopecks !== null ? formatPrice(kopecks) : '—'
}

function formatValue(d: Discount): string {
  return d.type === 'percent' ? `−${d.value}%` : `−${rub(d.value)}`
}

function formatPeriod(d: Discount): string {
  if (!d.starts_at && !d.ends_at) return 'Бессрочно'
  const fmt = (s: string) =>
    new Date(s).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
  if (d.starts_at && d.ends_at) return `${fmt(d.starts_at)} — ${fmt(d.ends_at)}`
  if (d.ends_at)   return `до ${fmt(d.ends_at)}`
  return `с ${fmt(d.starts_at!)}`
}

function formatUsage(d: Discount): string {
  return d.usage_limit !== null
    ? `${d.usage_count} / ${d.usage_limit}`
    : `${d.usage_count} / ∞`
}

function scopeLabel(s: DiscountScope): string {
  return { cart: 'Корзина', product: 'Товар', category: 'Категория' }[s]
}

function isExpired(d: Discount): boolean {
  return !!d.ends_at && new Date(d.ends_at) < new Date()
}

// ── Filters computed ──────────────────────────────────────────
const filteredDiscounts = computed(() => {
  let list = discounts.value
  if (filterType.value !== 'all')   list = list.filter(d => d.type === filterType.value)
  if (filterStatus.value === 'active')   list = list.filter(d => d.is_active && !isExpired(d))
  if (filterStatus.value === 'inactive') list = list.filter(d => !d.is_active || isExpired(d))
  if (filterSearch.value.trim()) {
    const q = filterSearch.value.toLowerCase()
    list = list.filter(d =>
      d.name.toLowerCase().includes(q) ||
      (d.code || '').toLowerCase().includes(q),
    )
  }
  return list
})

const activeCount = computed(() => discounts.value.filter(d => d.is_active && !isExpired(d)).length)

// ── Load ──────────────────────────────────────────────────────
async function load() {
  loading.value = true
  error.value   = ''
  try {
    const [discRes, prodRes] = await Promise.all([
      api.getDiscounts(),
      api.getProducts({ limit: '200' }),
    ])
    discounts.value = discRes.data
    products.value  = prodRes.data.map((p: any) => ({ id: p.id, name: p.name }))
    if (!categoriesStore.categories.length) categoriesStore.fetchCategories()
  } catch (e: any) {
    error.value = e.message || 'Не удалось загрузить'
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ── Modal open ────────────────────────────────────────────────
function openCreate() {
  editingDiscount.value = null
  showModal.value = true
}

function openEdit(d: Discount) {
  editingDiscount.value = d
  showModal.value = true
}

function handleDiscountSaved(discount: Discount, mode: 'create' | 'edit') {
  // "Скидка" или "Промокод" — по тому, что реально сохранили, а не всегда
  // "Промокод": у скидки без кода (auto-apply — большинство случаев) такое
  // сообщение сбивало с толку (баг найден 2026-08-22 — изменили именно
  // размер скидки, но подсказка про промокод, хотя код никто не трогал).
  const label = discount.code ? 'Промокод' : 'Скидка'
  if (mode === 'create') {
    discounts.value.unshift(discount)
    toast.success(`${label} создан${discount.code ? '' : 'а'}`)
  } else {
    const idx = discounts.value.findIndex(d => d.id === discount.id)
    if (idx !== -1) discounts.value[idx] = discount
    toast.success(`${label} обновлен${discount.code ? '' : 'а'}`)
  }
}

// ── Toggle active ─────────────────────────────────────────────
async function toggleActive(d: Discount) {
  try {
    const res = await api.updateDiscount(d.id, { is_active: !d.is_active })
    const idx = discounts.value.findIndex(x => x.id === d.id)
    if (idx !== -1) discounts.value[idx] = res.data
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось изменить статус промокода')
  }
}

// ── Delete ────────────────────────────────────────────────────
async function doDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await api.deleteDiscount(deleteTarget.value.id)
    discounts.value = discounts.value.filter(d => d.id !== deleteTarget.value!.id)
    deleteTarget.value = null
  } catch (e: any) {
    error.value = e.message || 'Ошибка удаления'
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="space-y-6">

    <!-- Header -->
    <PageHeader
      title="Скидки и промокоды"
      :subtitle="`${discounts.length} ${discounts.length === 1 ? 'акция' : discounts.length < 5 ? 'акции' : 'акций'}, ${activeCount} активных`"
    >
      <button @click="showGuide = true" class="btn-ghost shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="hidden sm:inline">Как это работает?</span>
      </button>
      <button @click="openCreate" class="btn-primary shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Создать скидку</span>
      </button>
    </PageHeader>

    <!-- Filters -->
    <div class="flex flex-col sm:flex-row gap-3">
      <div class="relative flex-1">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input v-model="filterSearch" type="text" class="input pl-9" placeholder="Поиск по названию или промокоду" />
      </div>
      <CustomSelect v-model="filterType" :options="typeOptions" class="sm:w-44" />
      <CustomSelect v-model="filterStatus" :options="statusOptions" class="sm:w-44" />
    </div>

    <!-- Error -->
    <div v-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="card flex items-center justify-center py-16">
      <UiSpinner />
    </div>

    <!-- Empty -->
    <UiEmptyState
      v-else-if="discounts.length === 0"
      title="Нет скидок"
      description="Создайте первую скидку или промокод для клиентов"
    >
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
        </svg>
      </template>
      <button @click="openCreate" class="btn-primary">Создать скидку</button>
    </UiEmptyState>

    <!-- Empty filter -->
    <div v-else-if="filteredDiscounts.length === 0" class="card text-center py-10">
      <p class="text-gray-500 dark:text-gray-400">Нет скидок по выбранным фильтрам</p>
      <button @click="filterSearch = ''; filterType = 'all'; filterStatus = 'all'" class="btn-ghost btn-sm mt-3">Сбросить</button>
    </div>

    <!-- Table -->
    <div v-else class="card overflow-x-auto p-0">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-gray-100 dark:border-gray-700">
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Название</th>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Скидка</th>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Промокод</th>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden md:table-cell">Применение</th>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Период</th>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider hidden lg:table-cell">Использований</th>
            <th class="text-center px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Активна</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
          <tr
            v-for="d in filteredDiscounts"
            :key="d.id"
            class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
            :class="{ 'opacity-50': !d.is_active || isExpired(d) }"
          >
            <td class="px-4 py-3">
              <div class="font-medium text-gray-900 dark:text-white">{{ d.name }}</div>
              <div v-if="d.max_discount_amount" class="text-xs text-gray-400 mt-0.5">
                Макс. скидка {{ rub(d.max_discount_amount) }}
              </div>
            </td>
            <td class="px-4 py-3">
              <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ formatValue(d) }}</span>
              <div v-if="d.min_order_amount > 0" class="text-xs text-gray-400 mt-0.5">
                от {{ rub(d.min_order_amount) }}
              </div>
            </td>
            <td class="px-4 py-3">
              <span v-if="d.code" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 tracking-wider">
                {{ d.code }}
              </span>
              <span v-else class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400">
                Авто
              </span>
            </td>
            <td class="px-4 py-3 hidden md:table-cell text-gray-600 dark:text-gray-400">
              {{ scopeLabel(d.scope) }}
              <span v-if="d.scope_value" class="block text-xs text-gray-400 truncate max-w-[120px]">
                {{ d.scope === 'product'
                    ? (products.find(p => p.id === d.scope_value)?.name ?? d.scope_value)
                    : (categoriesStore.getCategoryName(d.scope_value) || d.scope_value) }}
              </span>
            </td>
            <td class="px-4 py-3 hidden lg:table-cell text-gray-600 dark:text-gray-400">
              <span :class="isExpired(d) ? 'text-red-500' : ''">{{ formatPeriod(d) }}</span>
            </td>
            <td class="px-4 py-3 hidden lg:table-cell text-gray-600 dark:text-gray-400 font-mono text-xs">
              {{ formatUsage(d) }}
            </td>
            <td class="px-4 py-3 text-center">
              <button
                @click="toggleActive(d)"
                :class="[
                  'relative w-10 h-5 rounded-full transition-colors',
                  d.is_active ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600'
                ]"
              >
                <span :class="['absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', d.is_active ? 'translate-x-5' : '']" />
              </button>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2 justify-end">
                <button @click="openEdit(d)" class="btn-ghost btn-sm text-xs">Изменить</button>
                <button @click="deleteTarget = d" class="text-gray-400 hover:text-red-500 transition-colors p-1">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <DiscountFormModal v-model="showModal" :discount="editingDiscount" :products="products" @saved="handleDiscountSaved" />
    <DiscountGuideModal v-model="showGuide" />

    <!-- ── Delete Confirm ─────────────────────────────────────── -->
    <UiConfirmDialog
      :modelValue="!!deleteTarget"
      @update:modelValue="!$event && (deleteTarget = null)"
      title="Удалить скидку?"
      :loading="deleting"
      @confirm="doDelete"
    >
      Акция <strong>{{ deleteTarget?.name }}</strong> будет удалена. История использований сохранится в заказах.
    </UiConfirmDialog>

  </div>
</template>
