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
  <div ref="containerRef" class="relative">
    <!-- Trigger -->
    <button
      type="button"
      @click="toggle"
      :disabled="disabled"
      :class="[
        'input flex items-center gap-2 text-left w-full',
        open && !disabled ? '!ring-2 !ring-primary-500 !border-transparent' : '',
      ]"
    >
      <span
        class="flex-1 min-w-0 truncate"
        :class="selectedLabel != null ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-500 font-normal'"
      >
        {{ selectedLabel ?? placeholder }}
      </span>
      <svg
        :class="[
          'w-4 h-4 text-gray-400 flex-shrink-0 transition-transform duration-200 ml-auto',
          open ? 'rotate-180' : ''
        ]"
        fill="none" stroke="currentColor" viewBox="0 0 24 24"
      >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <!-- Dropdown -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 translate-y-1 scale-[0.98]"
      enter-to-class="opacity-100 translate-y-0 scale-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100 translate-y-0 scale-100"
      leave-to-class="opacity-0 translate-y-1 scale-[0.98]"
    >
      <div
        v-if="open"
        class="absolute z-50 mt-1 w-full min-w-[180px] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg overflow-hidden"
      >
        <!-- Search -->
        <div v-if="searchable" class="px-2 pt-2 pb-1.5 border-b border-gray-100 dark:border-gray-700">
          <div class="relative">
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input
              ref="searchInputRef"
              v-model="searchQuery"
              type="text"
              class="w-full pl-8 pr-3 py-1.5 text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500 text-gray-900 dark:text-gray-100 placeholder-gray-400"
              placeholder="Поиск..."
              @click.stop
            />
          </div>
        </div>

        <ul class="py-1 max-h-60 overflow-y-auto">
          <li
            v-for="option in filteredOptions"
            :key="option.value"
            @click="select(option.value)"
            :class="[
              'flex items-center justify-between px-4 py-2.5 cursor-pointer select-none text-sm',
              option.value === modelValue
                ? 'text-primary-700 dark:text-primary-300 bg-primary-50 dark:bg-primary-900/30 font-medium'
                : 'text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'
            ]"
          >
            <span class="truncate mr-2">{{ option.label }}</span>
            <svg
              v-if="option.value === modelValue"
              class="w-4 h-4 text-primary-600 flex-shrink-0"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg>
          </li>

          <li v-if="filteredOptions.length === 0" class="px-4 py-3 text-sm text-gray-400 dark:text-gray-500 text-center">
            Ничего не найдено
          </li>
        </ul>

        <!-- Footer slot for "add new" buttons -->
        <div v-if="$slots.footer" class="border-t border-gray-100 dark:border-gray-700">
          <slot name="footer" :close="() => open = false" :search="searchQuery" />
        </div>
      </div>
    </Transition>
  </div>
</template>
