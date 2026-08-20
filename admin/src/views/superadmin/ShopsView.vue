<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api } from '@/lib/api'
import { ApiError } from '@/lib/api'
import type { SuperadminShop } from '@/types'

const shops = ref<SuperadminShop[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')
const currentPage = ref(1)
const totalPages = ref(1)

async function load() {
  loading.value = true
  error.value = null
  try {
    const params: Record<string, string> = { page: String(currentPage.value) }
    if (search.value) params.search = search.value
    const res = await api.superadminGetShops(params)
    shops.value = res.data
    totalPages.value = Math.ceil((res.total || 0) / (res.per_page || 25)) || 1
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
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
    </div>

    <!-- Error -->
    <div v-if="error" class="p-4 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-lg text-sm">
      {{ error }}
    </div>

    <!-- Loader -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- Desktop: table -->
      <div class="card overflow-hidden hidden md:block">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-gray-200 dark:border-gray-700">
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Магазин</th>
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Владелец</th>
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Создан</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-if="shops.length === 0">
              <td colspan="3" class="py-12 text-center text-gray-400">Магазины не найдены</td>
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
              <td class="py-3 px-4 text-gray-400 text-xs">{{ formatDate(shop.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile: cards -->
      <div class="flex flex-col gap-3 md:hidden">
        <p v-if="shops.length === 0" class="text-center text-gray-400 py-12">Магазины не найдены</p>
        <div
          v-for="shop in shops"
          :key="shop.id"
          class="card p-4 flex flex-col gap-3"
        >
          <div>
            <div class="font-semibold text-gray-900 dark:text-white">{{ shop.name }}</div>
            <div v-if="shop.domain" class="text-xs text-gray-400">{{ shop.domain }}</div>
          </div>

          <div class="text-sm text-gray-600 dark:text-gray-400">
            <div>{{ shop.user?.name || '—' }}</div>
            <div class="text-xs text-gray-400">{{ shop.user?.email }}</div>
          </div>

          <div class="text-xs text-gray-400">Создан: {{ formatDate(shop.created_at) }}</div>
        </div>
      </div>
    </template>

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
  </div>
</template>
