<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { api, ApiError } from '@/lib/api'
import { handlePhoneInput, applyPhoneMask } from '@/composables/usePhoneInput'
import { UiModal, UiHint } from '@/shared/ui'
import ImageUpload from '@/components/ImageUpload.vue'
import type { StaffMember } from '@/types'

const props = defineProps<{
  modelValue: boolean
  admin: StaffMember | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'saved': [admin: StaffMember, mode: 'create' | 'edit']
}>()

const mode = computed(() => props.admin ? 'edit' : 'create')

const role       = ref<'admin' | 'collector'>('admin')
const name       = ref('')
const email      = ref('')
const phone      = ref('')
const avatarUrl  = ref<string | null>(null)
const saving     = ref(false)
const error      = ref('')
const uploading  = ref(false)

const nameTouched  = ref(false)
const emailTouched = ref(false)

const nameError = computed(() => {
  if (!nameTouched.value) return ''
  return name.value.trim().length >= 2 ? '' : 'Введите имя (минимум 2 символа)'
})

const emailError = computed(() => {
  if (!emailTouched.value) return ''
  if (!email.value.trim()) return 'Введите email'
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value) ? '' : 'Некорректный email'
})

const isValid = computed(() =>
  name.value.trim().length >= 2 &&
  /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)
)

watch(() => props.modelValue, (open) => {
  if (!open) return
  error.value        = ''
  nameTouched.value  = false
  emailTouched.value = false
  if (props.admin) {
    role.value      = props.admin.role === 'collector' ? 'collector' : 'admin'
    name.value      = props.admin.invite_name ?? props.admin.user?.name ?? ''
    email.value     = props.admin.invite_email ?? props.admin.user?.email ?? ''
    phone.value     = props.admin.phone ? applyPhoneMask(props.admin.phone) : ''
    avatarUrl.value = props.admin.avatar_url ?? null
  } else {
    role.value      = 'admin'
    name.value      = ''
    email.value     = ''
    phone.value     = ''
    avatarUrl.value = null
  }
})

async function save() {
  nameTouched.value  = true
  emailTouched.value = true
  if (!isValid.value || uploading.value) return

  saving.value = true
  error.value  = ''
  try {
    if (mode.value === 'create') {
      const res = role.value === 'collector'
        ? await api.createCollector(name.value.trim(), email.value.trim())
        : await api.createAdmin(name.value.trim(), email.value.trim())
      // Если при создании уже загрузили аватар/телефон — сразу обновляем
      if (avatarUrl.value || phone.value) {
        await api.updateAdmin(res.data.id, {
          name:       name.value.trim(),
          phone:      phone.value || null,
          avatar_url: avatarUrl.value,
        })
      }
      emit('saved', { ...res.data, avatar_url: avatarUrl.value, phone: phone.value || null }, 'create')
    } else {
      await api.updateAdmin(props.admin!.id, {
        name:       name.value.trim(),
        phone:      phone.value || null,
        avatar_url: avatarUrl.value,
      })
      emit('saved', {
        ...props.admin!,
        invite_name: name.value.trim(),
        phone:       phone.value || null,
        avatar_url:  avatarUrl.value,
      }, 'edit')
    }
    emit('update:modelValue', false)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UiModal :model-value="modelValue" max-width="max-w-md" @update:model-value="$emit('update:modelValue', $event)">

    <!-- Header -->
    <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700">
      <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
        {{ mode === 'create' ? 'Добавить сотрудника' : 'Редактировать' }}
      </h2>
      <button @click="$emit('update:modelValue', false)" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Body -->
    <div class="p-4 space-y-4">

      <div v-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
        {{ error }}
      </div>

      <!-- Role -->
      <div v-if="mode === 'create'">
        <p class="label flex items-center gap-1">
          Роль
          <UiHint>{{ role === 'collector'
            ? 'Видит только заказы — без доступа к финансам, клиентам и настройкам'
            : 'Полный доступ к панели управления, кроме владельческих настроек' }}</UiHint>
        </p>
        <div class="grid grid-cols-2 gap-2">
          <button
            type="button"
            @click="role = 'admin'"
            :class="['px-3 py-2 rounded-lg border text-sm font-medium transition-colors',
              role === 'admin'
                ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-400'
                : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300']"
          >
            Администратор
          </button>
          <button
            type="button"
            @click="role = 'collector'"
            :class="['px-3 py-2 rounded-lg border text-sm font-medium transition-colors',
              role === 'collector'
                ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-400'
                : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300']"
          >
            Сборщик
          </button>
        </div>
      </div>

      <!-- Avatar -->
      <div class="flex justify-center">
        <ImageUpload
          v-model="avatarUrl"
          v-model:uploading="uploading"
          shape="circle"
          size="lg"
          confirmText="Фото администратора будет удалено."
        />
      </div>

      <!-- Name -->
      <div>
        <p class="label">Имя <span class="text-red-500">*</span></p>
        <input
          v-model="name"
          type="text"
          :class="['input', nameError ? 'input-error' : '']"
          placeholder="Анна Петрова"
          @blur="nameTouched = true"
          @keydown.enter="save"
        />
        <p v-if="nameError" class="mt-1 text-xs text-red-500">{{ nameError }}</p>
      </div>

      <!-- Email -->
      <div>
        <p class="label flex items-center gap-1">
          Email <span class="text-red-500">*</span>
          <UiHint v-if="mode === 'create'">На этот адрес придёт ссылка-приглашение. Действует 48 часов.</UiHint>
        </p>
        <input
          v-if="mode === 'create'"
          v-model="email"
          type="email"
          :class="['input', emailError ? 'input-error' : '']"
          placeholder="admin@example.com"
          @blur="emailTouched = true"
          @keydown.enter="save"
        />
        <div v-else class="input bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 cursor-default select-all">
          {{ email }}
        </div>
        <p v-if="emailError" class="mt-1 text-xs text-red-500">{{ emailError }}</p>
      </div>

      <!-- Phone -->
      <div>
        <p class="label">Телефон</p>
        <input
          :value="phone"
          type="tel"
          class="input"
          placeholder="+7 (999) 123-45-67"
          @input="handlePhoneInput($event, (v) => phone = v)"
        />
      </div>

    </div>

    <!-- Footer -->
    <div class="flex justify-end gap-3 px-4 py-3 border-t border-gray-100 dark:border-gray-700">
      <button @click="$emit('update:modelValue', false)" class="btn-secondary" :disabled="saving">
        Отмена
      </button>
      <button @click="save" class="btn-primary" :disabled="saving || uploading">
        {{ saving ? 'Сохранение...' : (mode === 'create' ? 'Добавить и отправить' : 'Сохранить') }}
      </button>
    </div>

  </UiModal>
</template>
