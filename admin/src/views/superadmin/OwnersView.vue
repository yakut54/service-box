<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/shared/lib/format'
import CustomSelect from '@/components/CustomSelect.vue'
import { timezoneOptions } from '@/shared/lib/timezones'
import { UiModal } from '@/shared/ui'
import type { SuperadminOwner } from '@/types'

const toast = useToast()

const owners  = ref<SuperadminOwner[]>([])
const loading = ref(true)
const error   = ref<string | null>(null)
const search  = ref('')
const currentPage = ref(1)
const totalPages  = ref(1)

const showModal = ref(false)
const saving    = ref(false)
const formError = ref('')
const name      = ref('')
const email     = ref('')
const shopName  = ref('')
const shopDomain = ref('')
const timezone  = ref('')

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

function openCreate() {
  name.value = ''
  email.value = ''
  shopName.value = ''
  shopDomain.value = ''
  timezone.value = ''
  formError.value = ''
  showModal.value = true
}

async function createOwner() {
  if (!name.value.trim() || !email.value.trim() || !shopName.value.trim()) {
    formError.value = 'Заполните имя, email и название точки'
    return
  }
  saving.value = true
  formError.value = ''
  try {
    const owner = await api.superadminCreateOwner({
      name: name.value.trim(),
      email: email.value.trim(),
      shop_name: shopName.value.trim(),
      shop_domain: shopDomain.value.trim() || null,
      timezone: timezone.value || null,
    })
    owners.value.unshift(owner)
    showModal.value = false
    toast.success('Админ создан, ссылка на установку пароля отправлена на email')
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Не удалось создать админа'
  } finally {
    saving.value = false
  }
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
      <button @click="openCreate" class="btn-primary shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Создать админа</span>
      </button>
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
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Точка</th>
              <th class="text-left py-3 px-4 font-medium text-gray-500 dark:text-gray-400">Регистрация</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-if="owners.length === 0">
              <td colspan="3" class="py-12 text-center text-gray-400">Никого не найдено</td>
            </tr>
            <tr v-for="owner in owners" :key="owner.id" class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
              <td class="py-3 px-4">
                <div class="font-medium text-gray-900 dark:text-white">{{ owner.name }}</div>
                <div class="text-xs text-gray-400">{{ owner.email }}</div>
              </td>
              <td class="py-3 px-4 text-gray-600 dark:text-gray-400">{{ owner.shop_name || '—' }}</td>
              <td class="py-3 px-4 text-gray-400 text-xs">{{ formatDate(owner.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile -->
      <div class="flex flex-col gap-3 md:hidden">
        <p v-if="owners.length === 0" class="text-center text-gray-400 py-12">Никого не найдено</p>
        <div v-for="owner in owners" :key="owner.id" class="card p-4">
          <div class="min-w-0">
            <div class="font-semibold text-gray-900 dark:text-white truncate">{{ owner.name }}</div>
            <div class="text-xs text-gray-400 truncate">{{ owner.email }}</div>
            <div class="text-xs text-gray-400 mt-0.5">{{ owner.shop_name || '—' }} · {{ formatDate(owner.created_at) }}</div>
          </div>
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

    <!-- Создание админа -->
    <UiModal v-model="showModal" max-width="max-w-md">
      <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Новый админ</h2>
        <button @click="showModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <div class="p-4 space-y-4">
        <div v-if="formError" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
          {{ formError }}
        </div>

        <div>
          <p class="label">Имя <span class="text-red-500">*</span></p>
          <input v-model="name" type="text" class="input" placeholder="Как зовут админа" />
        </div>

        <div>
          <p class="label">Email <span class="text-red-500">*</span></p>
          <input v-model="email" type="email" class="input" placeholder="admin@example.com" />
        </div>

        <div>
          <p class="label">Название точки <span class="text-red-500">*</span></p>
          <input v-model="shopName" type="text" class="input" placeholder="Точка на Ленина" @keydown.enter="createOwner" />
        </div>

        <div>
          <p class="label">Сайт <span class="text-gray-400 font-normal">(необязательно)</span></p>
          <input v-model="shopDomain" type="text" class="input" placeholder="https://example.com" />
        </div>

        <div>
          <p class="label">Часовой пояс <span class="text-gray-400 font-normal">(необязательно)</span></p>
          <CustomSelect v-model="timezone" :options="timezoneOptions" placeholder="Выберите часовой пояс" searchable />
        </div>
      </div>

      <div class="flex justify-end gap-3 px-4 py-3 border-t border-gray-100 dark:border-gray-700">
        <button @click="showModal = false" class="btn-secondary" :disabled="saving">Отмена</button>
        <button @click="createOwner" class="btn-primary" :disabled="saving">
          {{ saving ? 'Создание...' : 'Создать' }}
        </button>
      </div>
    </UiModal>
  </div>
</template>
