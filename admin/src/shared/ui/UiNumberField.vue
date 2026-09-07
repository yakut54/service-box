<script setup lang="ts">
/**
 * Числовое поле с кнопками −/+ в теме админки. Замена «колхозным» нативным
 * стрелкам <input type="number"> (они убраны глобально в main.css).
 *
 * Дровово-совместимо с обычным полем: min / max / step / placeholder /
 * disabled. v-model — number | null (пустое поле = null).
 *
 * Не подходит для очень узких ячеек (матрица вариантов, таблица размерной
 * сетки) — там оставляем обычный <input class="input">.
 */
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: number | null
  min?: number
  max?: number
  step?: number
  placeholder?: string
  disabled?: boolean
  id?: string
}>(), {
  step: 1,
})

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>()

defineOptions({ inheritAttrs: false })

const atMin = computed(() =>
  props.min != null && props.modelValue != null && props.modelValue <= props.min)
const atMax = computed(() =>
  props.max != null && props.modelValue != null && props.modelValue >= props.max)

function clamp(n: number): number {
  if (props.min != null && n < props.min) n = props.min
  if (props.max != null && n > props.max) n = props.max
  return n
}

function onInput(e: Event) {
  const raw = (e.target as HTMLInputElement).value
  emit('update:modelValue', raw === '' ? null : Number(raw))
}

function bump(dir: 1 | -1) {
  if (props.disabled) return
  const base = props.modelValue ?? props.min ?? 0
  // округляем, чтобы 0.1 + 0.2 не давало 0.30000000000000004
  const next = clamp(Math.round((base + dir * props.step) * 1e6) / 1e6)
  emit('update:modelValue', next)
}

const btnClass =
  'shrink-0 w-9 flex items-center justify-center rounded-lg border ' +
  'border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 ' +
  'text-gray-600 dark:text-gray-300 ' +
  'hover:bg-gray-100 dark:hover:bg-gray-700 ' +
  'disabled:opacity-40 disabled:cursor-not-allowed transition-colors'
</script>

<template>
  <div class="flex items-stretch gap-1.5" v-bind="$attrs">
    <button type="button" :class="btnClass" :disabled="disabled || atMin" @click="bump(-1)" aria-label="Меньше">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" d="M5 12h14" />
      </svg>
    </button>

    <input
      :id="id"
      type="number"
      inputmode="decimal"
      :value="modelValue ?? ''"
      :min="min"
      :max="max"
      :step="step"
      :placeholder="placeholder"
      :disabled="disabled"
      class="input flex-1 min-w-0 text-center"
      @input="onInput"
      @wheel.prevent
    />

    <button type="button" :class="btnClass" :disabled="disabled || atMax" @click="bump(1)" aria-label="Больше">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" d="M12 5v14M5 12h14" />
      </svg>
    </button>
  </div>
</template>
