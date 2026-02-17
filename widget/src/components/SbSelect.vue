<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'

interface Option {
  value: string
  label: string
}

const props = withDefaults(defineProps<{
  modelValue: string
  options: Option[]
  placeholder?: string
  disabled?: boolean
}>(), {
  placeholder: 'Выберите...',
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'change': [value: string]
}>()

const open = ref(false)
const containerRef = ref<HTMLElement>()

const selectedLabel = computed(() =>
  props.options.find(o => o.value === props.modelValue)?.label ?? null
)

function select(value: string) {
  emit('update:modelValue', value)
  emit('change', value)
  open.value = false
}

function toggle() {
  if (!props.disabled) open.value = !open.value
}

function onOutsideClick(e: MouseEvent) {
  if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
    open.value = false
  }
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false
}

onMounted(() => {
  document.addEventListener('mousedown', onOutsideClick)
  document.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  document.removeEventListener('mousedown', onOutsideClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="containerRef" class="sb-select">
    <button
      type="button"
      @click="toggle"
      :disabled="disabled"
      :class="['sb-select-trigger', open && !disabled ? 'sb-select-trigger--open' : '']"
    >
      <span :class="selectedLabel != null ? '' : 'sb-select-placeholder'">
        {{ selectedLabel ?? placeholder }}
      </span>
      <svg
        :class="['sb-select-chevron', open ? 'sb-select-chevron--open' : '']"
        fill="none" stroke="currentColor" viewBox="0 0 24 24"
      >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <Transition name="sb-select">
      <div v-if="open" class="sb-select-dropdown">
        <ul class="sb-select-list">
          <li
            v-for="option in options"
            :key="option.value"
            @click="select(option.value)"
            :class="['sb-select-option', option.value === modelValue ? 'sb-select-option--active' : '']"
          >
            <span>{{ option.label }}</span>
            <svg
              v-if="option.value === modelValue"
              class="sb-select-check"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg>
          </li>
        </ul>
      </div>
    </Transition>
  </div>
</template>
