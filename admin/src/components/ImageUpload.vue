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
  <!-- Preview -->
  <div v-if="modelValue && !imageError" class="flex items-start gap-3 mb-2">
    <div class="relative flex-shrink-0">
      <img
        :src="modelValue"
        :class="['border border-gray-200 dark:border-gray-700', previewClass, shapeClass,
          objectFit === 'contain' ? 'object-contain bg-white dark:bg-gray-900 p-1' : 'object-cover']"
        alt=""
        @error="imageError = true"
      />
      <div v-if="uploading" :class="['absolute inset-0 bg-black/50 flex items-center justify-center', shapeClass]">
        <div class="animate-spin w-5 h-5 border-2 border-white border-t-transparent rounded-full" />
      </div>
    </div>
    <div v-if="!uploading" class="flex flex-col gap-1.5 pt-1">
      <button type="button" @click="fileInputEl?.click()"
        class="text-xs text-primary-600 dark:text-primary-400 hover:underline text-left">
        Заменить
      </button>
      <button type="button" @click="confirmDel = true"
        class="text-xs text-red-500 hover:text-red-700 flex items-center gap-1 text-left">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
        Удалить
      </button>
    </div>
    <p v-else class="text-xs text-gray-400 pt-1">Загружается...</p>
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

  <!-- URL input -->
  <div v-if="withUrlInput" class="mt-2">
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
