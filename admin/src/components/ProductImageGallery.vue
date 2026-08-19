<script setup lang="ts">
import { ref, computed } from 'vue'
import { api } from '@/lib/api'
import { parseApiError } from '@/lib/parseApiError'
import { compressIfNeeded } from '@/composables/useImageCompression'
import { UiTooltip } from '@/shared/ui'
import type { ProductImage } from '@/types'

const props = defineProps<{
  productId: string
  images: ProductImage[]
}>()

const emit = defineEmits<{
  'update:images': [images: ProductImage[]]
}>()

const MAX_IMAGES = 8
const ALLOWED = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']

const uploading = ref(false)
const isDragging = ref(false)
const error = ref('')
const fileInputEl = ref<HTMLInputElement | null>(null)

const canAddMore = computed(() => props.images.length < MAX_IMAGES)

async function handleFiles(files: FileList | File[]) {
  const list = Array.from(files).slice(0, MAX_IMAGES - props.images.length)
  if (list.length === 0) return

  error.value = ''
  uploading.value = true
  try {
    for (const file of list) {
      if (!ALLOWED.includes(file.type)) {
        error.value = 'Только JPG, PNG или WEBP'
        continue
      }
      const compressed = await compressIfNeeded(file, { maxSizeMB: 0.2, maxWidthOrHeight: 1280 })
      const uploaded = await api.uploadImage(compressed)
      const attached = await api.attachProductImage(props.productId, uploaded.url)
      emit('update:images', [...props.images, attached.data])
    }
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось загрузить фото')
  } finally {
    uploading.value = false
  }
}

function onFileInput(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (files) handleFiles(files)
  if (fileInputEl.value) fileInputEl.value.value = ''
}

function onDrop(e: DragEvent) {
  isDragging.value = false
  if (e.dataTransfer?.files) handleFiles(e.dataTransfer.files)
}

async function moveImage(index: number, direction: -1 | 1) {
  const target = index + direction
  if (target < 0 || target >= props.images.length) return

  const reordered = [...props.images]
  ;[reordered[index], reordered[target]] = [reordered[target], reordered[index]]
  emit('update:images', reordered)

  await api.reorderProductImages(
    props.productId,
    reordered.map((img, i) => ({ id: img.id, sort_order: i })),
  )
}

async function removeImage(image: ProductImage) {
  emit('update:images', props.images.filter((img) => img.id !== image.id))
  try {
    await api.deleteProductImage(props.productId, image.id)
  } catch (e: unknown) {
    error.value = parseApiError(e, 'Не удалось удалить фото')
  }
}
</script>

<template>
  <div>
    <div class="flex items-center gap-1.5 mb-2">
      <p class="label mb-0">Дополнительные фото ({{ images.length }}/{{ MAX_IMAGES }})</p>
      <UiTooltip align="start">
        <svg class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <template #content>
          Показываются в галерее на карточке товара — байер листает их<br />
          свайпом. Первой всегда идёт обложка сверху, эти фото — после неё.
        </template>
      </UiTooltip>
    </div>

    <div class="flex flex-wrap gap-2">
      <div
        v-for="(image, index) in images"
        :key="image.id"
        class="relative group w-20 h-20 flex-shrink-0 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700"
      >
        <img :src="image.url" class="w-full h-full object-cover" alt="" />
        <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors">
          <button
            v-if="index > 0"
            type="button"
            @click="moveImage(index, -1)"
            class="absolute top-1 left-1 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 hover:bg-white text-gray-700 rounded-full p-1 shadow-sm"
          >
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          </button>
          <button
            v-if="index < images.length - 1"
            type="button"
            @click="moveImage(index, 1)"
            class="absolute bottom-1 left-1 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 hover:bg-white text-gray-700 rounded-full p-1 shadow-sm"
          >
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
          </button>
          <button
            type="button"
            @click="removeImage(image)"
            class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 hover:bg-white text-red-500 hover:text-red-600 rounded-full p-1 shadow-sm"
          >
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
      </div>

      <div
        v-if="canAddMore"
        @dragenter.prevent="isDragging = true"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="onDrop"
        @click="fileInputEl?.click()"
        :class="[
          'w-20 h-20 flex-shrink-0 flex items-center justify-center rounded-xl border-2 border-dashed cursor-pointer transition-colors',
          isDragging
            ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
            : 'border-gray-200 dark:border-gray-700 hover:border-primary-400 hover:bg-gray-50 dark:hover:bg-gray-800/50'
        ]"
      >
        <div v-if="uploading" class="animate-spin w-4 h-4 border-2 border-gray-400 border-t-transparent rounded-full" />
        <svg v-else class="w-6 h-6 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/>
        </svg>
      </div>
    </div>

    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">JPG, PNG, WEBP · сжатие до 200 КБ</p>
    <p v-if="error" class="mt-1 text-xs text-red-500">{{ error }}</p>

    <input ref="fileInputEl" type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="onFileInput" />
  </div>
</template>
