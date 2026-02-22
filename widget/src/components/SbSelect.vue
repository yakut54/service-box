<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'

interface Option {
  value: string
  label: string
}

const props = withDefaults(defineProps<{
  modelValue: string
  options: Option[]
  placeholder?: string
  disabled?: boolean
  searchable?: boolean
}>(), {
  placeholder: 'Выберите...',
  disabled: false,
  searchable: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'change': [value: string]
}>()

const open = ref(false)
const searchQuery = ref('')
const containerRef = ref<HTMLElement>()
const searchInputRef = ref<HTMLInputElement>()
let _root: EventTarget | null = null

const selectedLabel = computed(() =>
  props.options.find(o => o.value === props.modelValue)?.label ?? null
)

const filteredOptions = computed(() => {
  if (!props.searchable || !searchQuery.value.trim()) return props.options
  const q = searchQuery.value.trim().toLowerCase()
  return props.options.filter(o => o.label.toLowerCase().includes(q))
})

watch(open, async (val) => {
  if (!val) {
    searchQuery.value = ''
  } else if (props.searchable) {
    await nextTick()
    searchInputRef.value?.focus()
  }
})

function select(value: string) {
  emit('update:modelValue', value)
  emit('change', value)
  open.value = false
}

function toggle() {
  if (!props.disabled) open.value = !open.value
}

function onOutsideClick(e: Event) {
  if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
    open.value = false
  }
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false
}

onMounted(() => {
  _root = (containerRef.value?.getRootNode() as EventTarget) ?? document
  _root.addEventListener('mousedown', onOutsideClick as EventListener)
  _root.addEventListener('keydown', onKeydown as EventListener)
})

onUnmounted(() => {
  _root?.removeEventListener('mousedown', onOutsideClick as EventListener)
  _root?.removeEventListener('keydown', onKeydown as EventListener)
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
      <span class="sb-select-label" :class="selectedLabel != null ? '' : 'sb-select-placeholder'">
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
        <!-- Search -->
        <div v-if="searchable" class="sb-select-search-wrap">
          <svg class="sb-select-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            ref="searchInputRef"
            v-model="searchQuery"
            type="text"
            class="sb-select-search-input"
            placeholder="Поиск..."
            @click.stop
          />
        </div>

        <ul class="sb-select-list">
          <li
            v-for="option in filteredOptions"
            :key="option.value"
            @click="select(option.value)"
            :class="['sb-select-option', option.value === modelValue ? 'sb-select-option--active' : '']"
          >
            <span class="sb-select-option-label">{{ option.label }}</span>
            <svg
              v-if="option.value === modelValue"
              class="sb-select-check"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg>
          </li>

          <li v-if="filteredOptions.length === 0" class="sb-select-empty">
            Ничего не найдено
          </li>
        </ul>
      </div>
    </Transition>
  </div>
</template>
