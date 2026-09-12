<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatPrice, formatDate } from '@/shared/lib/format'
import { plural } from '@/lib/utils'
import PageHeader from '@/components/PageHeader.vue'
import CustomSelect from '@/components/CustomSelect.vue'
import { timezoneOptions } from '@/shared/lib/timezones'
import { UiModal, UiEmptyState, UiSpinner } from '@/shared/ui'
import type { ChainShop } from '@/types'

const authStore = useAuthStore()
const toast = useToast()

const shops   = ref<ChainShop[]>([])
const loading = ref(true)
const error   = ref('')

const showModal = ref(false)
const saving    = ref(false)
const formError = ref('')
const name      = ref('')
const domain    = ref('')
const timezone  = ref('')

const enteringId = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.chainGetShops()
    shops.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  name.value = ''
  domain.value = ''
  timezone.value = ''
  formError.value = ''
  showModal.value = true
}

async function createShop() {
  if (!name.value.trim()) {
    formError.value = 'Укажите название точки'
    return
  }
  saving.value = true
  formError.value = ''
  try {
    const res = await api.chainCreateShop({
      name: name.value.trim(),
      domain: domain.value.trim() || null,
      timezone: timezone.value || null,
    })
    shops.value.push({
      ...res.data,
      revenue_30d_kopecks: 0,
      orders_30d: 0,
      staff_count: 0,
    })
    showModal.value = false
    toast.success('Точка создана')
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Не удалось создать точку'
  } finally {
    saving.value = false
  }
}

async function enter(shop: ChainShop) {
  enteringId.value = shop.id
  try {
    await authStore.enterShop(shop.id)
  } finally {
    enteringId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Мои точки" subtitle="Магазины вашей сети — зайдите в любую, чтобы управлять ей">
      <button @click="openCreate" class="btn-primary shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Добавить точку</span>
      </button>
    </PageHeader>

    <div v-if="loading" class="card flex items-center justify-center py-16">
      <UiSpinner />
    </div>

    <div v-else-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <UiEmptyState
      v-else-if="shops.length === 0"
      title="Пока нет ни одной точки"
      description="Добавьте первую точку — дальше всё как в обычной админке"
    >
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        </svg>
      </template>
      <button @click="openCreate" class="btn-primary">Добавить точку</button>
    </UiEmptyState>

    <div v-else class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
      <div v-for="s in shops" :key="s.id" class="card flex flex-col gap-4">
        <div class="min-w-0">
          <p class="font-semibold text-gray-900 dark:text-white truncate">{{ s.name }}</p>
          <p v-if="s.domain" class="text-xs text-gray-400 truncate">{{ s.domain }}</p>
          <p class="text-xs text-gray-400 mt-0.5">Создана {{ formatDate(s.created_at) }}</p>
        </div>

        <div class="grid grid-cols-2 gap-3 text-sm">
          <div>
            <p class="text-gray-400 text-xs">Оборот за 30 дней</p>
            <p class="font-semibold text-gray-900 dark:text-white tabular-nums">{{ formatPrice(s.revenue_30d_kopecks) }}</p>
          </div>
          <div>
            <p class="text-gray-400 text-xs">Заказы за 30 дней</p>
            <p class="font-semibold text-gray-900 dark:text-white tabular-nums">{{ s.orders_30d }}</p>
          </div>
        </div>

        <p class="text-xs text-gray-400">
          {{ s.staff_count }} {{ plural(s.staff_count, 'сотрудник', 'сотрудника', 'сотрудников') }}
        </p>

        <button
          @click="enter(s)"
          :disabled="enteringId === s.id"
          class="btn-secondary btn-sm mt-auto"
        >
          <UiSpinner v-if="enteringId === s.id" class="w-4 h-4" />
          <span v-else>Войти →</span>
        </button>
      </div>
    </div>

    <!-- Создание точки -->
    <UiModal v-model="showModal" max-width="max-w-md">
      <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Новая точка</h2>
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
          <p class="label">Название <span class="text-red-500">*</span></p>
          <input v-model="name" type="text" class="input" placeholder="Точка на Ленина" @keydown.enter="createShop" />
        </div>

        <div>
          <p class="label">Сайт <span class="text-gray-400 font-normal">(необязательно)</span></p>
          <input v-model="domain" type="text" class="input" placeholder="https://example.com" />
        </div>

        <div>
          <p class="label">Часовой пояс <span class="text-gray-400 font-normal">(по умолчанию — как в первой точке)</span></p>
          <CustomSelect v-model="timezone" :options="timezoneOptions" placeholder="Выберите часовой пояс" searchable />
        </div>
      </div>

      <div class="flex justify-end gap-3 px-4 py-3 border-t border-gray-100 dark:border-gray-700">
        <button @click="showModal = false" class="btn-secondary" :disabled="saving">Отмена</button>
        <button @click="createShop" class="btn-primary" :disabled="saving">
          {{ saving ? 'Создание...' : 'Создать' }}
        </button>
      </div>
    </UiModal>
  </div>
</template>
