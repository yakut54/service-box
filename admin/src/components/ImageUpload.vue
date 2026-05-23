<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import imageCompression from 'browser-image-compression'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'

const props = withDefaults(defineProps<{
  modelValue?: string | null
  shape?: 'square' | 'circle'
  size?: 'sm' | 'md' | 'lg'
  objectFit?: 'cover' | 'contain'
  withUrlInput?: boolean
  hint?: string
  confirmText?: string
}>(), {
  shape: 'square',
  size: 'md',
  objectFit: 'cover',
  withUrlInput: false,
  hint: 'JPG, PNG, WEBP · сжатие до 1 МБ',
  confirmText: 'Изображение будет удалено. Это действие нельзя отменить.',
})

const emit = defineEmits<{
  'update:modelValue': [url: string | null]
  'update:uploading': [uploading: boolean]
}>()

const uploading   = ref(false)
const isDragging  = ref(false)
const uploadError = ref('')
const imageError  = ref(false)
const confirmDel  = ref(false)
const fileInputEl = ref<HTMLInputElement | null>(null)

const ALLOWED = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']

watch(() => props.modelValue, () => { imageError.value = false })

const previewClass = computed(() => ({
  sm: 'h-12 w-12',
  md: 'h-20 w-20',
  lg: 'h-24 w-24',
}[props.size]))

const shapeClass = computed(() =>
  props.shape === 'circle' ? 'rounded-full' : 'rounded-xl'
)

const padClass = computed(() => ({
  sm: 'p-3',
  md: 'p-4',
  lg: 'p-6',
}[props.size]))

function setUploading(v: boolean) {
  uploading.value = v
  emit('update:uploading', v)
}

function validateFile(file: File): string | null {
  if (!ALLOWED.includes(file.type)) return 'Только JPG, PNG или WEBP'
  return null
}

async function handleFile(file: File) {
  const err = validateFile(file)
  if (err) { uploadError.value = err; return }

  uploadError.value = ''
  imageError.value  = false

  const previewUrl = URL.createObjectURL(file)
  emit('update:modelValue', previewUrl)
  setUploading(true)

  try {
    const toUpload = file.size > 1024 * 1024
      ? await imageCompression(file, { maxSizeMB: 1, maxWidthOrHeight: 1920, useWebWorker: true }) as File
      : file
    const result = await api.uploadImage(toUpload)
    URL.revokeObjectURL(previewUrl)
    emit('update:modelValue', result.url)
  } catch (e: unknown) {
    URL.revokeObjectURL(previewUrl)
    emit('update:modelValue', null)
    uploadError.value = parseApiError(e, 'Не удалось загрузить изображение')
  } finally {
    setUploading(false)
  }
}

function onFileInput(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) handleFile(file)
  if (fileInputEl.value) fileInputEl.value.value = ''
}

function onDrop(e: DragEvent) {
  isDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) handleFile(file)
}

function doDelete() {
  emit('update:modelValue', null)
  uploadError.value = ''
  imageError.value  = false
  confirmDel.value  = false
}

function onUrlInput(e: Event) {
  const val = (e.target as HTMLInputElement).value
  emit('update:modelValue', val || null)
  imageError.value  = false
  uploadError.value = ''
}
</script>

<template>
  <!-- Preview с оверлеем -->
  <div v-if="modelValue && !imageError" class="mb-2">
    <div :class="['relative flex-shrink-0 group overflow-hidden', previewClass, shapeClass]">
      <img
        :src="modelValue"
        :class="['w-full h-full border border-gray-200 dark:border-gray-700', shapeClass,
          objectFit === 'contain' ? 'object-contain bg-white dark:bg-gray-900 p-1' : 'object-cover']"
        alt=""
        @error="imageError = true"
      />
      <!-- Спиннер загрузки -->
      <div v-if="uploading" class="absolute inset-0 bg-black/50 flex items-center justify-center">
        <div class="animate-spin w-5 h-5 border-2 border-white border-t-transparent rounded-full" />
      </div>
      <!-- Оверлей с кнопками при наведении -->
      <div v-else class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors">
        <!-- Заменить (камера, снизу по центру) -->
        <button
          type="button"
          @click.stop="fileInputEl?.click()"
          class="absolute bottom-1.5 left-1/2 -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 hover:bg-white text-gray-700 rounded-full p-1.5 shadow-sm"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
        </button>
        <!-- Удалить (×, правый верхний угол) -->
        <button
          type="button"
          @click.stop="confirmDel = true"
          class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 hover:bg-white text-red-500 hover:text-red-600 rounded-full p-1 shadow-sm"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
    </div>
  </div>

  <!-- Dropzone -->
  <div
    v-else
    @dragenter.prevent="isDragging = true"
    @dragover.prevent="isDragging = true"
    @dragleave.prevent="isDragging = false"
    @drop.prevent="onDrop"
    @click="fileInputEl?.click()"
    :class="[
      'border-2 border-dashed rounded-xl text-center cursor-pointer transition-colors',
      padClass,
      isDragging
        ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
        : 'border-gray-200 dark:border-gray-700 hover:border-primary-400 hover:bg-gray-50 dark:hover:bg-gray-800/50'
    ]"
  >
    <svg v-if="shape === 'circle'"
      class="w-7 h-7 mx-auto mb-1 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
    </svg>
    <svg v-else
      class="w-7 h-7 mx-auto mb-1 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
    </svg>
    <p class="text-sm text-gray-500 dark:text-gray-400">
      <span class="text-primary-600 dark:text-primary-400 font-medium">Нажмите</span>
      {{ isDragging ? ' или отпустите файл' : ' или перетащите фото' }}
    </p>
    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ hint }}</p>
  </div>

  <!-- URL input: только когда картинки нет -->
  <div v-if="withUrlInput && (!modelValue || imageError)" class="mt-2">
    <input
      :value="modelValue ?? ''"
      type="url"
      class="input text-sm"
      placeholder="или вставьте ссылку https://..."
      @input="onUrlInput"
    />
  </div>

  <p v-if="uploadError" class="mt-1 text-xs text-red-500">{{ uploadError }}</p>
  <p v-if="imageError && !uploadError" class="mt-1 text-xs text-red-500">Не удалось загрузить изображение по ссылке</p>

  <input ref="fileInputEl" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFileInput" />

  <UiConfirmDialog v-model="confirmDel" title="Удалить изображение?" confirmLabel="Удалить" @confirm="doDelete">
    {{ confirmText }}
  </UiConfirmDialog>
</template>
