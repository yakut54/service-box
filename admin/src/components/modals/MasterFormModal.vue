<script setup lang="ts">
import { ref, computed, reactive, watch } from 'vue'
import { api } from '@/lib/api'
import { handlePhoneInput, applyPhoneMask, isValidPhone } from '@/composables/usePhoneInput'
import { UiModal } from '@/shared/ui'
import ImageUpload from '@/components/ImageUpload.vue'
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
const avatarUploading = ref(false)


// ── Open / Reset ──────────────────────────────────────────────
watch(() => props.modelValue, async (open) => {
  if (!open) return
  error.value              = ''
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
        <p class="label">Имя <span class="text-red-500">*</span></p>
        <input v-model="form.name" @input="validateName(form.name)" @blur="validateName(form.name)" type="text"
          :class="['input', formErrors.name ? 'input-error' : '']" placeholder="Иван Иванов" />
        <p v-if="formErrors.name" class="mt-1 text-xs text-red-500">{{ formErrors.name }}</p>
      </div>

      <div>
        <p class="label">Специализация</p>
        <input v-model="form.specialization" type="text" class="input" placeholder="Парикмахер, массажист..." autocomplete="off" />
      </div>

      <div>
        <p class="label">Телефон</p>
        <input :value="form.phone"
          @input="handlePhoneInput($event, (v) => { form.phone = v; validatePhone(v) })"
          @blur="validatePhone(form.phone)" type="tel"
          :class="['input', formErrors.phone ? 'input-error' : form.phone && !formErrors.phone ? 'input-success' : '']"
          placeholder="+7 (999) 123-45-67" />
        <p v-if="formErrors.phone" class="mt-1 text-xs text-red-500">{{ formErrors.phone }}</p>
      </div>

      <div>
        <p class="label">Email</p>
        <input v-model="form.email" @input="validateEmail(form.email)" @blur="validateEmail(form.email)" type="email"
          :class="['input', formErrors.email ? 'input-error' : form.email && !formErrors.email ? 'input-success' : '']"
          placeholder="master@example.com" />
        <p v-if="formErrors.email" class="mt-1 text-xs text-red-500">{{ formErrors.email }}</p>
      </div>

      <!-- Avatar -->
      <div>
        <p class="label">Фото</p>
        <ImageUpload
          v-model="form.avatar_url"
          v-model:uploading="avatarUploading"
          shape="circle"
          size="md"
          confirmText="Фото мастера будет удалено. Это действие нельзя отменить."
        />
      </div>

      <div>
        <p class="label">Порядок сортировки</p>
        <input v-model.number="form.sort_order" type="number" min="0" class="input" placeholder="0" />
      </div>

      <!-- Services -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <p class="label mb-0">Услуги мастера</p>
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
              <span :class="['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                selectedServiceIds.includes(svc.id) ? 'bg-primary-600' : 'bg-gray-200 dark:bg-gray-700']">
                <span :class="['inline-block h-4 w-4 rounded-full bg-white shadow transition-transform',
                  selectedServiceIds.includes(svc.id) ? 'translate-x-6' : 'translate-x-1']" />
              </span>
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
      <button @click="save" class="btn-primary flex-1" :disabled="saving || !isFormValid || avatarUploading">
        {{ saving ? 'Сохранение...' : (mode === 'create' ? 'Создать' : 'Сохранить') }}
      </button>
    </div>
  </UiModal>
</template>
