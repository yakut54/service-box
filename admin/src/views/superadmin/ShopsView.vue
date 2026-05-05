<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api } from '@/lib/api'
import { ApiError } from '@/lib/api'
import type { SuperadminShop } from '@/types'
import CustomSelect from '@/components/CustomSelect.vue'

const shops = ref<SuperadminShop[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')
const filterPlan = ref('')
const currentPage = ref(1)
const totalPages = ref(1)

// Edit plan modal
const editShop = ref<SuperadminShop | null>(null)
const newPlan = ref('')
const saving = ref(false)
const saveError = ref<string | null>(null)

const planOptions = [
  { value: '', label: 'Все тарифы' },
  { value: 'micro', label: 'Micro' },
  { value: 'start', label: 'Start' },
  { value: 'business', label: 'Business' },
  { value: 'pro', label: 'Pro' },
]

const planEditOptions = planOptions.slice(1) // без "Все тарифы"

const planBadge: Record<string, string> = {
  micro:    'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
  start:    'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
  business: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
  pro:      'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const params: Record<string, string> = { page: String(currentPage.value) }
    if (search.value) params.search = search.value
    if (filterPlan.value) params.plan = filterPlan.value
    const res = await api.superadminGetShops(params)
    shops.value = res.data
    totalPages.value = Math.ceil((res.total || 0) / (res.per_page || 25)) || 1
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

function openEdit(shop: SuperadminShop) {
  editShop.value = shop
  newPlan.value = shop.plan || 'micro'
  saveError.value = null
}

function closeEdit() {
  editShop.value = null
}

async function savePlan() {
  if (!editShop.value) return
  saving.value = true
  saveError.value = null
  try {
    const res = await api.superadminUpdatePlan(editShop.value.id, { plan: newPlan.value })
    // update in list
    const idx = shops.value.findIndex(s => s.id === editShop.value!.id)
    if (idx !== -1) shops.value[idx] = { ...shops.value[idx], plan: res.shop.plan }
    closeEdit()
  } catch (e) {
    saveError.value = e instanceof ApiError ? e.message : 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}

function formatDate(d: string | null) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('ru-RU')
}

let searchTimer: ReturnType<typeof setTimeout> | null = null
function onSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { currentPage.value = 1; load() }, 400)
}

function onFilterPlan() {
  currentPage.value = 1
  load()
}

onMounted(load)
</script>

<template>
  <div class="flex flex-col gap-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Магазины платформы</h1>
      <span class="text-sm text-gray-500 dark:text-gray-400">Всего: {{ shops.length }}</span>
    </div>

    <!-- Filters -->
    <div class="flex gap-3 flex-wrap">
      <input
        v-model="search"
        @input="onSearch"
        type="text"
        placeholder="Поиск по названию или домену..."
        class="input flex-1 min-w-[200px]"
      />
      <div class="w-48">
        <CustomSelect
          v-model="filterPlan"
          :options="planOptions"
          placeholder="Все тарифы"
          @update:modelValue="onFilterPlan"
        />
      </div>
    </div>

    <!-- Error -->
    <div v-if="error" class="p-4 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-lg text-sm">
      {{ error }}
    </div>

    <!-- Table -->
    <div class="card overflow-hidden">
      <div v-if="loading" class="flex items-center justify-center py-16">
        <div class="w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
      </div>

      <table v-else class="w-full text-sm">
        <thead>
          <tr class="border-b border-gray-200 dark:border-gray-700">
            <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Магазин</th>
            <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Владелец</th>
            <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Тариф</th>
            <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Подписка до</th>
            <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Создан</th>
            <th class="py-3 px-4"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <tr v-if="shops.length === 0">
            <td colspan="6" class="py-12 text-center text-gray-400">Магазины не найдены</td>
          </tr>
          <tr v-for="shop in shops" :key="shop.id" class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
            <td class="py-3 px-4">
              <div class="font-medium text-gray-900 dark:text-white">{{ shop.name }}</div>
              <div v-if="shop.domain" class="text-xs text-gray-400">{{ shop.domain }}</div>
            </td>
            <td class="py-3 px-4 text-gray-600 dark:text-gray-400">
              <div>{{ shop.user?.name || '—' }}</div>
              <div class="text-xs text-gray-400">{{ shop.user?.email }}</div>
            </td>
            <td class="py-3 px-4">
              <span
                v-if="shop.plan"
                :class="['px-2 py-0.5 rounded text-xs font-medium', planBadge[shop.plan] || 'bg-gray-100 text-gray-600']"
              >
                {{ shop.plan }}
              </span>
              <span v-else class="text-gray-400 text-xs">—</span>
            </td>
            <td class="py-3 px-4 text-gray-600 dark:text-gray-400 text-xs">{{ formatDate(shop.subscription_ends_at) }}</td>
            <td class="py-3 px-4 text-gray-400 text-xs">{{ formatDate(shop.created_at) }}</td>
            <td class="py-3 px-4">
              <button @click="openEdit(shop)" class="btn-ghost text-xs py-1 px-2">Изменить тариф</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="totalPages > 1" class="flex items-center justify-center gap-2">
      <button
        @click="() => { currentPage--; load() }"
        :disabled="currentPage <= 1"
        class="btn-ghost text-sm px-3 py-1.5 disabled:opacity-40"
      >← Назад</button>
      <span class="text-sm text-gray-600 dark:text-gray-400">{{ currentPage }} / {{ totalPages }}</span>
      <button
        @click="() => { currentPage++; load() }"
        :disabled="currentPage >= totalPages"
        class="btn-ghost text-sm px-3 py-1.5 disabled:opacity-40"
      >Вперёд →</button>
    </div>

    <!-- Edit plan modal -->
    <div v-if="editShop" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="closeEdit">
      <div class="bg-white dark:bg-gray-900 rounded-xl shadow-xl p-6 w-full max-w-sm">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Изменить тариф</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ editShop.name }}</p>

        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Тариф</label>
          <CustomSelect v-model="newPlan" :options="planEditOptions" placeholder="Выберите тариф" />
        </div>

        <div v-if="saveError" class="mb-3 text-sm text-red-600 dark:text-red-400">{{ saveError }}</div>

        <div class="flex gap-3">
          <button @click="closeEdit" class="btn-ghost flex-1">Отмена</button>
          <button @click="savePlan" :disabled="saving" class="btn-primary flex-1">
            {{ saving ? 'Сохранение...' : 'Сохранить' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
