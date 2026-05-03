<script setup lang="ts">
import { computed, useId } from 'vue'

const generatedId = useId()

const props = withDefaults(defineProps<{
  modelValue: string
  type?: string
  id?: string
  label?: string
  placeholder?: string
  autocomplete?: string
  disabled?: boolean
  error?: string
}>(), {
  type: 'text',
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'blur': []
}>()

const inputId    = computed(() => props.id ?? generatedId)
const inputClass = computed(() => ['input', props.error ? 'input-error' : ''])
</script>

<template>
  <div>
    <label v-if="label" :for="inputId" class="label">{{ label }}</label>
    <input
      :id="inputId"
      :name="inputId"
      :value="modelValue"
      :type="type"
      :class="inputClass"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
      :disabled="disabled"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      @blur="emit('blur')"
    />
    <p v-if="error" class="mt-1 text-sm text-red-500">{{ error }}</p>
  </div>
</template>
