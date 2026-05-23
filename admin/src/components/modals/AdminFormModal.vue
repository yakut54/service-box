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

const name  = ref('')
const email = ref('')
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
  error.value = ''
  nameTouched.value = false
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
  <UiModal
    :model-value="modelValue"
    :title="mode === 'create' ? 'Добавить администратора' : 'Редактировать'"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <div class="flex flex-col gap-4">

      <!-- Name -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          Имя <span class="text-red-500">*</span>
        </label>
        <input
          v-model="name"
          type="text"
          class="input w-full"
          :class="{ 'border-red-400 dark:border-red-500': nameError }"
          placeholder="Анна Петрова"
          @blur="nameTouched = true"
          @keydown.enter="save"
        />
        <p v-if="nameError" class="mt-1 text-xs text-red-500">{{ nameError }}</p>
      </div>

      <!-- Email (read-only when editing) -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          Email <span class="text-red-500">*</span>
        </label>
        <input
          v-if="mode === 'create'"
          v-model="email"
          type="email"
          class="input w-full"
          :class="{ 'border-red-400 dark:border-red-500': emailError }"
          placeholder="admin@example.com"
          @blur="emailTouched = true"
          @keydown.enter="save"
        />
        <div
          v-else
          class="input w-full bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 cursor-default"
        >{{ email }}</div>
        <p v-if="emailError" class="mt-1 text-xs text-red-500">{{ emailError }}</p>
        <p v-if="mode === 'create'" class="mt-1 text-xs text-gray-400 dark:text-gray-500">
          На этот адрес придёт приглашение. Действует 48 часов.
        </p>
      </div>

      <!-- Error -->
      <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>

    <template #footer>
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
    </template>
  </UiModal>
</template>
