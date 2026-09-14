<script setup lang="ts">
/**
 * Счётчик на пункте меню сайдбара — красный «требует действия»
 * (variant='alert', по умолчанию — 4 существующих места так и вызывают,
 * не трогаем) или серый «всего» (variant='muted' — сколько всего
 * заказов/товаров и т.п., см. NavCountsController). У 'muted' нет ни
 * зажима 99+ (для «всего» это бессмысленно — было бы 999+), ни скрытия
 * при 0 по умолчанию (для total 0 — тоже осмысленная цифра, в отличие
 * от 0 непрочитанных, поэтому showZero отдельным флагом, не завязан на
 * variant).
 */
withDefaults(defineProps<{
  count: number
  variant?: 'alert' | 'muted'
  showZero?: boolean
}>(), {
  variant: 'alert',
  showZero: false,
})
</script>

<template>
  <span
    v-if="count > 0 || showZero"
    :class="[
      'min-w-[1.25rem] h-5 px-1 flex items-center justify-center text-[11px] font-semibold leading-none',
      variant === 'alert'
        ? 'rounded-full bg-red-500 text-white'
        : 'text-gray-400 dark:text-gray-500 font-medium',
    ]"
  >
    {{ count > (variant === 'alert' ? 99 : 999) ? (variant === 'alert' ? '99+' : '999+') : count }}
  </span>
</template>
