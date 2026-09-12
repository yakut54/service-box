<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import { formatDate } from '@/shared/lib/format'
import { UiToggle, UiConfirmDialog } from '@/shared/ui'
import type { SuperadminOwner } from '@/types'

const owners  = ref<SuperadminOwner[]>([])
const loading = ref(true)
const error   = ref<string | null>(null)
const search  = ref('')
const currentPage = ref(1)
const totalPages  = ref(1)

const togglingId = ref<string | null>(null)
// Выключение флага у владельца с несколькими точками — не блокируем на
// бэкенде (см. SuperadminOwnerController), но предупреждаем на фронте:
// панель сети у него исчезнет, магазины останутся, он попадёт в
// самую старую точку как обычный владелец (App\Support\ShopAccess).
const confirmTarget = ref<SuperadminOwner | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const params: Record<string, string> = { page: String(currentPage.value) }
    if (search.value) params.search = search.value
    const res = await api.superadminGetOwners(params)
    owners.value = res.data
    totalPages.value = Math.ceil((res.total || 0) / (res.per_page || 25)) || 1
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

function onToggle(owner: SuperadminOwner, next: boolean) {
  if (!next && owner.shops_count > 1) {
    confirmTarget.value = owner
    return
  }
  applyToggle(owner, next)
}

async function applyToggle(owner: SuperadminOwner, next: boolean) {
  togglingId.value = owner.id
  const prev = owner.is_chain_owner
  owner.is_chain_owner = next // оптимистично
  try {
    await api.superadminToggleChainOwner(owner.id, next)
  } catch (e) {
    owner.is_chain_owner = prev // откат
    error.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    togglingId.value = null
  }
}

function confirmDisable() {
  if (!confirmTarget.value) return
  const owner = confirmTarget.value
  confirmTarget.value = null
  applyToggle(owner, false)
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
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Владельцы</h1>
      <span class="text-sm text-gray-500 dark:text-gray-400">Всего: {{ owners.length }}</span>
    </div>

    <div class="flex gap-3 flex-wrap">
      <input
        v-model="search"
        @input="onSearch"
        type="text"
        placeholder="Поиск по имени или email..."
        class="input flex-1 min-w-[200px]"
      />
    </div>

    <div v-if="error" class="p-4 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-lg text-sm">
      {{ error }}
    </div>

    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else>
      <!-- Desktop -->
      <div class="card overflow-hidden hidden md:block">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-gray-200 dark:border-gray-700">
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Владелец</th>
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Точек</th>
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Регистрация</th>
              <th class="text-right py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Владелец сети</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-if="owners.length === 0">
              <td colspan="4" class="py-12 text-center text-gray-400">Никого не найдено</td>
            </tr>
            <tr v-for="owner in owners" :key="owner.id" class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
              <td class="py-3 px-4">
                <div class="font-medium text-gray-900 dark:text-white">{{ owner.name }}</div>
                <div class="text-xs text-gray-400">{{ owner.email }}</div>
              </td>
              <td class="py-3 px-4 text-gray-600 dark:text-gray-400">{{ owner.shops_count }}</td>
              <td class="py-3 px-4 text-gray-400 text-xs">{{ formatDate(owner.created_at) }}</td>
              <td class="py-3 px-4 text-right">
                <UiToggle
                  :model-value="owner.is_chain_owner"
                  :loading="togglingId === owner.id"
                  @update:model-value="(n: boolean) => onToggle(owner, n)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile -->
      <div class="flex flex-col gap-3 md:hidden">
        <p v-if="owners.length === 0" class="text-center text-gray-400 py-12">Никого не найдено</p>
        <div v-for="owner in owners" :key="owner.id" class="card p-4 flex items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="font-semibold text-gray-900 dark:text-white truncate">{{ owner.name }}</div>
            <div class="text-xs text-gray-400 truncate">{{ owner.email }}</div>
            <div class="text-xs text-gray-400 mt-0.5">{{ owner.shops_count }} точек · {{ formatDate(owner.created_at) }}</div>
          </div>
          <UiToggle
            :model-value="owner.is_chain_owner"
            :loading="togglingId === owner.id"
            @update:model-value="(n: boolean) => onToggle(owner, n)"
          />
        </div>
      </div>
    </template>

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

    <UiConfirmDialog
      :model-value="!!confirmTarget"
      @update:model-value="!$event && (confirmTarget = null)"
      title="Выключить владельца сети?"
      confirm-label="Выключить"
      :danger="false"
      @confirm="confirmDisable"
    >
      У <strong>{{ confirmTarget?.name }}</strong> сейчас {{ confirmTarget?.shops_count }} точек.
      После выключения панель сети станет недоступна, он попадёт в свою самую
      старую точку как обычный владелец — остальные точки останутся в базе,
      но управлять ими будет негде, пока флаг не включат обратно.
    </UiConfirmDialog>
  </div>
</template>
