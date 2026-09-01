<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  align?: 'start' | 'center' | 'end'
  side?: 'top' | 'bottom'
  bottomPct?: number  // when set, positions tooltip above bar at given % height of the trigger
}>()

// Раньше пузырь был обычным потомком триггера (position:absolute внутри
// .relative-обёртки) — на странице товара main-контент (AppLayout.vue) имеет
// overflow-auto для собственного скролла, и это ЖЁСТКО обрезает любого
// потомка, который выходит за его границы, СОВСЕМ НЕ ГЛЯДЯ на z-index —
// overflow обрезает раньше, чем до стэкинга вообще доходит очередь (поднятый
// в прошлый раз z-index поэтому не мог помочь в принципе). Единственный
// надёжный выход — Teleport пузыря прямо в body, вне зоны обрезки, и
// позиционирование через fixed + координаты триггера, посчитанные в JS.
//
// inheritAttrs: false + ручной v-bind="$attrs" на триггере — обязателен,
// т.к. компонент больше не имеет одного корневого узла (триггер и
// телепортированный пузырь — раздельные корни), а RevenueChart.vue передаёт
// сюда класс со своей flex-раскладкой прямо на <UiTooltip> (сама разметка
// одного столбца графика — см. RevenueChart.vue) — без ручной переадресации
// эти классы улетели бы в пустоту.
const triggerEl = ref<HTMLElement | null>(null)
const boxEl = ref<HTMLElement | null>(null)
const visible = ref(false)
const boxLeft = ref(-9999)
const boxTop = ref(-9999)
const arrowLeftPx = ref(12)

const GAP = 8
const EDGE_MARGIN = 12

async function updatePosition() {
  const trigger = triggerEl.value
  const box = boxEl.value
  if (!trigger || !box) return

  const t = trigger.getBoundingClientRect()
  const b = box.getBoundingClientRect()

  let left: number
  if (props.align === 'start') left = t.left
  else if (props.align === 'end') left = t.right - b.width
  else left = t.left + t.width / 2 - b.width / 2
  left = Math.min(Math.max(left, EDGE_MARGIN), window.innerWidth - EDGE_MARGIN - b.width)

  let top: number
  if (props.bottomPct !== undefined) {
    const bottomY = t.bottom - (t.height * props.bottomPct) / 100 - 10
    top = bottomY - b.height
  } else if (props.side === 'bottom') {
    top = t.bottom + GAP
  } else {
    top = t.top - GAP - b.height
  }

  boxLeft.value = left
  boxTop.value = top
  arrowLeftPx.value = Math.min(Math.max(t.left + t.width / 2 - left, 12), Math.max(b.width - 12, 12))
}

async function show() {
  // Позиция считается ДО того, как visible становится true — иначе fade-in
  // стартует со старых/нулевых координат и tooltip визуально «долетает»
  // до места, а не появляется сразу на нём.
  await nextTick()
  await updatePosition()
  visible.value = true
}

function hide() {
  visible.value = false
}

function onScroll() {
  if (visible.value) hide()
}

onMounted(() => {
  window.addEventListener('resize', updatePosition)
  window.addEventListener('scroll', onScroll, true)
})

onUnmounted(() => {
  window.removeEventListener('resize', updatePosition)
  window.removeEventListener('scroll', onScroll, true)
})
</script>

<template>
  <div
    ref="triggerEl"
    v-bind="$attrs"
    @mouseenter="show"
    @mouseleave="hide"
    @focusin="show"
    @focusout="hide"
  >
    <slot />
  </div>
  <Teleport to="body">
    <div
      :class="[
        // transition (не transition-all!): анимирует только opacity/transform
        // из Tailwind-набора — top/left, которыми задаётся позиция, в него
        // не входят и меняются мгновенно, без наезда на fade-анимацию.
        'fixed z-[9999] pointer-events-none transition duration-150 ease-out',
        visible
          ? 'opacity-100 translate-y-0'
          : side === 'bottom' ? 'opacity-0 -translate-y-1' : 'opacity-0 translate-y-1',
      ]"
      :style="{ top: `${boxTop}px`, left: `${boxLeft}px` }"
    >
      <div
        ref="boxEl"
        class="w-max bg-gray-900 dark:bg-gray-700 text-white text-xs rounded-lg px-2.5 py-2 max-w-[min(300px,calc(100vw-24px))] whitespace-normal shadow-xl ring-1 ring-black/10"
      >
        <slot name="content" />
      </div>
      <!-- Arrow -->
      <div
        v-if="side === 'bottom'"
        class="absolute bottom-full border-4 border-transparent border-b-gray-900 dark:border-b-gray-700"
        :style="{ left: `${arrowLeftPx}px` }"
      />
      <div
        v-else
        class="absolute top-full border-4 border-transparent border-t-gray-900 dark:border-t-gray-700"
        :style="{ left: `${arrowLeftPx}px` }"
      />
    </div>
  </Teleport>
</template>
