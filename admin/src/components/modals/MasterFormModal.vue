<script setup lang="ts">
import { ref, computed, reactive, watch } from 'vue'
import { api } from '@/lib/api'
import { handlePhoneInput, applyPhoneMask, isValidPhone } from '@/composables/usePhoneInput'
import { UiModal } from '@/shared/ui'
import type { Master, Product } from '@/types'

const props = defineProps<{
  modelValue: boolean
  master: Master | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'saved': [master: Master, mode: 'create' | 'edit']
}>()

const mode = computed(() => props.master ? 'edit' : 'create')

const saving   = ref(false)
const error    = ref('')

// ── Services ──────────────────────────────────────────────────
const allServices       = ref<Product[]>([])
const selectedServiceIds = ref<string[]>([])
const servicesLoading   = ref(false)

const servicesByCategory = computed(() => {
  const map = new Map<string, Product[]>()
  for (const s of allServices.value) {
    const cat = s.category?.name ?? 'Без категории'
    if (!map.has(cat)) map.set(cat, [])
    map.get(cat)!.push(s)
  }
  return map
})

function toggleService(id: string) {
  const idx = selectedServiceIds.value.indexOf(id)
  if (idx === -1) selectedServiceIds.value.push(id)
  else selectedServiceIds.value.splice(idx, 1)
}

async function loadAllServices() {
  if (allServices.value.length) return
  try {
    const res = await api.getProducts({ type: 'service', per_page: '100' })
    allServices.value = res.data
  } catch { /* ignore */ }
}

// ── Form ──────────────────────────────────────────────────────
const emptyForm = () => ({
  name: '', specialization: '', phone: '', email: '',
  avatar_url: '', is_active: true, sort_order: 0,
})

const form = ref(emptyForm())

// ── Validation ────────────────────────────────────────────────
const formErrors = reactive({ name: '', phone: '', email: '' })

function validateName(v: string)  { formErrors.name  = v.trim() ? '' : 'Укажите имя мастера' }
function validatePhone(v: string) {
  if (!v) { formErrors.phone = ''; return }
  formErrors.phone = isValidPhone(v) ? '' : 'Введите полный номер: +7 (XXX) XXX-XX-XX'
}
function validateEmail(v: string) {
  if (!v) { formErrors.email = ''; return }
  formErrors.email = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? '' : 'Некорректный email'
}
function resetErrors() { formErrors.name = ''; formErrors.phone = ''; formErrors.email = '' }

const isFormValid = computed(() =>
  !formErrors.name && !formErrors.phone && !formErrors.email && form.value.name.trim()
)

// ── Avatar upload ─────────────────────────────────────────────
const avatarUploading   = ref(false)
const avatarUploadError = ref('')
const avatarFileInputEl = ref<HTMLInputElement | null>(null)
const AVATAR_ALLOWED    = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']
const AVATAR_MAX_BYTES  = 1 * 1024 * 1024

function validateAvatarFile(file: File): string | null {
  if (!AVATAR_ALLOWED.includes(file.type)) return 'Только JPG, PNG или WEBP'
  if (file.size > AVATAR_MAX_BYTES) return `Слишком большой (макс. 1 МБ, у вас ${(file.size / 1024 / 1024).toFixed(1)} МБ)`
  return null
}

async function handleAvatarFile(file: File) {
  const err = validateAvatarFile(file)
  if (err) { avatarUploadError.value = err; return }
  avatarUploadError.value = ''
  form.value.avatar_url   = URL.createObjectURL(file)
  avatarUploading.value   = true
  try {
    const result = await api.uploadImage(file)
    form.value.avatar_url = result.url
  } catch (e: unknown) {
    form.value.avatar_url   = ''
    avatarUploadError.value = e instanceof Error ? e.message : 'Не удалось загрузить'
  }
  avatarUploading.value = false
}

function onAvatarFileInput(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) handleAvatarFile(file)
}

function clearAvatar() {
  form.value.avatar_url   = ''
  avatarUploadError.value = ''
  if (avatarFileInputEl.value) avatarFileInputEl.value.value = ''
}


// ── Open / Reset ──────────────────────────────────────────────
watch(() => props.modelValue, async (open) => {
  if (!open) return
  error.value             = ''
  avatarUploadError.value = ''
  selectedServiceIds.value = []
  resetErrors()

  if (props.master) {
    form.value = {
      name:           props.master.name           || '',
      specialization: props.master.specialization || '',
      phone:          props.master.phone ? applyPhoneMask(props.master.phone) : '',
      email:          props.master.email          || '',
      avatar_url:     props.master.avatar_url     || '',
      is_active:      props.master.is_active ?? true,
      sort_order:     props.master.sort_order     ?? 0,
    }
    servicesLoading.value = true
    await loadAllServices()
    try {
      const res = await api.getMasterServices(props.master.id)
      selectedServiceIds.value = res.data
    } catch { /* ignore */ } finally {
      servicesLoading.value = false
    }
  } else {
    form.value = emptyForm()
    loadAllServices()
  }
})

// ── Save ──────────────────────────────────────────────────────
async function save() {
  validateName(form.value.name)
  validatePhone(form.value.phone)
  validateEmail(form.value.email)
  if (!isFormValid.value) return

  saving.value = true
  error.value  = ''
  try {
    const payload = { ...form.value }
    let savedId: string
    let savedMaster: Master

    if (mode.value === 'create') {
      const res   = await api.createMaster(payload)
      savedMaster = res.data
      savedId     = res.data.id
    } else {
      const res   = await api.updateMaster(props.master!.id, payload)
      savedMaster = res.data
      savedId     = props.master!.id
    }

    await api.updateMasterServices(savedId, selectedServiceIds.value)
    emit('saved', savedMaster, mode.value)
    emit('update:modelValue', false)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UiModal :modelValue="modelValue" maxWidth="max-w-md" @update:modelValue="emit('update:modelValue', $event)">
    <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
        {{ mode === 'create' ? 'Новый мастер' : 'Редактировать мастера' }}
      </h2>
      <button @click="emit('update:modelValue', false)" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div class="p-4 space-y-4">
      <div v-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">{{ error }}</div>

      <div>
        <label class="label">Имя <span class="text-red-500">*</span></label>
        <input v-model="form.name" @input="validateName(form.name)" @blur="validateName(form.name)" type="text"
          :class="['input', formErrors.name ? 'input-error' : '']" placeholder="Иван Иванов" />
        <p v-if="formErrors.name" class="mt-1 text-xs text-red-500">{{ formErrors.name }}</p>
      </div>

      <div>
        <label class="label">Специализация</label>
        <input v-model="form.specialization" type="text" class="input" placeholder="Парикмахер, массажист..." autocomplete="off" />
      </div>

      <div>
        <label class="label">Телефон</label>
        <input :value="form.phone"
          @input="handlePhoneInput($event, (v) => { form.phone = v; validatePhone(v) })"
          @blur="validatePhone(form.phone)" type="tel"
          :class="['input', formErrors.phone ? 'input-error' : form.phone && !formErrors.phone ? 'input-success' : '']"
          placeholder="+7 (999) 123-45-67" />
        <p v-if="formErrors.phone" class="mt-1 text-xs text-red-500">{{ formErrors.phone }}</p>
      </div>

      <div>
        <label class="label">Email</label>
        <input v-model="form.email" @input="validateEmail(form.email)" @blur="validateEmail(form.email)" type="email"
          :class="['input', formErrors.email ? 'input-error' : form.email && !formErrors.email ? 'input-success' : '']"
          placeholder="master@example.com" />
        <p v-if="formErrors.email" class="mt-1 text-xs text-red-500">{{ formErrors.email }}</p>
      </div>

      <!-- Avatar -->
      <div>
        <label class="label">Фото</label>
        <div v-if="form.avatar_url" class="flex items-start gap-3 mb-2">
          <div class="relative flex-shrink-0">
            <img :src="form.avatar_url" class="h-20 w-20 object-cover rounded-full border border-gray-200 dark:border-gray-700" alt="Аватар" />
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
        <div v-else @click="avatarFileInputEl?.click()"
          class="border-2 border-dashed border-gray-200 dark:border-gray-700 hover:border-primary-400 hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded-xl p-4 text-center cursor-pointer transition-colors">
          <svg class="w-7 h-7 mx-auto mb-1 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
          <p class="text-sm text-gray-500 dark:text-gray-400"><span class="text-primary-600 dark:text-primary-400 font-medium">Нажмите</span> для загрузки фото</p>
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">JPG, PNG, WEBP · до 1 МБ</p>
        </div>
        <p v-if="avatarUploadError" class="mt-1 text-xs text-red-500">{{ avatarUploadError }}</p>
        <input ref="avatarFileInputEl" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onAvatarFileInput" />
      </div>

      <div>
        <label class="label">Порядок сортировки</label>
        <input v-model.number="form.sort_order" type="number" min="0" class="input" placeholder="0" />
      </div>

      <!-- Services -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="label mb-0">Услуги мастера</label>
          <span class="text-xs text-gray-400 dark:text-gray-500">
            {{ selectedServiceIds.length ? `${selectedServiceIds.length} выбрано` : 'все (не ограничено)' }}
          </span>
        </div>
        <div v-if="servicesLoading" class="flex items-center gap-2 text-sm text-gray-400 py-2">
          <div class="animate-spin w-4 h-4 border-2 border-primary-500 border-t-transparent rounded-full"></div>
          Загрузка...
        </div>
        <div v-else-if="allServices.length === 0" class="text-sm text-gray-400 italic py-1">Нет услуг в магазине</div>
        <div v-else class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
          <template v-for="[cat, services] in servicesByCategory" :key="cat">
            <div class="px-3 py-1.5 bg-gray-50 dark:bg-gray-800/60 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide border-b border-gray-200 dark:border-gray-700">{{ cat }}</div>
            <label v-for="svc in services" :key="svc.id"
              class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/40 border-b border-gray-100 dark:border-gray-700/50 last:border-0">
              <input type="checkbox" :checked="selectedServiceIds.includes(svc.id)" @change="toggleService(svc.id)"
                class="w-4 h-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 cursor-pointer flex-shrink-0" />
              <span class="text-sm text-gray-700 dark:text-gray-300">{{ svc.name }}</span>
              <span class="ml-auto text-xs text-gray-400">{{ svc.price.toLocaleString('ru-RU') }} ₽</span>
            </label>
          </template>
        </div>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Если ничего не выбрано — мастер появится для всех услуг</p>
      </div>

      <div class="flex items-center justify-between py-2">
        <span class="label mb-0">Активен</span>
        <button type="button" @click="form.is_active = !form.is_active"
          :class="['relative w-11 h-6 rounded-full transition-colors', form.is_active ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600']">
          <span :class="['absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition-transform', form.is_active ? 'translate-x-5' : '']" />
        </button>
      </div>
    </div>

    <div class="flex gap-3 p-4 pt-0">
      <button @click="emit('update:modelValue', false)" class="btn-secondary flex-1">Отмена</button>
      <button @click="save" class="btn-primary flex-1" :disabled="saving || !isFormValid">
        {{ saving ? 'Сохранение...' : (mode === 'create' ? 'Создать' : 'Сохранить') }}
      </button>
    </div>
  </UiModal>
</template>
