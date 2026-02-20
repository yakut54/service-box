<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { api } from '@/lib/api'

type DiscountType  = 'percent' | 'fixed'
type DiscountScope = 'cart' | 'product' | 'category'

interface Discount {
  id: string
  name: string
  type: DiscountType
  value: number
  code: string | null
  scope: DiscountScope
  scope_value: string | null
  min_order_amount: number
  max_discount_amount: number | null
  usage_limit: number | null
  usage_count: number
  per_user_limit: number
  is_active: boolean
  starts_at: string | null
  ends_at: string | null
  created_at: string | null
}

// ── State ────────────────────────────────────────────────────
const discounts = ref<Discount[]>([])
const loading   = ref(false)
const error     = ref('')

// ── Modal ────────────────────────────────────────────────────
const showModal  = ref(false)
const modalMode  = ref<'create' | 'edit'>('create')
const saving     = ref(false)
const modalError = ref('')
const editingId  = ref<string | null>(null)

// ── Delete ────────────────────────────────────────────────────
const deleteTarget = ref<Discount | null>(null)
const deleting     = ref(false)

// ── Filters ───────────────────────────────────────────────────
const filterSearch = ref('')
const filterType   = ref('all')
const filterStatus = ref('all')

// ── Form (human-friendly units: rubles, not kopecks) ─────────
const emptyForm = () => ({
  name:               '',
  type:               'percent' as DiscountType,
  value:              10,      // % or ₽
  code:               '',      // empty string = auto-apply (no code)
  scope:              'cart' as DiscountScope,
  scope_value:        '',
  min_order_amount:   0,       // ₽
  max_discount_amount: '',     // ₽ or '' = no cap
  usage_limit:        '',      // '' = unlimited
  per_user_limit:     1,
  is_active:          true,
  starts_at:          '',
  ends_at:            '',
})

const form = ref(emptyForm())

// ── Helpers ───────────────────────────────────────────────────
function rub(kopecks: number | null): string {
  if (kopecks === null) return '—'
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency', currency: 'RUB', minimumFractionDigits: 0,
  }).format(kopecks / 100)
}

function formatValue(d: Discount): string {
  return d.type === 'percent'
    ? `−${d.value}%`
    : `−${rub(d.value)}`
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
    const res = await api.getDiscounts()
    discounts.value = res.data
  } catch (e: any) {
    error.value = e.message || 'Не удалось загрузить'
  } finally {
    loading.value = false
  }
}

onMounted(load)

// ── Modal open ────────────────────────────────────────────────
function openCreate() {
  modalMode.value  = 'create'
  form.value       = emptyForm()
  editingId.value  = null
  modalError.value = ''
  showModal.value  = true
}

function openEdit(d: Discount) {
  modalMode.value  = 'edit'
  editingId.value  = d.id
  form.value = {
    name:               d.name,
    type:               d.type,
    value:              d.type === 'percent' ? d.value : d.value / 100,
    code:               d.code || '',
    scope:              d.scope,
    scope_value:        d.scope_value || '',
    min_order_amount:   d.min_order_amount / 100,
    max_discount_amount: d.max_discount_amount !== null ? String(d.max_discount_amount / 100) : '',
    usage_limit:        d.usage_limit !== null ? String(d.usage_limit) : '',
    per_user_limit:     d.per_user_limit,
    is_active:          d.is_active,
    starts_at:          d.starts_at ? d.starts_at.slice(0, 10) : '',
    ends_at:            d.ends_at   ? d.ends_at.slice(0, 10)   : '',
  }
  modalError.value = ''
  showModal.value  = true
}

// ── Build API payload (kopecks for money) ─────────────────────
function buildPayload() {
  const f = form.value
  return {
    name:               f.name.trim(),
    type:               f.type,
    value:              f.type === 'percent'
                          ? Number(f.value)
                          : Math.round(Number(f.value) * 100),
    code:               f.code.trim().toUpperCase() || null,
    scope:              f.scope,
    scope_value:        f.scope_value.trim() || null,
    min_order_amount:   Math.round(Number(f.min_order_amount) * 100),
    max_discount_amount: f.max_discount_amount !== ''
                          ? Math.round(Number(f.max_discount_amount) * 100)
                          : null,
    usage_limit:        f.usage_limit !== '' ? Number(f.usage_limit) : null,
    per_user_limit:     Number(f.per_user_limit),
    is_active:          f.is_active,
    starts_at:          f.starts_at || null,
    ends_at:            f.ends_at   || null,
  }
}

// ── Save ──────────────────────────────────────────────────────
async function save() {
  if (!form.value.name.trim()) {
    modalError.value = 'Укажите название'
    return
  }
  if (!form.value.value || Number(form.value.value) < 1) {
    modalError.value = 'Укажите значение скидки'
    return
  }
  if (form.value.type === 'percent' && Number(form.value.value) > 100) {
    modalError.value = 'Процент не может превышать 100'
    return
  }

  saving.value     = true
  modalError.value = ''

  try {
    const payload = buildPayload()
    if (modalMode.value === 'create') {
      const res = await api.createDiscount(payload)
      discounts.value.unshift(res.data)
    } else {
      const res = await api.updateDiscount(editingId.value!, payload)
      const idx = discounts.value.findIndex(d => d.id === editingId.value)
      if (idx !== -1) discounts.value[idx] = res.data
    }
    showModal.value = false
  } catch (e: any) {
    modalError.value = e.message || 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}

// ── Toggle active ─────────────────────────────────────────────
async function toggleActive(d: Discount) {
  try {
    const res = await api.updateDiscount(d.id, { is_active: !d.is_active })
    const idx = discounts.value.findIndex(x => x.id === d.id)
    if (idx !== -1) discounts.value[idx] = res.data
  } catch { /* silent */ }
}

// ── Delete ────────────────────────────────────────────────────
function confirmDelete(d: Discount) { deleteTarget.value = d }

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
    <div class="flex items-center justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Скидки и промокоды</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          {{ discounts.length }} {{ discounts.length === 1 ? 'акция' : discounts.length < 5 ? 'акции' : 'акций' }},
          {{ activeCount }} активных
        </p>
      </div>
      <button @click="openCreate" class="btn-primary shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Создать скидку</span>
      </button>
    </div>

    <!-- Filters -->
    <div class="flex flex-col sm:flex-row gap-3">
      <div class="relative flex-1">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input v-model="filterSearch" type="text" class="input pl-9" placeholder="Поиск по названию или промокоду" />
      </div>
      <select v-model="filterType" class="input sm:w-44">
        <option value="all">Все типы</option>
        <option value="percent">Процент</option>
        <option value="fixed">Фиксированная</option>
      </select>
      <select v-model="filterStatus" class="input sm:w-44">
        <option value="all">Все статусы</option>
        <option value="active">Активные</option>
        <option value="inactive">Неактивные</option>
      </select>
    </div>

    <!-- Error -->
    <div v-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="card flex items-center justify-center py-16">
      <div class="animate-spin w-7 h-7 border-4 border-primary-600 border-t-transparent rounded-full"></div>
    </div>

    <!-- Empty (no discounts) -->
    <div v-else-if="discounts.length === 0" class="card text-center py-16">
      <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
        </svg>
      </div>
      <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Нет скидок</h3>
      <p class="text-gray-500 dark:text-gray-400 mb-6">Создайте первую скидку или промокод для клиентов</p>
      <button @click="openCreate" class="btn-primary">Создать скидку</button>
    </div>

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
            <!-- Name -->
            <td class="px-4 py-3">
              <div class="font-medium text-gray-900 dark:text-white">{{ d.name }}</div>
              <div v-if="d.max_discount_amount" class="text-xs text-gray-400 mt-0.5">
                Макс. скидка {{ rub(d.max_discount_amount) }}
              </div>
            </td>

            <!-- Value -->
            <td class="px-4 py-3">
              <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ formatValue(d) }}</span>
              <div v-if="d.min_order_amount > 0" class="text-xs text-gray-400 mt-0.5">
                от {{ rub(d.min_order_amount) }}
              </div>
            </td>

            <!-- Code -->
            <td class="px-4 py-3">
              <span v-if="d.code" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 tracking-wider">
                {{ d.code }}
              </span>
              <span v-else class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400">
                Авто
              </span>
            </td>

            <!-- Scope -->
            <td class="px-4 py-3 hidden md:table-cell text-gray-600 dark:text-gray-400">
              {{ scopeLabel(d.scope) }}
              <span v-if="d.scope_value" class="block text-xs text-gray-400 truncate max-w-[120px]">
                {{ d.scope_value }}
              </span>
            </td>

            <!-- Period -->
            <td class="px-4 py-3 hidden lg:table-cell text-gray-600 dark:text-gray-400">
              <span :class="isExpired(d) ? 'text-red-500' : ''">{{ formatPeriod(d) }}</span>
            </td>

            <!-- Usage -->
            <td class="px-4 py-3 hidden lg:table-cell text-gray-600 dark:text-gray-400 font-mono text-xs">
              {{ formatUsage(d) }}
            </td>

            <!-- Active toggle -->
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

            <!-- Actions -->
            <td class="px-4 py-3">
              <div class="flex items-center gap-2 justify-end">
                <button @click="openEdit(d)" class="btn-ghost btn-sm text-xs">
                  Изменить
                </button>
                <button @click="confirmDelete(d)" class="text-gray-400 hover:text-red-500 transition-colors p-1">
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

    <!-- ── Create / Edit Modal ────────────────────────────────── -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="showModal = false" />
      <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">

        <!-- Modal header -->
        <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
            {{ modalMode === 'create' ? 'Новая скидка' : 'Редактировать скидку' }}
          </h2>
          <button @click="showModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Modal body -->
        <div class="p-4 space-y-4">
          <div v-if="modalError" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
            {{ modalError }}
          </div>

          <!-- Name -->
          <div>
            <label class="label">Название <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="input" placeholder="Летняя акция, Скидка на первый заказ..." />
          </div>

          <!-- Type toggle -->
          <div>
            <label class="label">Тип скидки <span class="text-red-500">*</span></label>
            <div class="flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
              <button
                type="button"
                :class="['flex-1 py-2 text-sm font-medium transition-colors', form.type === 'percent' ? 'bg-primary-600 text-white' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700']"
                @click="form.type = 'percent'"
              >
                Процент (%)
              </button>
              <button
                type="button"
                :class="['flex-1 py-2 text-sm font-medium transition-colors', form.type === 'fixed' ? 'bg-primary-600 text-white' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700']"
                @click="form.type = 'fixed'"
              >
                Фиксированная (₽)
              </button>
            </div>
          </div>

          <!-- Value -->
          <div>
            <label class="label">Размер скидки <span class="text-red-500">*</span></label>
            <div class="relative">
              <input
                v-model.number="form.value"
                type="number"
                :min="1"
                :max="form.type === 'percent' ? 100 : undefined"
                class="input pr-12"
                :placeholder="form.type === 'percent' ? '10' : '500'"
              />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm font-medium">
                {{ form.type === 'percent' ? '%' : '₽' }}
              </span>
            </div>
            <p v-if="form.type === 'percent'" class="text-xs text-gray-400 mt-1">От 1 до 100%</p>
          </div>

          <!-- Code -->
          <div>
            <label class="label">Промокод</label>
            <input
              v-model="form.code"
              type="text"
              class="input font-mono uppercase"
              placeholder="SUMMER20 (оставьте пустым — авто-применение)"
              maxlength="50"
            />
            <p class="text-xs text-gray-400 mt-1">
              {{ form.code.trim() ? 'Клиент вводит код вручную при оформлении заказа' : 'Скидка применится автоматически при выполнении условий' }}
            </p>
          </div>

          <!-- Scope -->
          <div>
            <label class="label">Применять к</label>
            <select v-model="form.scope" class="input">
              <option value="cart">Всей корзине</option>
              <option value="product">Конкретному товару</option>
              <option value="category">Категории товаров</option>
            </select>
          </div>

          <!-- Scope value -->
          <div v-if="form.scope !== 'cart'">
            <label class="label">{{ form.scope === 'product' ? 'ID товара' : 'Название категории' }}</label>
            <input
              v-model="form.scope_value"
              type="text"
              class="input"
              :placeholder="form.scope === 'product' ? 'UUID товара' : 'Например: Масла и жидкости'"
            />
          </div>

          <!-- Min order -->
          <div>
            <label class="label">Минимальная сумма заказа, ₽</label>
            <input v-model.number="form.min_order_amount" type="number" min="0" class="input" placeholder="0 (без ограничений)" />
          </div>

          <!-- Max discount -->
          <div>
            <label class="label">Максимальная скидка, ₽</label>
            <input
              v-model="form.max_discount_amount"
              type="number"
              min="1"
              class="input"
              placeholder="Оставьте пустым — без ограничения"
            />
            <p class="text-xs text-gray-400 mt-1">Защита маржи: скидка не превысит это значение в ₽</p>
          </div>

          <!-- Usage limit -->
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Лимит использований</label>
              <input
                v-model="form.usage_limit"
                type="number"
                min="1"
                class="input"
                placeholder="∞"
              />
            </div>
            <div>
              <label class="label">Лимит на 1 клиента</label>
              <input v-model.number="form.per_user_limit" type="number" min="0" class="input" placeholder="1" />
              <p class="text-xs text-gray-400 mt-1">0 = без ограничений</p>
            </div>
          </div>

          <!-- Period -->
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">Дата начала</label>
              <input v-model="form.starts_at" type="date" class="input" />
            </div>
            <div>
              <label class="label">Дата окончания</label>
              <input v-model="form.ends_at" type="date" class="input" />
            </div>
          </div>

          <!-- Active -->
          <div class="flex items-center justify-between py-2">
            <div>
              <span class="label mb-0">Активна</span>
              <p class="text-xs text-gray-400">Скидка применяется при оформлении заказов</p>
            </div>
            <button
              type="button"
              @click="form.is_active = !form.is_active"
              :class="['relative w-11 h-6 rounded-full transition-colors', form.is_active ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600']"
            >
              <span :class="['absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition-transform', form.is_active ? 'translate-x-5' : '']" />
            </button>
          </div>
        </div>

        <!-- Modal footer -->
        <div class="flex gap-3 p-4 pt-0">
          <button @click="showModal = false" class="btn-secondary flex-1">Отмена</button>
          <button @click="save" class="btn-primary flex-1" :disabled="saving">
            {{ saving ? 'Сохранение...' : (modalMode === 'create' ? 'Создать' : 'Сохранить') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Delete Confirm ─────────────────────────────────────── -->
    <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="deleteTarget = null" />
      <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-sm p-6 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Удалить скидку?</h2>
        <p class="text-gray-600 dark:text-gray-400">
          Акция <strong>{{ deleteTarget.name }}</strong> будет удалена. История использований сохранится в заказах.
        </p>
        <div class="flex gap-3">
          <button @click="deleteTarget = null" class="btn-secondary flex-1">Отмена</button>
          <button @click="doDelete" class="btn-danger flex-1" :disabled="deleting">
            {{ deleting ? 'Удаление...' : 'Удалить' }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>
