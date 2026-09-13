<script setup lang="ts">
/**
 * «Слайд, чтобы подтвердить» — для действий, которые не должны сработать от
 * случайного тапа (завершение сборки заказа). Работает и мышью, и пальцем
 * через Pointer Events (один код для десктопа и мобилки, в отличие от
 * touchstart/touchmove — которых в проекте до этого не было вообще).
 *
 * Не текстовая кнопка: подтверждение = дотянуть бегунок до конца дорожки
 * (>= 85%), иначе бегунок пружинит назад — намеренно требует осознанного
 * жеста, а не одного клика.
 */
import { ref, computed } from 'vue'

const props = withDefaults(defineProps<{
  label?: string
  disabled?: boolean
  loading?: boolean
}>(), {
  label: 'Готово — сдвиньте',
})

const emit = defineEmits<{ confirm: [] }>()

const trackEl = ref<HTMLElement | null>(null)
const dragging = ref(false)
const dragPx = ref(0)
const trackWidth = ref(0)
const THUMB = 48

const progress = computed(() => {
  if (trackWidth.value <= THUMB) return 0
  return Math.min(1, Math.max(0, dragPx.value / (trackWidth.value - THUMB)))
})

function onPointerDown(e: PointerEvent) {
  if (props.disabled || props.loading) return
  const track = trackEl.value
  if (!track) return
  trackWidth.value = track.getBoundingClientRect().width
  dragging.value = true
  ;(e.target as HTMLElement).setPointerCapture(e.pointerId)
  startX.value = e.clientX - dragPx.value
}

const startX = ref(0)

function onPointerMove(e: PointerEvent) {
  if (!dragging.value) return
  dragPx.value = Math.min(trackWidth.value - THUMB, Math.max(0, e.clientX - startX.value))
}

function onPointerUp() {
  if (!dragging.value) return
  dragging.value = false
  if (progress.value >= 0.85) {
    dragPx.value = trackWidth.value - THUMB
    emit('confirm')
  } else {
    dragPx.value = 0
  }
}
</script>

<template>
  <div
    ref="trackEl"
    class="relative h-12 rounded-full bg-gray-100 dark:bg-gray-800 select-none overflow-hidden"
    :class="(disabled || loading) && 'opacity-50'"
  >
    <div
      class="absolute inset-y-0 left-0 bg-green-100 dark:bg-green-900/40 rounded-full"
      :style="{ width: `calc(${THUMB}px + ${progress * 100}%)` }"
    />
    <span class="absolute inset-0 flex items-center justify-center text-sm font-medium text-gray-500 dark:text-gray-400 pointer-events-none">
      {{ loading ? 'Сохранение…' : label }}
    </span>
    <button
      type="button"
      class="absolute top-1 left-1 w-10 h-10 rounded-full bg-primary-600 text-white flex items-center justify-center shadow-sm transition-transform"
      :class="!dragging && 'transition-[transform] duration-200'"
      :style="{ transform: `translateX(${dragPx}px)` }"
      :disabled="disabled || loading"
      aria-label="Сдвиньте, чтобы подтвердить"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
      </svg>
    </button>
  </div>
</template>
