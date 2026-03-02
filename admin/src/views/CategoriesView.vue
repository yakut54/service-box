<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { api } from '@/lib/api'
import { useCategoriesStore } from '@/stores/categories'
import CustomSelect from '@/components/CustomSelect.vue'
import PageHeader from '@/components/PageHeader.vue'
import UiSpinner from '@/shared/ui/UiSpinner.vue'
import UiEmptyState from '@/shared/ui/UiEmptyState.vue'
import UiModal from '@/shared/ui/UiModal.vue'

const categoriesStore = useCategoriesStore()

// ── State ─────────────────────────────────────────────────────────────────
const error    = ref('')
const toggling = ref<string | null>(null)

// ── Search & sort ──────────────────────────────────────────────────────────
const searchQuery = ref('')
const sortBy      = ref('')

const sortOptions = [
  { value: '',             label: 'По умолчанию' },
  { value: 'name_asc',    label: 'А → Я' },
  { value: 'products_desc', label: 'Больше товаров' },
]

const filteredCategories = computed(() => {
  let list = [...categoriesStore.categories]

  if (searchQuery.value.trim()) {
    const q = searchQuery.value.trim().toLowerCase()
    list = list.filter(c =>
      c.name.toLowerCase().includes(q) ||
      (c.children || []).some((ch: any) => ch.name.toLowerCase().includes(q))
    )
  }

  if (sortBy.value === 'name_asc') {
    list = list.sort((a, b) => a.name.localeCompare(b.name, 'ru'))
  } else if (sortBy.value === 'products_desc') {
    list = list.sort((a, b) => (b.products_count ?? 0) - (a.products_count ?? 0))
  }

  return list
})

// ── Modal create/edit ─────────────────────────────────────────────────────
const showModal  = ref(false)
const modalMode  = ref<'create' | 'edit'>('create')
const editingId  = ref<string | null>(null)
const saving     = ref(false)
const modalError = ref('')

const emptyForm = () => ({
  name:        '',
  parent_id:   '',
  description: '',
  image_url:   '',
  is_visible:  true,
  sort_order:  0,
})

const form = ref(emptyForm())

// ── Image upload ──────────────────────────────────────────────────────────
const imageError  = ref(false)
const uploading   = ref(false)
const isDragging  = ref(false)
const uploadError = ref('')
const fileInputEl = ref<HTMLInputElement | null>(null)

const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']
const MAX_BYTES     = 1 * 1024 * 1024

function validateFile(file: File): string | null {
  if (!ALLOWED_TYPES.includes(file.type)) return 'Только JPG, PNG или WEBP'
  if (file.size > MAX_BYTES) return `Слишком большой (макс. 1 МБ, у вас ${(file.size / 1024 / 1024).toFixed(1)} МБ)`
  return null
}

async function handleFile(file: File) {
  const err = validateFile(file)
  if (err) { uploadError.value = err; return }
  uploadError.value    = ''
  form.value.image_url = URL.createObjectURL(file)
  uploading.value      = true
  try {
    const result         = await api.uploadImage(file)
    form.value.image_url = result.url
  } catch (e: any) {
    form.value.image_url = ''
    uploadError.value    = e.message || 'Не удалось загрузить'
  }
  uploading.value = false
}

function onFileInput(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) handleFile(file)
}

function onDrop(e: DragEvent) {
  isDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) handleFile(file)
}

function clearImage() {
  form.value.image_url = ''
  imageError.value     = false
  uploadError.value    = ''
  if (fileInputEl.value) fileInputEl.value.value = ''
}

// ── Delete flow ────────────────────────────────────────────────────────────
const deleteTarget      = ref<any | null>(null)
const deleteAction      = ref<'nullify' | 'move'>('nullify')
const deleteMoveTarget  = ref('')
const deleteConfirmName = ref('')
const deleting          = ref(false)
const deleteError       = ref('')

const deleteSubcatCount  = computed(() => deleteTarget.value?.children?.length ?? 0)
const deleteProductCount = computed(() => {
  if (!deleteTarget.value) return 0
  const childProducts = (deleteTarget.value.children ?? []).reduce((sum: number, c: any) => sum + (c.products_count ?? 0), 0)
  return (deleteTarget.value.products_count ?? 0) + childProducts
})

const moveTargetOptions = computed(() => {
  if (!deleteTarget.value) return []
  const deletedIds = new Set([
    deleteTarget.value.id,
    ...(deleteTarget.value.children ?? []).map((c: any) => c.id),
  ])
  return categoriesStore.allCategories
    .filter(c => !deletedIds.has(c.id))
    .map(c => ({ value: c.id, label: c.parent_id ? `— ${c.name}` : c.name }))
})

const deleteConfirmValid = computed(() =>
  deleteConfirmName.value.trim() === (deleteTarget.value?.name ?? '')
)

// ── Parent options for modal ──────────────────────────────────────────────
const parentSelectOptions = computed(() => {
  const self = editingId.value
  return [
    { value: '', label: 'Верхний уровень (без родителя)' },
    ...categoriesStore.categories
      .filter(c => !c.parent_id && c.id !== self)
      .map(c => ({ value: c.id, label: c.name })),
  ]
})

// ── Load ───────────────────────────────────────────────────────────────────
onMounted(() => categoriesStore.fetchCategories())

// ── Modal open ─────────────────────────────────────────────────────────────
function openCreate() {
  modalMode.value   = 'create'
  editingId.value   = null
  form.value        = emptyForm()
  modalError.value  = ''
  imageError.value  = false
  uploadError.value = ''
  showModal.value   = true
}

function openEdit(cat: any) {
  modalMode.value   = 'edit'
  editingId.value   = cat.id
  form.value = {
    name:        cat.name,
    parent_id:   cat.parent_id ?? '',
    description: cat.description ?? '',
    image_url:   cat.image_url ?? '',
    is_visible:  cat.is_visible,
    sort_order:  cat.sort_order,
  }
  modalError.value  = ''
  imageError.value  = false
  uploadError.value = ''
  showModal.value   = true
}

// ── Save ───────────────────────────────────────────────────────────────────
async function save() {
  if (!form.value.name.trim()) { modalError.value = 'Укажите название'; return }
  saving.value     = true
  modalError.value = ''
  try {
    const payload: Record<string, any> = {
      name:        form.value.name.trim(),
      parent_id:   form.value.parent_id || null,
      description: form.value.description.trim() || null,
      image_url:   form.value.image_url.trim() || null,
      is_visible:  form.value.is_visible,
      sort_order:  form.value.sort_order,
    }
    if (modalMode.value === 'create') {
      await api.createCategory(payload)
    } else {
      await api.updateCategory(editingId.value!, payload)
    }
    showModal.value = false
    await categoriesStore.fetchCategories()
  } catch (e: any) {
    modalError.value = e.message || 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}

// ── Toggle visibility ──────────────────────────────────────────────────────
async function toggleVisible(cat: any) {
  toggling.value = cat.id
  try {
    await api.updateCategory(cat.id, { is_visible: !cat.is_visible })
    await categoriesStore.fetchCategories()
  } catch { /* silent */ } finally {
    toggling.value = null
  }
}

// ── Delete modal open ──────────────────────────────────────────────────────
function openDelete(cat: any) {
  deleteTarget.value      = cat
  deleteAction.value      = 'nullify'
  deleteMoveTarget.value  = ''
  deleteConfirmName.value = ''
  deleteError.value       = ''
}

// ── Hide (soft action) ─────────────────────────────────────────────────────
async function hideCategory(cat: any) {
  try {
    await api.deleteCategory(cat.id, { action: 'hide' })
    await categoriesStore.fetchCategories()
  } catch (e: any) {
    error.value = e.message || 'Ошибка'
  }
}

// ── Permanent delete ───────────────────────────────────────────────────────
async function doDelete() {
  if (!deleteTarget.value || !deleteConfirmValid.value) return
  deleting.value    = true
  deleteError.value = ''
  try {
    const params: Record<string, string> = {
      action:   'delete',
      products: deleteAction.value,
    }
    if (deleteAction.value === 'move' && deleteMoveTarget.value) {
      params.target_id = deleteMoveTarget.value
    }
    await api.deleteCategory(deleteTarget.value.id, params)
    deleteTarget.value = null
    await categoriesStore.fetchCategories()
  } catch (e: any) {
    deleteError.value = e.message || 'Ошибка удаления'
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="space-y-6">

    <!-- Header -->
    <PageHeader
      title="Категории"
      :subtitle="`${categoriesStore.categories.length} ${categoriesStore.categories.length === 1 ? 'категория' : categoriesStore.categories.length < 5 ? 'категории' : 'категорий'}`"
    >
      <button @click="openCreate" class="btn-primary shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Добавить</span>
      </button>
    </PageHeader>

    <!-- Search + sort -->
    <div v-if="!categoriesStore.loading && categoriesStore.categories.length > 0" class="card">
      <div class="flex flex-col sm:flex-row gap-3">
        <input v-model="searchQuery" type="text" class="input flex-1" placeholder="Поиск по названию..." />
        <CustomSelect v-model="sortBy" :options="sortOptions" class="w-full sm:w-48 shrink-0" />
      </div>
    </div>

    <!-- Error -->
    <div v-if="error || categoriesStore.error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
      {{ error || categoriesStore.error }}
    </div>

    <!-- Loading -->
    <div v-if="categoriesStore.loading" class="card flex items-center justify-center py-16">
      <UiSpinner />
    </div>

    <!-- Empty -->
    <UiEmptyState
      v-else-if="categoriesStore.categories.length === 0"
      title="Нет категорий"
      description="Создайте первую категорию для товаров"
    >
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
        </svg>
      </template>
      <button @click="openCreate" class="btn-primary">Создать категорию</button>
    </UiEmptyState>

    <!-- Empty search result -->
    <div v-else-if="filteredCategories.length === 0 && searchQuery" class="card py-10 text-center text-gray-500 dark:text-gray-400 text-sm">
      Ничего не найдено по «{{ searchQuery }}»
    </div>

    <!-- Categories list -->
    <div v-else class="space-y-2">
      <template v-for="cat in filteredCategories" :key="cat.id">

        <!-- Parent category -->
        <div class="card p-0">
          <div class="flex items-center gap-3 px-4 py-3">
            <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-700 flex-shrink-0 overflow-hidden">
              <img v-if="cat.image_url" :src="cat.image_url" :alt="cat.name" class="w-full h-full object-cover" />
              <div v-else class="w-full h-full flex items-center justify-center">
                <svg class="w-5 h-5 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
            </div>

            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-medium text-gray-900 dark:text-white truncate">{{ cat.name }}</span>
                <span v-if="!cat.is_visible" class="badge bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 text-xs">Скрыта</span>
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                <RouterLink v-if="(cat.products_count ?? 0) > 0" :to="{ path: '/products', query: { category_id: cat.id } }" class="text-primary-600 dark:text-primary-400 hover:underline">
                  {{ cat.products_count ?? 0 }} {{ (cat.products_count ?? 0) === 1 ? 'товар' : (cat.products_count ?? 0) < 5 ? 'товара' : 'товаров' }}
                </RouterLink>
                <span v-else>0 товаров</span>
                <template v-if="cat.children && cat.children.length > 0">
                  · {{ cat.children.length }} подкатегор{{ cat.children.length === 1 ? 'ия' : 'ии' }}
                </template>
              </div>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
              <button @click="toggleVisible(cat)" :disabled="toggling === cat.id" :class="['relative w-9 h-5 rounded-full transition-colors flex-shrink-0', cat.is_visible ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600']" :title="cat.is_visible ? 'Скрыть' : 'Показать'">
                <span :class="['absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', cat.is_visible ? 'translate-x-4' : '']" />
              </button>
              <button @click="openEdit(cat)" class="btn-ghost btn-sm text-xs px-2">Изменить</button>
              <button @click="openDelete(cat)" class="text-gray-400 hover:text-red-500 transition-colors p-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Children -->
          <div v-if="cat.children && cat.children.length > 0" class="border-t border-gray-100 dark:border-gray-700">
            <div
              v-for="child in cat.children"
              :key="child.id"
              class="flex items-center gap-3 px-4 py-2.5 pl-10 border-b border-gray-50 dark:border-gray-700/50 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/30"
            >
              <div class="w-8 h-8 rounded-md bg-gray-100 dark:bg-gray-700 flex-shrink-0 overflow-hidden">
                <img v-if="child.image_url" :src="child.image_url" :alt="child.name" class="w-full h-full object-cover" />
                <div v-else class="w-full h-full flex items-center justify-center">
                  <svg class="w-4 h-4 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                </div>
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ child.name }}</span>
                  <span v-if="!child.is_visible" class="badge bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 text-xs">Скрыта</span>
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                  <RouterLink v-if="(child.products_count ?? 0) > 0" :to="{ path: '/products', query: { category_id: child.id } }" class="text-primary-600 dark:text-primary-400 hover:underline">
                    {{ child.products_count ?? 0 }} {{ (child.products_count ?? 0) === 1 ? 'товар' : (child.products_count ?? 0) < 5 ? 'товара' : 'товаров' }}
                  </RouterLink>
                  <span v-else>0 товаров</span>
                </div>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <button @click="toggleVisible(child)" :disabled="toggling === child.id" :class="['relative w-9 h-5 rounded-full transition-colors', child.is_visible ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600']">
                  <span :class="['absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform', child.is_visible ? 'translate-x-4' : '']" />
                </button>
                <button @click="openEdit(child)" class="btn-ghost btn-sm text-xs px-2">Изменить</button>
                <button @click="openDelete(child)" class="text-gray-400 hover:text-red-500 transition-colors p-1">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </div>
          </div>
        </div>

      </template>
    </div>

    <!-- ── Create / Edit Modal ───────────────────────────────────────── -->
    <UiModal v-model="showModal">
      <!-- Header -->
      <div class="flex items-center justify-between p-4 border-b border-gray-100 dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
          {{ modalMode === 'create' ? 'Новая категория' : 'Редактировать категорию' }}
        </h2>
        <button @click="showModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Body -->
      <div class="p-4 space-y-4">
        <div v-if="modalError" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
          {{ modalError }}
        </div>

        <div>
          <label class="label">Название <span class="text-red-500">*</span></label>
          <input v-model="form.name" type="text" class="input" placeholder="Например: Косметика" />
        </div>

        <div>
          <label class="label">Родительская категория</label>
          <CustomSelect v-model="form.parent_id" :options="parentSelectOptions" placeholder="Верхний уровень (без родителя)" searchable />
          <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Поддерживается только 1 уровень вложенности</p>
        </div>

        <div>
          <label class="label">Описание</label>
          <textarea v-model="form.description" class="input min-h-[80px]" placeholder="Краткое описание категории" />
        </div>

        <div>
          <label class="label">Изображение</label>
          <div v-if="form.image_url && !imageError" class="flex items-start gap-3">
            <div class="relative flex-shrink-0">
              <img :src="form.image_url" @error="imageError = true" class="h-20 w-20 object-cover rounded-xl border border-gray-200 dark:border-gray-700" alt="Превью" />
              <div v-if="uploading" class="absolute inset-0 bg-black/50 rounded-xl flex items-center justify-center">
                <div class="animate-spin w-5 h-5 border-2 border-white border-t-transparent rounded-full"></div>
              </div>
            </div>
            <div v-if="!uploading" class="flex flex-col gap-1.5 pt-1">
              <button type="button" @click="fileInputEl?.click()" class="text-xs text-primary-600 dark:text-primary-400 hover:underline text-left">Заменить</button>
              <button type="button" @click="clearImage" class="text-xs text-red-500 hover:text-red-700 text-left">Удалить</button>
            </div>
            <p v-else class="text-xs text-gray-400 pt-1">Загружается...</p>
          </div>
          <div
            v-else
            @dragenter.prevent="isDragging = true"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop"
            @click="fileInputEl?.click()"
            :class="[
              'border-2 border-dashed rounded-xl p-5 text-center cursor-pointer transition-colors',
              isDragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-primary-400 hover:bg-gray-50 dark:hover:bg-gray-800/50'
            ]"
          >
            <svg class="w-7 h-7 mx-auto mb-2 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <p class="text-sm text-gray-500 dark:text-gray-400">
              <span class="text-primary-600 dark:text-primary-400 font-medium">Нажмите</span>
              {{ isDragging ? ' или отпустите файл' : ' или перетащите фото' }}
            </p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">JPG, PNG, WEBP · до 1 МБ</p>
          </div>
          <div class="mt-2">
            <input v-model="form.image_url" type="url" class="input text-sm" placeholder="или вставьте ссылку https://..." @input="imageError = false; uploadError = ''" />
          </div>
          <p v-if="uploadError" class="mt-1 text-xs text-red-500">{{ uploadError }}</p>
          <p v-if="imageError" class="mt-1 text-xs text-red-500">Не удалось загрузить изображение</p>
          <input ref="fileInputEl" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFileInput" />
        </div>

        <div class="flex items-center justify-between py-2">
          <div>
            <span class="label mb-0">Видна на витрине</span>
            <p class="text-xs text-gray-400">Показывать в публичном каталоге</p>
          </div>
          <button type="button" @click="form.is_visible = !form.is_visible" :class="['relative w-11 h-6 rounded-full transition-colors', form.is_visible ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-600']">
            <span :class="['absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition-transform', form.is_visible ? 'translate-x-5' : '']" />
          </button>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex gap-3 p-4 pt-0">
        <button @click="showModal = false" class="btn-secondary flex-1">Отмена</button>
        <button @click="save" class="btn-primary flex-1" :disabled="saving">
          {{ saving ? 'Сохранение...' : (modalMode === 'create' ? 'Создать' : 'Сохранить') }}
        </button>
      </div>
    </UiModal>

    <!-- ── Delete Modal ───────────────────────────────────────────────── -->
    <UiModal :modelValue="!!deleteTarget" @update:modelValue="!$event && (deleteTarget = null)" maxWidth="max-w-md">
      <div v-if="deleteTarget" class="p-6 space-y-5">
        <div>
          <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Удалить «{{ deleteTarget.name }}»?</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            <template v-if="deleteSubcatCount > 0">
              {{ deleteSubcatCount }} подкатегор{{ deleteSubcatCount === 1 ? 'ия' : 'ии' }},
            </template>
            {{ deleteProductCount }} {{ deleteProductCount === 1 ? 'товар' : deleteProductCount < 5 ? 'товара' : 'товаров' }} в этой категории
          </p>
        </div>

        <div class="p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
          <p class="text-sm font-medium text-amber-800 dark:text-amber-300 mb-2">Рекомендуется: скрыть с витрины</p>
          <p class="text-xs text-amber-700 dark:text-amber-400 mb-3">Категория и товары остаются в системе, просто не отображаются клиентам</p>
          <button @click="hideCategory(deleteTarget); deleteTarget = null" class="btn-secondary btn-sm text-amber-700 dark:text-amber-400 border-amber-300 dark:border-amber-700 hover:bg-amber-100 dark:hover:bg-amber-900/30">
            Скрыть с витрины
          </button>
        </div>

        <div class="border-t border-gray-100 dark:border-gray-700 pt-4 space-y-4">
          <p class="text-sm font-medium text-red-600 dark:text-red-400">Удалить навсегда</p>
          <div class="space-y-2">
            <label class="flex items-start gap-3 cursor-pointer">
              <input type="radio" v-model="deleteAction" value="nullify" class="mt-0.5 text-primary-600" />
              <span class="text-sm text-gray-700 dark:text-gray-300">Оставить товары бескатегорийными</span>
            </label>
            <label class="flex items-start gap-3 cursor-pointer">
              <input type="radio" v-model="deleteAction" value="move" class="mt-0.5 text-primary-600" />
              <span class="text-sm text-gray-700 dark:text-gray-300">Перенести товары в другую категорию</span>
            </label>
          </div>
          <div v-if="deleteAction === 'move'" class="pl-6">
            <CustomSelect v-model="deleteMoveTarget" :options="moveTargetOptions" placeholder="Выберите категорию..." searchable />
          </div>
          <div>
            <label class="label text-sm">Введите <strong>{{ deleteTarget.name }}</strong> для подтверждения</label>
            <input v-model="deleteConfirmName" type="text" class="input" :placeholder="deleteTarget.name" />
          </div>
          <p v-if="deleteError" class="text-xs text-red-500">{{ deleteError }}</p>
          <div class="flex gap-3">
            <button @click="deleteTarget = null" class="btn-secondary flex-1">Отмена</button>
            <button @click="doDelete" class="btn-danger flex-1" :disabled="deleting || !deleteConfirmValid || (deleteAction === 'move' && !deleteMoveTarget)">
              {{ deleting ? 'Удаление...' : 'Удалить навсегда' }}
            </button>
          </div>
        </div>
      </div>
    </UiModal>

  </div>
</template>
