<script setup lang="ts">
/**
 * Редактор списка пар «ключ: значение» — повторяемые строки с кнопками
 * добавить/удалить. Канонический компонент для любых наборов пар в админке
 * (характеристики товара, значения опций и т.п.), не верстать заново.
 */
import { computed } from 'vue'

export interface KeyValueRow {
  label: string
  value: string
}

const props = withDefaults(defineProps<{
  modelValue: KeyValueRow[]
  labelPlaceholder?: string
  valuePlaceholder?: string
  addLabel?: string
  /** Подсказки для поля «ключ» — рендерятся как <datalist>. */
  suggestions?: string[]
  max?: number
}>(), {
  labelPlaceholder: 'Характеристика',
  valuePlaceholder: 'Значение',
  addLabel: '+ Добавить строку',
  suggestions: () => [],
  max: 30,
})

const emit = defineEmits<{ 'update:modelValue': [rows: KeyValueRow[]] }>()

const rows = computed(() => props.modelValue)
const canAdd = computed(() => rows.value.length < props.max)

// Стабильный id для <datalist>, чтобы несколько редакторов на странице не пересекались.
const listId = `kv-suggest-${Math.random().toString(36).slice(2, 9)}`

function update(next: KeyValueRow[]) {
  emit('update:modelValue', next)
}

function setField(index: number, field: keyof KeyValueRow, val: string) {
  update(rows.value.map((r, i) => (i === index ? { ...r, [field]: val } : r)))
}

function addRow() {
  if (!canAdd.value) return
  update([...rows.value, { label: '', value: '' }])
}

function removeRow(index: number) {
  update(rows.value.filter((_, i) => i !== index))
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <datalist v-if="suggestions.length" :id="listId">
      <option v-for="s in suggestions" :key="s" :value="s" />
    </datalist>

    <div
      v-for="(row, i) in rows"
      :key="i"
      class="flex items-start gap-2"
    >
      <input
        :value="row.label"
        @input="setField(i, 'label', ($event.target as HTMLInputElement).value)"
        type="text"
        class="input flex-1 min-w-0"
        :placeholder="labelPlaceholder"
        :list="suggestions.length ? listId : undefined"
      />
      <input
        :value="row.value"
        @input="setField(i, 'value', ($event.target as HTMLInputElement).value)"
        type="text"
        class="input flex-1 min-w-0"
        :placeholder="valuePlaceholder"
      />
      <button
        type="button"
        @click="removeRow(i)"
        class="shrink-0 h-10 w-10 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
        aria-label="Удалить строку"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <button
      type="button"
      @click="addRow"
      :disabled="!canAdd"
      class="self-start text-sm font-medium text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 disabled:opacity-40 disabled:cursor-not-allowed"
    >
      {{ addLabel }}
    </button>
  </div>
</template>
