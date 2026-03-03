<script setup lang="ts">
import { ref, computed } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: string
  id?: string
  placeholder?: string
  autocomplete?: string
  withGenerate?: boolean
}>(), {
  withGenerate: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'generate': [value: string]
}>()

const show  = ref(false)
const copied = ref(false)

const inputType = computed(() => show.value ? 'text' : 'password')
const inputClass = computed(() => props.withGenerate ? 'input pr-20' : 'input pr-10')

function toggleShow() {
  show.value = !show.value
}

function generatePassword(): string {
  const lower  = 'abcdefghjkmnpqrstuvwxyz'
  const upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ'
  const digits = '23456789'
  const symbols = '!@#$%&*'
  const all = lower + upper + digits + symbols
  const arr = new Uint32Array(16)
  crypto.getRandomValues(arr)
  // Гарантируем минимум по 1 символу каждого типа
  let pwd = [
    lower[arr[0]  % lower.length],
    upper[arr[1]  % upper.length],
    digits[arr[2] % digits.length],
    symbols[arr[3] % symbols.length],
    ...Array.from({ length: 12 }, (_, i) => all[arr[4 + i] % all.length]),
  ]
  // Перемешиваем
  for (let i = pwd.length - 1; i > 0; i--) {
    const j = arr[i] % (i + 1);
    [pwd[i], pwd[j]] = [pwd[j], pwd[i]]
  }
  return pwd.join('')
}

function handleGenerate() {
  const pwd = generatePassword()
  emit('update:modelValue', pwd)
  emit('generate', pwd)
  show.value = true
  navigator.clipboard.writeText(pwd).then(() => {
    copied.value = true
    setTimeout(() => copied.value = false, 2000)
  }).catch(() => {})
}
</script>

<template>
  <div class="relative">
    <input
      :id="id"
      :value="modelValue"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      :type="inputType"
      :class="inputClass"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
    />

    <!-- Generate button (left of eye) -->
    <button
      v-if="withGenerate"
      type="button"
      @click="handleGenerate"
      :title="copied ? 'Скопировано в буфер!' : 'Сгенерировать надёжный пароль'"
      class="absolute right-9 top-1/2 -translate-y-1/2 p-1.5 rounded text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
      aria-label="Сгенерировать пароль"
    >
      <!-- Checkmark when copied -->
      <svg v-if="copied" class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
      </svg>
      <!-- Dice/random icon -->
      <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <rect x="2" y="2" width="20" height="20" rx="3" stroke-width="2"/>
        <circle cx="8"  cy="8"  r="1.2" fill="currentColor"/>
        <circle cx="16" cy="8"  r="1.2" fill="currentColor"/>
        <circle cx="8"  cy="16" r="1.2" fill="currentColor"/>
        <circle cx="16" cy="16" r="1.2" fill="currentColor"/>
        <circle cx="12" cy="12" r="1.2" fill="currentColor"/>
      </svg>
    </button>

    <!-- Eye toggle -->
    <button
      type="button"
      @click="toggleShow"
      :aria-label="show ? 'Скрыть пароль' : 'Показать пароль'"
      class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
    >
      <!-- Eye open -->
      <svg v-if="!show" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
      </svg>
      <!-- Eye closed -->
      <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 4.411m0 0L21 21"/>
      </svg>
    </button>
  </div>
</template>
