import imageCompression from 'browser-image-compression'

export interface CompressOptions {
  maxSizeMB: number
  maxWidthOrHeight: number
}

/**
 * Приводит файл к WebP. Уже маленькие WebP-файлы не трогаем — незачем
 * гонять их через воркер повторно; всё остальное (JPEG/PNG с телефона,
 * либо WebP крупнее целевого размера) прогоняем через сжатие, даже если
 * по весу укладывается — иначе на диске осталась бы смесь форматов.
 * Используется и обложкой товара (ImageUpload.vue, дефолт — 1 МБ/1920px),
 * и галереей доп. фото (ProductImageGallery.vue — жёстче, 200 КБ/1280px),
 * чтобы логика сжатия не дублировалась в двух местах.
 */
export async function compressIfNeeded(file: File, opts: CompressOptions): Promise<File> {
  const targetBytes = opts.maxSizeMB * 1024 * 1024
  if (file.size <= targetBytes && file.type === 'image/webp') return file

  return await imageCompression(file, {
    maxSizeMB: opts.maxSizeMB,
    maxWidthOrHeight: opts.maxWidthOrHeight,
    useWebWorker: true,
    fileType: 'image/webp',
  }) as File
}
