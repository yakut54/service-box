<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { api, ApiError } from '@/lib/api'
import { UiModal } from '@/shared/ui'
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

const name   = ref('')
const email  = ref('')
const saving = ref(false)
const error  = ref('')

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
    name.value  = props.admin.invite_name ?? props.admin.user?.name ?? ''
    email.value = props.admin.invite_email ?? props.admin.user?.email ?? ''
  } else {
    name.value  = ''
    email.value = ''
  }
})

async function save() {
  nameTouched.value  = true
  emailTouched.value = true
  if (!isValid.value) return

  saving.value = true
  error.value  = ''
  try {
    if (mode.value === 'create') {
      const res = await api.createAdmin(name.value.trim(), email.value.trim())
      emit('saved', res.data, 'create')
    } else {
      await api.updateAdmin(props.admin!.id, name.value.trim())
      emit('saved', { ...props.admin!, invite_name: name.value.trim() }, 'edit')
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
        {{ mode === 'create' ? 'Добавить администратора' : 'Редактировать' }}
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
        <p class="label">Email <span class="text-red-500">*</span></p>
        <input
          v-if="mode === 'create'"
          v-model="email"
          type="email"
          :class="['input', emailError ? 'input-error' : '']"
          placeholder="admin@example.com"
          @blur="emailTouched = true"
          @keydown.enter="save"
        />
        <div
          v-else
          class="input bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 cursor-default select-all"
        >{{ email }}</div>
        <p v-if="emailError" class="mt-1 text-xs text-red-500">{{ emailError }}</p>
        <p v-if="mode === 'create'" class="mt-1 text-xs text-gray-400 dark:text-gray-500">
          На этот адрес придёт ссылка-приглашение. Действует 48 часов.
        </p>
      </div>

    </div>

    <!-- Footer -->
    <div class="flex justify-end gap-3 px-4 py-3 border-t border-gray-100 dark:border-gray-700">
      <button
        @click="$emit('update:modelValue', false)"
        class="btn-secondary"
        :disabled="saving"
      >Отмена</button>
      <button
        @click="save"
        class="btn-primary"
        :disabled="saving"
      >{{ saving ? 'Сохранение...' : (mode === 'create' ? 'Добавить и отправить' : 'Сохранить') }}</button>
    </div>

  </UiModal>
</template>
