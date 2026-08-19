import imageCompression from 'browser-image-compression'

export interface CompressOptions {
  maxSizeMB: number
  maxWidthOrHeight: number
}

/**
 * Сжимает файл только если он уже больше целевого размера — маленькие
 * файлы не трогаем, незачем гонять их через воркер. Используется и
 * обложкой товара (ImageUpload.vue, дефолт — 1 МБ/1920px), и галереей
 * доп. фото (ProductImageGallery.vue — жёстче, 200 КБ/1280px), чтобы
 * логика сжатия не дублировалась в двух местах.
 */
export async function compressIfNeeded(file: File, opts: CompressOptions): Promise<File> {
  const targetBytes = opts.maxSizeMB * 1024 * 1024
  if (file.size <= targetBytes) return file

  return await imageCompression(file, {
    maxSizeMB: opts.maxSizeMB,
    maxWidthOrHeight: opts.maxWidthOrHeight,
    useWebWorker: true,
  }) as File
}
