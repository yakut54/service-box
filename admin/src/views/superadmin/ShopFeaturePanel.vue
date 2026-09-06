<script setup lang="ts">
import { UiToggle } from '@/shared/ui'
import type { SuperadminShopFeature } from '@/types'

defineProps<{
  shopId: string
  features?: SuperadminShopFeature[]
  loading?: boolean
  error?: string
  /** Ключ `${shopId}:${featureKey}`, который сейчас сохраняется. */
  togglingKey?: string | null
}>()

defineEmits<{ set: [feature: SuperadminShopFeature, next: boolean] }>()
</script>

<template>
  <div>
    <div v-if="loading" class="py-4 text-sm text-gray-400">Загрузка функций…</div>

    <template v-else>
      <div v-if="error" class="text-sm text-red-500 mb-2">{{ error }}</div>

      <div
        v-for="f in features || []"
        :key="f.key"
        class="flex items-start justify-between gap-4 py-2.5 border-b border-gray-200 dark:border-gray-700 last:border-0"
      >
        <div class="min-w-0">
          <div class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ f.label }}</div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ f.description }}</div>
        </div>
        <UiToggle
          :model-value="f.enabled"
          :loading="togglingKey === `${shopId}:${f.key}`"
          @update:model-value="(n: boolean) => $emit('set', f, n)"
        />
      </div>
    </template>
  </div>
</template>
