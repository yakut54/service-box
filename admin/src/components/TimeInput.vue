<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps<{
  modelValue: string  // "HH:MM"
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const open    = ref(false)
const containerRef = ref<HTMLElement | null>(null)
const hoursRef     = ref<HTMLElement | null>(null)

function pad(n: number) {
  return String(n).padStart(2, '0')
}

const parsedHour = computed(() => {
  const h = parseInt(props.modelValue?.split(':')[0] ?? '9')
  return isNaN(h) ? 9 : Math.min(23, Math.max(0, h))
})

const parsedMin = computed(() => {
  const raw = parseInt(props.modelValue?.split(':')[1] ?? '0')
  const m = isNaN(raw) ? 0 : raw
  // snap to nearest 15
  return Math.round(m / 15) * 15 % 60
})

const displayValue = computed(() => `${pad(parsedHour.value)}:${pad(parsedMin.value)}`)

const HOURS   = Array.from({ length: 24 }, (_, i) => i)
const MINUTES = [0, 15, 30, 45]

function setHour(h: number) {
  emit('update:modelValue', `${pad(h)}:${pad(parsedMin.value)}`)
}

function setMinute(m: number) {
  emit('update:modelValue', `${pad(parsedHour.value)}:${pad(m)}`)
  open.value = false
}

function toggle() {
  open.value = !open.value
  if (open.value) {
    nextTick(scrollToHour)
  }
}

function scrollToHour() {
  if (!hoursRef.value) return
  const active = hoursRef.value.querySelector('[data-active="true"]') as HTMLElement | null
  active?.scrollIntoView({ block: 'center', behavior: 'instant' })
}

function onOutside(e: MouseEvent) {
  if (containerRef.value && !containerRef.value.contains(e.target as Node)) {
    open.value = false
  }
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') open.value = false
}

onMounted(() => {
  document.addEventListener('mousedown', onOutside)
  document.addEventListener('keydown', onKeydown)
})
onUnmounted(() => {
  document.removeEventListener('mousedown', onOutside)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="containerRef" class="relative">

    <!-- Trigger -->
    <button
      type="button"
      @click="toggle"
      :class="[
        'input flex items-center gap-2.5 text-left w-full group',
        open ? '!ring-2 !ring-primary-500 !border-transparent' : '',
      ]"
    >
      <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <span class="font-semibold tabular-nums tracking-wide text-gray-900 dark:text-gray-100 text-base leading-none">
        {{ displayValue }}
      </span>
      <svg
        :class="['w-4 h-4 text-gray-400 flex-shrink-0 ml-auto transition-transform duration-200', open ? 'rotate-180' : '']"
        fill="none" stroke="currentColor" viewBox="0 0 24 24"
      >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
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
        class="absolute z-50 mt-1 left-0 w-full min-w-[160px] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-xl overflow-hidden"
      >

        <!-- Header: live preview -->
        <div class="flex items-center justify-between px-4 py-2.5 bg-gray-50 dark:bg-gray-750 border-b border-gray-100 dark:border-gray-700">
          <span class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Час</span>
          <span class="tabular-nums font-bold text-primary-600 dark:text-primary-400 text-sm tracking-wider">
            {{ displayValue }}
          </span>
          <span class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Мин</span>
        </div>

        <!-- Two columns -->
        <div class="flex divide-x divide-gray-100 dark:divide-gray-700">

          <!-- Hours (scrollable, 24 items) -->
          <div ref="hoursRef" class="flex-1 overflow-y-auto" style="max-height: 196px;">
            <button
              v-for="h in HOURS"
              :key="h"
              type="button"
              :data-active="h === parsedHour ? 'true' : undefined"
              @click="setHour(h)"
              :class="[
                'w-full py-2.5 text-sm text-center tabular-nums transition-colors leading-none',
                h === parsedHour
                  ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 font-bold'
                  : 'text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700',
              ]"
            >
              {{ pad(h) }}
            </button>
          </div>

          <!-- Minutes (4 options, no scroll needed) -->
          <div class="flex-1">
            <button
              v-for="m in MINUTES"
              :key="m"
              type="button"
              @click="setMinute(m)"
              :class="[
                'w-full py-2.5 text-sm text-center tabular-nums transition-colors leading-none',
                m === parsedMin
                  ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 font-bold'
                  : 'text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700',
              ]"
            >
              {{ pad(m) }}
            </button>
          </div>

        </div>
      </div>
    </Transition>
  </div>
</template>
