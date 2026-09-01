<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps<{
  align?: 'start' | 'center' | 'end'
  side?: 'top' | 'bottom'
  bottomPct?: number  // when set, positions tooltip above bar at given % height
}>()

// Tailwind-классы (left-0/right-0/center) дают только СТАРТОВОЕ положение
// относительно триггера — этого достаточно на широком экране, но у ⓘ в
// правой колонке узкой сетки (320-360px) пузырь всё равно вылезает за край
// вьюпорта. Замеряем реальный прямоугольник после раскладки и докручиваем
// коррекцию через transform.
//
// Коррекция идёт ПРЯМО на пузырь и на стрелку (не на общую обёртку вокруг
// них): обёртка пробовалась первой — простой div без своих классов между
// уже-спозиционированным родителем и пузырём с max-width ломает shrink-to-fit
// у родителя (браузер считает предпочтительную ширину не по max-width пузыря,
// а по самому длинному неразрывному слову) — пузырь визуально сжимался ДО
// max-width, а не после него. На самом пузыре transform раньше не был занят
// ничем (только у стрелки при align="center" уже висит -translate-x-1/2 —
// её JS-коррекция дописывается к этому значению, не заменяет).
const boxEl = ref<HTMLElement | null>(null)
const shiftPx = ref(0)
const EDGE_MARGIN = 12

const boxShiftStyle = computed(() =>
  shiftPx.value === 0 ? undefined : { transform: `translateX(${shiftPx.value}px)` },
)
// Стрелка при align="center" уже центрируется через translateX(-50%) —
// JS-коррекция дописывается к этому значению, а не заменяет его.
const arrowTransformStyle = computed(() => {
  const base = props.align === 'start' || props.align === 'end' ? '' : 'translateX(-50%) '
  const shift = shiftPx.value === 0 ? '' : `translateX(${shiftPx.value}px)`
  const combined = `${base}${shift}`.trim()
  return combined === '' ? undefined : { transform: combined }
})

async function updatePosition() {
  const el = boxEl.value
  if (!el) return
  shiftPx.value = 0
  await nextTick()
  const rect = el.getBoundingClientRect()
  const viewportWidth = window.innerWidth
  if (rect.left < EDGE_MARGIN) {
    shiftPx.value = EDGE_MARGIN - rect.left
  } else if (rect.right > viewportWidth - EDGE_MARGIN) {
    shiftPx.value = viewportWidth - EDGE_MARGIN - rect.right
  }
}

onMounted(() => {
  updatePosition()
  window.addEventListener('resize', updatePosition)
})

onUnmounted(() => {
  window.removeEventListener('resize', updatePosition)
})
</script>

<template>
  <div class="relative group" @mouseenter="updatePosition" @focusin="updatePosition">
    <slot />
    <div
      :class="[
        'absolute z-50 pointer-events-none',
        side === 'bottom'
          ? 'opacity-0 -translate-y-1 group-hover:opacity-100 group-hover:translate-y-0 group-focus-within:opacity-100 group-focus-within:translate-y-0 transition-all duration-150 ease-out top-full mt-2'
          : 'opacity-0 translate-y-1 group-hover:opacity-100 group-hover:translate-y-0 group-focus-within:opacity-100 group-focus-within:translate-y-0 transition-all duration-150 ease-out bottom-full mb-2',
        align === 'start' ? 'left-0' : align === 'end' ? 'right-0' : 'left-1/2 -translate-x-1/2',
      ]"
      :style="bottomPct !== undefined ? { bottom: `calc(${bottomPct}% + 10px)` } : undefined"
    >
      <div
        ref="boxEl"
        class="bg-gray-900 dark:bg-gray-700 text-white text-xs rounded-lg px-2.5 py-2 max-w-[min(300px,calc(100vw-24px))] whitespace-normal shadow-xl ring-1 ring-black/10"
        :style="boxShiftStyle"
      >
        <slot name="content" />
      </div>
      <!-- Arrow -->
      <div
        v-if="side === 'bottom'"
        :class="[
          'absolute bottom-full border-4 border-transparent border-b-gray-900 dark:border-b-gray-700',
          align === 'start' ? 'left-3' : align === 'end' ? 'right-3' : 'left-1/2',
        ]"
        :style="arrowTransformStyle"
      />
      <div
        v-else
        :class="[
          'absolute top-full border-4 border-transparent border-t-gray-900 dark:border-t-gray-700',
          align === 'start' ? 'left-3' : align === 'end' ? 'right-3' : 'left-1/2',
        ]"
        :style="arrowTransformStyle"
      />
    </div>
  </div>
</template>
