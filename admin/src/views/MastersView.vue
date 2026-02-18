<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { formatPhone, plural } from '@/lib/utils'
import CustomSelect from '@/components/CustomSelect.vue'

// ── State ────────────────────────────────────────────────────
const masters = ref<any[]>([])
const loading = ref(false)
const error = ref('')

// ── Modal ────────────────────────────────────────────────────
const showModal = ref(false)
const modalMode = ref<'create' | 'edit'>('create')
const saving = ref(false)
const modalError = ref('')
const editingId = ref<string | null>(null)

const emptyForm = () => ({
  name: '',
  specialization: '',
  phone: '',
  email: '',
  avatar_url: '',
  is_active: true,
  sort_order: 0,
})

const form = ref(emptyForm())

// ── Delete confirm ───────────────────────────────────────────
const deleteTarget = ref<any | null>(null)
const deleting = ref(false)

// ── Filter ───────────────────────────────────────────────────
const filterSearch = ref('')
const filterActive = ref('all')

const filterActiveOptions = [
  { value: 'all', label: 'Все мастера' },
  { value: 'active', label: 'Только активные' },
  { value: 'inactive', label: 'Только неактивные' },
]

const filteredMasters = computed(() => {
  let list = masters.value
  if (filterActive.value === 'active') list = list.filter(m => m.is_active)
  if (filterActive.value === 'inactive') list = list.filter(m => !m.is_active)
  if (filterSearch.value.trim()) {
    const q = filterSearch.value.toLowerCase()
    list = list.filter(m => m.name.toLowerCase().includes(q) || (m.specialization || '').toLowerCase().includes(q))
  }
  return list
})

// ── Computed ─────────────────────────────────────────────────
const activeCount = computed(() => masters.value.filter(m => m.is_active).length)

function sortMasters() {
  masters.value = [...masters.value].sort((a, b) => (a.sort_order ?? 999) - (b.sort_order ?? 999))
}

// ── Load ─────────────────────────────────────────────────────
async function loadMasters() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getMasters()
    masters.value = res.data
    sortMasters()
  } catch (e: any) {
    error.value = e.message || 'Не удалось загрузить мастеров'
  } finally {
    loading.value = false
  }
}

onMounted(loadMasters)

// ── Open modal ───────────────────────────────────────────────
function openCreate() {
  modalMode.value = 'create'
  form.value = emptyForm()
  editingId.value = null
  modalError.value = ''
  avatarUploadError.value = ''
  showModal.value = true
}

function openEdit(master: any) {
  modalMode.value = 'edit'
  editingId.value = master.id
  form.value = {
    name: master.name || '',
    specialization: master.specialization || '',
    phone: master.phone || '',
    email: master.email || '',
    avatar_url: master.avatar_url || '',
    is_active: master.is_active ?? true,
    sort_order: master.sort_order ?? 0,
  }
  modalError.value = ''
  avatarUploadError.value = ''
  showModal.value = true
}

// ── Save ─────────────────────────────────────────────────────
async function save() {
  if (!form.value.name.trim()) {
    modalError.value = 'Укажите имя мастера'
    return
  }

  saving.value = true
  modalError.value = ''

  try {
    const payload = { ...form.value }

    if (modalMode.value === 'create') {
      const res = await api.createMaster(payload)
      masters.value.unshift(res.data)
    } else {
      const res = await api.updateMaster(editingId.value!, payload)
      const idx = masters.value.findIndex(m => m.id === editingId.value)
      if (idx !== -1) masters.value[idx] = res.data
      sortMasters()
    }

    showModal.value = false
  } catch (e: any) {
    modalError.value = e.message || 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}

// ── Toggle active ─────────────────────────────────────────────
async function toggleActive(master: any) {
  try {
    const res = await api.updateMaster(master.id, { is_active: !master.is_active })
    const idx = masters.value.findIndex(m => m.id === master.id)
    if (idx !== -1) masters.value[idx] = res.data
  } catch {
    // silent
  }
}

// ── Delete ────────────────────────────────────────────────────
function confirmDelete(master: any) {
  deleteTarget.value = master
}

async function doDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await api.deleteMaster(deleteTarget.value.id)
    masters.value = masters.value.filter(m => m.id !== deleteTarget.value.id)
    deleteTarget.value = null
  } catch (e: any) {
    error.value = e.message || 'Ошибка удаления'
  } finally {
    deleting.value = false
  }
}

// ── Avatar initials ───────────────────────────────────────────
function initials(name: string) {
  return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
}

// ── Avatar upload ─────────────────────────────────────────────
const avatarUploading  = ref(false)
const avatarUploadError = ref('')
const avatarFileInputEl = ref<HTMLInputElement | null>(null)
const AVATAR_ALLOWED   = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']
const AVATAR_MAX_BYTES = 1 * 1024 * 1024

function validateAvatarFile(file: File): string | null {
  if (!AVATAR_ALLOWED.includes(file.type)) return 'Только JPG, PNG или WEBP'
  if (file.size > AVATAR_MAX_BYTES) return `Слишком большой (макс. 1 МБ, у вас ${(file.size / 1024 / 1024).toFixed(1)} МБ)`
  return null
}

async function handleAvatarFile(file: File) {
  const err = validateAvatarFile(file)
  if (err) { avatarUploadError.value = err; return }
  avatarUploadError.value = ''
  form.value.avatar_url = URL.createObjectURL(file)
  avatarUploading.value = true
  try {
    const result = await api.uploadImage(file)
    form.value.avatar_url = result.url
  } catch (e: any) {
    form.value.avatar_url = ''
    avatarUploadError.value = e.message || 'Не удалось загрузить'
  }
  avatarUploading.value = false
}

function onAvatarFileInput(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) handleAvatarFile(file)
}

function clearAvatar() {
  form.value.avatar_url = ''
  avatarUploadError.value = ''
  if (avatarFileInputEl.value) avatarFileInputEl.value.value = ''
}
</script>

<template>
  <div class="space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Мастера</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          {{ masters.length }} {{ plural(masters.length, 'мастер', 'мастера', 'мастеров') }}, {{ activeCount }} {{ plural(activeCount, 'активный', 'активных', 'активных') }}
        </p>
      </div>
      <button @click="openCreate" class="btn-primary shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Добавить мастера</span>
      </button>
    </div>

    <!-- Filters -->
    <div class="flex flex-col sm:flex-row gap-3">
      <div class="relative flex-1">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <input
          v-model="filterSearch"
          type="text"
          class="input pl-9"
          placeholder="Поиск по имени или специализации"
        />
      </div>
      <CustomSelect
        v-model="filterActive"
        :options="filterActiveOptions"
        class="sm:w-48"
      />
    </div>

    <!-- Error -->
    <div v-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="card flex items-center justify-center py-16">
      <div class="text-gray-500 dark:text-gray-400">Загрузка...</div>
    </div>

    <!-- Empty (no masters at all) -->
    <div v-else-if="masters.length === 0" class="card text-center py-16">
      <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
      </div>
      <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Нет мастеров</h3>
      <p class="text-gray-500 dark:text-gray-400 mb-6">Добавьте первого мастера, чтобы принимать записи</p>
      <button @click="openCreate" class="btn-primary">Добавить мастера</button>
    </div>

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
          <!-- Avatar -->
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

          <!-- Info -->
          <div class="flex-1 min-w-0">
            <RouterLink
              :to="`/masters/${master.id}`"
              class="font-semibold text-gray-900 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 truncate block"
            >{{ master.name }}</RouterLink>
            <div v-if="master.specialization" class="text-sm text-gray-500 dark:text-gray-400 truncate">
              {{ master.specialization }}
            </div>
          </div>

          <!-- Active toggle -->
          <button
            @click="toggleActive(master)"
            :class="[
              'relative flex-shrink-0 w-10 h-5 rounded-full transition-colors',
              master.is_active ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600'
            ]"
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
          <button @click="openEdit(master)" class="btn-secondary btn-sm flex-1">
            Редактировать
          </button>
          <button @click="confirmDelete(master)" class="btn-danger btn-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- ── Create / Edit Modal ───────────────────────────────── -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" />
      <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <!-- Modal header -->
        <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
            {{ modalMode === 'create' ? 'Новый мастер' : 'Редактировать мастера' }}
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

          <div>
            <label class="label">Имя <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="input" placeholder="Иван Иванов" />
          </div>

          <div>
            <label class="label">Специализация</label>
            <input v-model="form.specialization" type="text" class="input" placeholder="Парикмахер, массажист..." autocomplete="off" />
          </div>

          <div>
            <label class="label">Телефон</label>
            <input
              :value="form.phone"
              @input="form.phone = formatPhone(($event.target as HTMLInputElement).value)"
              type="tel"
              class="input"
              placeholder="+7 (999) 123-45-67"
            />
          </div>

          <div>
            <label class="label">Email</label>
            <input v-model="form.email" type="email" class="input" placeholder="master@example.com" />
          </div>

          <!-- Avatar upload -->
          <div>
            <label class="label">Фото</label>

            <!-- Preview -->
            <div v-if="form.avatar_url" class="flex items-start gap-3 mb-2">
              <div class="relative flex-shrink-0">
                <img
                  :src="form.avatar_url"
                  class="h-20 w-20 object-cover rounded-full border border-gray-200 dark:border-gray-700"
                  alt="Аватар"
                />
                <div v-if="avatarUploading" class="absolute inset-0 bg-black/50 rounded-full flex items-center justify-center">
                  <div class="animate-spin w-5 h-5 border-2 border-white border-t-transparent rounded-full"></div>
                </div>
              </div>
              <div v-if="!avatarUploading" class="flex flex-col gap-1.5 pt-1">
                <button type="button" @click="avatarFileInputEl?.click()" class="text-xs text-primary-600 dark:text-primary-400 hover:underline text-left">Заменить</button>
                <button type="button" @click="clearAvatar" class="text-xs text-red-500 hover:text-red-700 flex items-center gap-1 text-left">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                  Удалить
                </button>
              </div>
              <p v-else class="text-xs text-gray-400 pt-1">Загружается...</p>
            </div>

            <!-- Drop zone -->
            <div
              v-else
              @click="avatarFileInputEl?.click()"
              class="border-2 border-dashed border-gray-200 dark:border-gray-700 hover:border-primary-400 hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded-xl p-4 text-center cursor-pointer transition-colors"
            >
              <svg class="w-7 h-7 mx-auto mb-1 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
              <p class="text-sm text-gray-500 dark:text-gray-400"><span class="text-primary-600 dark:text-primary-400 font-medium">Нажмите</span> для загрузки фото</p>
              <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">JPG, PNG, WEBP · до 1 МБ</p>
            </div>

            <p v-if="avatarUploadError" class="mt-1 text-xs text-red-500">{{ avatarUploadError }}</p>

            <input
              ref="avatarFileInputEl"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              class="hidden"
              @change="onAvatarFileInput"
            />
          </div>

          <div>
            <label class="label">Порядок сортировки</label>
            <input v-model.number="form.sort_order" type="number" min="0" class="input" placeholder="0" />
          </div>

          <div class="flex items-center justify-between py-2">
            <span class="label mb-0">Активен</span>
            <button
              type="button"
              @click="form.is_active = !form.is_active"
              :class="[
                'relative w-11 h-6 rounded-full transition-colors',
                form.is_active ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600'
              ]"
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

    <!-- ── Delete Confirm Modal ──────────────────────────────── -->
    <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="deleteTarget = null" />
      <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-sm p-6 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Удалить мастера?</h2>
        <p class="text-gray-600 dark:text-gray-400">
          Мастер <strong>{{ deleteTarget.name }}</strong> будет удалён. Записи, привязанные к нему, останутся.
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
