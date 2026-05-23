<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api, ApiError } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import { plural } from '@/lib/utils'
import { useToast } from '@/composables/useToast'
import CustomSelect from '@/components/CustomSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import MasterFormModal from '@/components/modals/MasterFormModal.vue'
import { UiConfirmDialog, UiEmptyState, UiSpinner } from '@/shared/ui'
import type { Master } from '@/types'

// ── Invite master ────────────────────────────────────────────────
const inviteOpenId   = ref<string | null>(null)  // master.id с открытой формой
const inviteEmails   = ref<Record<string, string>>({})
const invitingId     = ref<string | null>(null)
const inviteErrors   = ref<Record<string, string>>({})

function openInviteForm(master: Master) {
  inviteOpenId.value = inviteOpenId.value === master.id ? null : master.id
  inviteEmails.value[master.id] = ''
  inviteErrors.value[master.id] = ''
}

async function sendMasterInvite(master: Master, emailOverride?: string) {
  const email = (emailOverride ?? inviteEmails.value[master.id] ?? '').trim()
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    inviteErrors.value[master.id] = 'Введите корректный email'
    return
  }
  invitingId.value = master.id
  inviteErrors.value[master.id] = ''
  try {
    const res = await api.inviteMaster(master.id, email)
    toast.success(res.message || 'Приглашение отправлено')
    inviteOpenId.value = null
  } catch (e) {
    inviteErrors.value[master.id] = e instanceof ApiError ? e.message : 'Ошибка отправки'
  } finally {
    invitingId.value = null
  }
}

const route = useRoute()

const masters = ref<Master[]>([])
const loading = ref(false)
const error = ref('')
const toast = useToast()

const showModal = ref(false)
const editingMaster = ref<Master | null>(null)

const deleteTarget = ref<Master | null>(null)
const deleting = ref(false)

const filterSearch = ref('')
const filterActive = ref('all')

const filterActiveOptions = [
  { value: 'all',      label: 'Все мастера' },
  { value: 'active',   label: 'Только активные' },
  { value: 'inactive', label: 'Только неактивные' },
]

const filteredMasters = computed(() => {
  let list = masters.value
  if (filterActive.value === 'active')   list = list.filter(m => m.is_active)
  if (filterActive.value === 'inactive') list = list.filter(m => !m.is_active)
  if (filterSearch.value.trim()) {
    const q = filterSearch.value.toLowerCase()
    list = list.filter(m => m.name.toLowerCase().includes(q) || (m.specialization || '').toLowerCase().includes(q))
  }
  return list
})

const activeCount = computed(() => masters.value.filter(m => m.is_active).length)

function sortMasters() {
  masters.value = [...masters.value].sort((a, b) => (a.sort_order ?? 999) - (b.sort_order ?? 999))
}

async function loadMasters() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getMasters()
    masters.value = res.data
    sortMasters()
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось загрузить мастеров')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadMasters()
  if (route.query.create === '1') openCreate()
})

function openCreate() {
  editingMaster.value = null
  showModal.value = true
}

function openEdit(master: Master) {
  editingMaster.value = master
  showModal.value = true
}

function handleMasterSaved(master: Master, mode: 'create' | 'edit') {
  if (mode === 'create') {
    masters.value.unshift(master)
  } else {
    const idx = masters.value.findIndex(m => m.id === master.id)
    if (idx !== -1) masters.value[idx] = master
    sortMasters()
  }
}

async function toggleActive(master: Master) {
  try {
    const res = await api.updateMaster(master.id, { is_active: !master.is_active })
    const idx = masters.value.findIndex(m => m.id === master.id)
    if (idx !== -1) masters.value[idx] = res.data
  } catch (e: unknown) {
    const msg = e instanceof ApiError ? e.message : 'Не удалось изменить статус мастера'
    toast.error(msg)
  }
}

function confirmDelete(master: Master) {
  deleteTarget.value = master
}

async function doDelete() {
  if (!deleteTarget.value) return
  const targetId = deleteTarget.value.id
  deleting.value = true
  try {
    await api.deleteMaster(targetId)
    masters.value = masters.value.filter(m => m.id !== targetId)
    deleteTarget.value = null
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось удалить мастера')
  } finally {
    deleting.value = false
  }
}

function initials(name: string) {
  return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
}
</script>

<template>
  <div class="space-y-6">

    <!-- Header -->
    <PageHeader
      :subtitle="`${masters.length} ${plural(masters.length, 'мастер', 'мастера', 'мастеров')}, ${activeCount} ${plural(activeCount, 'активный', 'активных', 'активных')}`"
      title="Мастера"
    >
      <button @click="openCreate" class="btn-primary shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Добавить мастера</span>
      </button>
    </PageHeader>

    <!-- Filters -->
    <div class="flex flex-col sm:flex-row gap-3">
      <div class="relative flex-1">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input v-model="filterSearch" type="text" class="input pl-9" placeholder="Поиск по имени или специализации" />
      </div>
      <CustomSelect v-model="filterActive" :options="filterActiveOptions" class="sm:w-48" />
    </div>

    <!-- Error -->
    <div v-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="card flex flex-col items-center gap-4 py-16">
      <UiSpinner />
      <p class="text-gray-500 dark:text-gray-400">Загрузка...</p>
    </div>

    <!-- Empty (no masters at all) -->
    <UiEmptyState v-else-if="masters.length === 0" title="Нет мастеров" description="Добавьте первого мастера, чтобы принимать записи">
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
      </template>
      <button @click="openCreate" class="btn-primary">Добавить мастера</button>
    </UiEmptyState>

    <!-- Empty (filter gives no results) -->
    <div v-else-if="filteredMasters.length === 0" class="card text-center py-10">
      <p class="text-gray-500 dark:text-gray-400">Нет мастеров по выбранным фильтрам</p>
      <button @click="filterSearch = ''; filterActive = 'all'" class="btn-ghost btn-sm mt-3">Сбросить фильтры</button>
    </div>

    <!-- Grid -->
    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="master in filteredMasters"
        :key="master.id"
        class="card flex flex-col gap-4"
        :class="{ 'opacity-60': !master.is_active }"
      >
        <!-- Top row: avatar + info + toggle -->
        <div class="flex items-start gap-3">
          <div class="flex-shrink-0">
            <img
              v-if="master.avatar_url"
              :src="master.avatar_url"
              :alt="master.name"
              class="w-12 h-12 rounded-full object-cover border border-gray-200 dark:border-gray-700"
            />
            <div
              v-else
              class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-primary-600 dark:text-primary-400 font-semibold text-lg"
            >
              {{ initials(master.name) }}
            </div>
          </div>

          <div class="flex-1 min-w-0">
            <RouterLink
              :to="`/masters/${master.id}`"
              class="font-semibold text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 truncate block"
            >{{ master.name }}</RouterLink>
            <div class="flex flex-wrap gap-1 mt-1">
              <template v-if="master.services && master.services.length > 0">
                <span
                  v-for="svc in master.services.slice(0, 2)"
                  :key="svc.id"
                  class="inline-block text-xs px-1.5 py-0.5 rounded bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300"
                >{{ svc.name }}</span>
                <span
                  v-if="master.services.length > 2"
                  class="inline-block text-xs px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400"
                >ещё {{ master.services.length - 2 }}</span>
              </template>
              <span v-else class="inline-block text-xs px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500">
                Все услуги
              </span>
            </div>
          </div>

          <button
            @click="toggleActive(master)"
            :class="['relative flex-shrink-0 w-10 h-5 rounded-full transition-colors', master.is_active ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600']"
            :title="master.is_active ? 'Деактивировать' : 'Активировать'"
          >
            <span :class="['absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', master.is_active ? 'translate-x-5' : '']" />
          </button>
        </div>

        <!-- Contacts -->
        <div class="space-y-1 text-sm">
          <a v-if="master.phone" :href="`tel:${master.phone}`" class="flex items-center gap-2 text-gray-600 dark:text-gray-400 hover:text-primary-600">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
            {{ master.phone }}
          </a>
          <a v-if="master.email" :href="`mailto:${master.email}`" class="flex items-center gap-2 text-gray-600 dark:text-gray-400 hover:text-primary-600 truncate">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            {{ master.email }}
          </a>
          <div v-if="!master.phone && !master.email" class="text-gray-400 dark:text-gray-600 italic text-xs">Нет контактов</div>
        </div>

        <!-- Status badge -->
        <div>
          <span :class="master.is_active ? 'badge-paid' : 'badge-cancelled'">
            {{ master.is_active ? 'Активен' : 'Неактивен' }}
          </span>
        </div>

        <!-- Actions -->
        <div class="flex gap-2 pt-1 border-t border-gray-100 dark:border-gray-700">
          <button @click="openEdit(master)" class="btn-secondary btn-sm flex-1">Редактировать</button>

          <!-- Invite / linked badge -->
          <template v-if="master.user_id">
            <span class="badge-confirmed self-center text-xs px-2">✓ Привязан</span>
          </template>
          <template v-else>
            <button
              @click="master.email ? sendMasterInvite(master, master.email) : openInviteForm(master)"
              :disabled="invitingId === master.id"
              class="btn-ghost btn-sm"
              :title="master.email ? `Отправить приглашение на ${master.email}` : 'Пригласить мастера в личный кабинет'"
            >
              <UiSpinner v-if="invitingId === master.id" class="w-4 h-4" />
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
            </button>
          </template>

          <button @click="confirmDelete(master)" class="btn-danger btn-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>

        <!-- Inline invite form -->
        <div
          v-if="inviteOpenId === master.id"
          class="flex flex-col gap-2 pt-3 border-t border-gray-100 dark:border-gray-700"
        >
          <p class="text-xs text-gray-500 dark:text-gray-400">У мастера не указан email. Введите адрес — он получит ссылку для входа в личный кабинет</p>
          <div class="flex gap-2">
            <input
              v-model="inviteEmails[master.id]"
              type="email"
              class="input text-sm flex-1 min-w-0"
              placeholder="email@example.com"
              @keydown.enter="sendMasterInvite(master)"
              @keydown.escape="inviteOpenId = null"
            />
            <button
              @click="sendMasterInvite(master)"
              class="btn-primary btn-sm flex-shrink-0"
              :disabled="invitingId === master.id"
            >
              <UiSpinner v-if="invitingId === master.id" class="w-4 h-4" />
              <span v-else>Отправить</span>
            </button>
          </div>
          <p v-if="inviteErrors[master.id]" class="text-xs text-red-500">{{ inviteErrors[master.id] }}</p>
        </div>
      </div>
    </div>

    <MasterFormModal v-model="showModal" :master="editingMaster" @saved="handleMasterSaved" />

    <UiConfirmDialog
      :modelValue="!!deleteTarget"
      @update:modelValue="!$event && (deleteTarget = null)"
      title="Удалить мастера?"
      :loading="deleting"
      @confirm="doDelete"
    >
      Мастер <strong>{{ deleteTarget?.name }}</strong> будет удалён. Записи, привязанные к нему, останутся.
    </UiConfirmDialog>

  </div>
</template>
