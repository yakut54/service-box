<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import { useCategoriesStore } from '@/stores/categories'
import { api } from '@/lib/api'
import CustomSelect from '@/components/CustomSelect.vue'

const props = withDefaults(defineProps<{
  modelValue: string
  placeholder?: string
  nullable?: boolean   // adds "Без категории" as first option
}>(), {
  placeholder: 'Выберите категорию...',
  nullable: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const categoriesStore = useCategoriesStore()

const newCatMode  = ref(false)
const newCatName  = ref('')
const newCatInput = ref<HTMLInputElement>()
const createError = ref('')

const options = computed(() => {
  const cats = categoriesStore.allCategories.map(c => ({
    value: c.id,
    label: c.parent_id ? `— ${c.name}` : c.name,
  }))
  return props.nullable ? [{ value: '', label: 'Без категории' }, ...cats] : cats
})

onMounted(() => {
  if (!categoriesStore.categories.length) categoriesStore.fetchCategories()
})

async function quickCreate(name: string, close: () => void) {
  if (!name.trim()) return
  createError.value = ''
  try {
    const res = await api.createCategory({ name: name.trim() })
    categoriesStore.categories.push(res.data)
    emit('update:modelValue', res.data.id)
    newCatMode.value = false
    newCatName.value = ''
    close()
  } catch (e: any) {
    createError.value = e.message || 'Не удалось создать категорию'
  }
}

async function startAdd() {
  newCatMode.value = true
  newCatName.value = ''
  await nextTick()
  newCatInput.value?.focus()
}
</script>

<template>
  <div>
    <CustomSelect
      :modelValue="modelValue"
      @update:modelValue="$emit('update:modelValue', $event); newCatMode = false"
      :options="options"
      :placeholder="placeholder"
      searchable
    >
      <template #footer="{ close, search }">
        <!-- Inline input mode -->
        <div v-if="newCatMode" class="px-3 py-2" @mousedown.stop @click.stop>
          <div class="flex gap-2">
            <input
              ref="newCatInput"
              v-model="newCatName"
              type="text"
              class="input text-sm flex-1 py-1.5"
              placeholder="Название категории"
              @keydown.enter.prevent="quickCreate(newCatName, close)"
              @keydown.escape.prevent="newCatMode = false"
            />
            <button
              type="button"
              @click="quickCreate(newCatName, close)"
              class="btn-primary btn-sm px-3"
              :disabled="!newCatName.trim()"
            >✓</button>
            <button
              type="button"
              @click="newCatMode = false"
              class="btn-ghost btn-sm px-2 text-gray-400"
            >✕</button>
          </div>
        </div>
        <!-- Button mode: search → "Создать «query»", else → "+ Добавить категорию" -->
        <button
          v-else
          type="button"
          @click="search ? quickCreate(search, close) : startAdd()"
          class="flex items-center gap-2 px-4 py-2.5 text-sm text-primary-600 dark:text-primary-400 hover:bg-gray-50 dark:hover:bg-gray-700 w-full"
        >
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          <span v-if="search">Создать «{{ search }}»</span>
          <span v-else>Добавить категорию</span>
        </button>
      </template>
    </CustomSelect>
    <p v-if="createError" class="text-xs text-red-500 mt-1">{{ createError }}</p>
  </div>
</template>
