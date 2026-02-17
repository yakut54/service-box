<script setup lang="ts">
/**
 * DatePicker — кастомный datepicker с поддержкой dark mode.
 * v-model: string в формате 'YYYY-MM-DD' (совместим с нативным type="date")
 * prop min: строка 'YYYY-MM-DD', дни до неё — недоступны
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: string
  min?: string
  placeholder?: string
  disabled?: boolean
}>(), {
  placeholder: 'Выберите дату',
})

const emit = defineEmits<{
  'update:modelValue': [v: string]
  'change': [v: string]
}>()

// ─── Calendar state ──────────────────────────────────────────────────────────

const open      = ref(false)
const today     = new Date()
today.setHours(0, 0, 0, 0)

function parseYMD(s: string | undefined): Date | null {
  if (!s) return null
  const [y, m, d] = s.split('-').map(Number)
  const dt = new Date(y, m - 1, d)
  return isNaN(dt.getTime()) ? null : dt
}

function toYMD(d: Date): string {
  const y  = d.getFullYear()
  const m  = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${dd}`
}

// Cursor = year+month currently shown
const selected = computed(() => parseYMD(props.modelValue))
const minDate  = computed(() => parseYMD(props.min))

const cursor = ref<Date>(() => {
  const init = selected.value ?? today
  return new Date(init.getFullYear(), init.getMonth(), 1)
}())

// Keep cursor in sync when modelValue changes externally
watch(() => props.modelValue, (v) => {
  const d = parseYMD(v)
  if (d) cursor.value = new Date(d.getFullYear(), d.getMonth(), 1)
})

// ─── Computed calendar cells ─────────────────────────────────────────────────

const MONTH_NAMES = [
  'Январь','Февраль','Март','Апрель','Май','Июнь',
  'Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь',
]
const DOW = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']

const headerLabel = computed(() =>
  `${MONTH_NAMES[cursor.value.getMonth()]} ${cursor.value.getFullYear()}`
)

const cells = computed(() => {
  const year  = cursor.value.getFullYear()
  const month = cursor.value.getMonth()
  const first = new Date(year, month, 1)
  const last  = new Date(year, month + 1, 0)

  // Monday-first: 0=Mon … 6=Sun
  let startDow = first.getDay() // 0=Sun
  startDow = (startDow === 0 ? 6 : startDow - 1) // convert to Mon-first

  const days: Array<{ date: Date; current: boolean }> = []

  // Pad from prev month
  for (let i = startDow - 1; i >= 0; i--) {
    days.push({ date: new Date(year, month, -i), current: false })
  }

  // Current month
  for (let d = 1; d <= last.getDate(); d++) {
    days.push({ date: new Date(year, month, d), current: true })
  }

  // Pad to next month (fill row)
  const remainder = days.length % 7
  if (remainder !== 0) {
    for (let d = 1; d <= 7 - remainder; d++) {
      days.push({ date: new Date(year, month + 1, d), current: false })
    }
  }

  return days
})

function isDisabled(d: Date) {
  if (minDate.value && d < minDate.value) return true
  return false
}

function isToday(d: Date) {
  return d.getTime() === today.getTime()
}

function isSelected(d: Date) {
  return selected.value ? d.getTime() === selected.value.getTime() : false
}

// ─── Navigation ──────────────────────────────────────────────────────────────

function prevMonth() {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() - 1, 1)
}

function nextMonth() {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 1)
}

// ─── Selection ───────────────────────────────────────────────────────────────

function selectDay(d: Date) {
  if (isDisabled(d)) return
  const ymd = toYMD(d)
  emit('update:modelValue', ymd)
  emit('change', ymd)
  open.value = false
}

function clearDate() {
  emit('update:modelValue', '')
  emit('change', '')
}

// ─── Display value ───────────────────────────────────────────────────────────

const displayValue = computed(() => {
  const d = selected.value
  if (!d) return ''
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' })
})

// ─── Close on outside click ──────────────────────────────────────────────────

const root = ref<HTMLElement | null>(null)

function handleOutside(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) {
    open.value = false
  }
}

onMounted(()  => document.addEventListener('mousedown', handleOutside))
onBeforeUnmount(() => document.removeEventListener('mousedown', handleOutside))

// ─── Weekend check ────────────────────────────────────────────────────────────
function isWeekend(d: Date) {
  return d.getDay() === 0 || d.getDay() === 6
}
</script>

<template>
  <div ref="root" class="relative">

    <!-- Trigger button -->
    <button
      type="button"
      :disabled="disabled"
      @click="open = !open"
      :class="[
        'input flex items-center gap-2 text-left w-full select-none',
        disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
        open ? 'ring-2 ring-primary-500 border-primary-500' : '',
      ]"
    >
      <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
        <line x1="16" y1="2" x2="16" y2="6"/>
        <line x1="8" y1="2" x2="8" y2="6"/>
        <line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
      <span :class="displayValue ? 'text-gray-900 dark:text-white' : 'text-gray-400'">
        {{ displayValue || placeholder }}
      </span>
      <span class="flex-1"/>
      <!-- Clear button -->
      <span
        v-if="modelValue"
        @click.stop="clearDate"
        class="ml-auto text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded p-0.5 -mr-1 transition-colors"
        title="Очистить"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </span>
    </button>

    <!-- Dropdown -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 translate-y-1 scale-95"
      enter-to-class="opacity-100 translate-y-0 scale-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100 translate-y-0 scale-100"
      leave-to-class="opacity-0 translate-y-1 scale-95"
    >
      <div
        v-if="open"
        class="absolute z-50 mt-1.5 w-72 origin-top-left bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl shadow-xl shadow-black/10 dark:shadow-black/40 p-4 select-none"
      >
        <!-- Header: prev / month-year / next -->
        <div class="flex items-center justify-between mb-3">
          <button
            type="button"
            @click="prevMonth"
            class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
          </button>

          <span class="text-sm font-semibold text-gray-900 dark:text-white">
            {{ headerLabel }}
          </span>

          <button
            type="button"
            @click="nextMonth"
            class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
          </button>
        </div>

        <!-- Days of week -->
        <div class="grid grid-cols-7 mb-1">
          <div
            v-for="dow in DOW"
            :key="dow"
            :class="[
              'text-center text-xs font-medium pb-2',
              dow === 'Сб' || dow === 'Вс' ? 'text-red-400 dark:text-red-400' : 'text-gray-400 dark:text-gray-500',
            ]"
          >
            {{ dow }}
          </div>
        </div>

        <!-- Day cells -->
        <div class="grid grid-cols-7 gap-y-0.5">
          <button
            v-for="(cell, i) in cells"
            :key="i"
            type="button"
            :disabled="isDisabled(cell.date)"
            @click="selectDay(cell.date)"
            :class="[
              'h-9 w-full flex items-center justify-center rounded-xl text-sm font-medium transition-all',
              // not current month — muted
              !cell.current ? 'text-gray-300 dark:text-gray-700' : '',
              // disabled
              isDisabled(cell.date) ? 'cursor-not-allowed opacity-40' : 'cursor-pointer',
              // weekend color (current month only, not selected, not disabled)
              cell.current && isWeekend(cell.date) && !isSelected(cell.date) && !isDisabled(cell.date)
                ? 'text-red-500 dark:text-red-400' : '',
              // selected
              isSelected(cell.date)
                ? 'bg-primary-600 text-white shadow-md shadow-primary-600/30 scale-105'
                : '',
              // today (not selected)
              isToday(cell.date) && !isSelected(cell.date) && !isDisabled(cell.date)
                ? 'ring-2 ring-primary-500 ring-offset-1 dark:ring-offset-gray-900 text-primary-600 dark:text-primary-400'
                : '',
              // hover (not selected, not disabled)
              !isSelected(cell.date) && !isDisabled(cell.date) && cell.current
                ? 'hover:bg-gray-100 dark:hover:bg-gray-800'
                : '',
              // non-current hover
              !isSelected(cell.date) && !isDisabled(cell.date) && !cell.current
                ? 'hover:bg-gray-50 dark:hover:bg-gray-800/50'
                : '',
            ]"
          >
            {{ cell.date.getDate() }}
          </button>
        </div>

        <!-- Footer: Сегодня / Очистить -->
        <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 flex justify-between text-xs font-medium">
          <button
            type="button"
            v-if="modelValue"
            @click="clearDate"
            class="text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300 transition-colors"
          >
            Удалить
          </button>
          <span v-else/>
          <button
            type="button"
            @click="selectDay(today)"
            :disabled="isDisabled(today)"
            :class="[
              'transition-colors',
              isDisabled(today)
                ? 'text-gray-300 dark:text-gray-700 cursor-not-allowed'
                : 'text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300',
            ]"
          >
            Сегодня
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>
