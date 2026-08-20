<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'

type Params = Record<string, string | string[]>

type CrumbDef = {
  label: string | ((params: Params) => string)
  to?: string
  parent?: string
}

const CRUMBS: Record<string, CrumbDef> = {
  'dashboard':       { label: 'Главная',        to: '/' },
  'products':        { label: 'Товары',          to: '/products',     parent: 'dashboard' },
  'product-new':     { label: 'Новый товар',                          parent: 'products'  },
  'product-edit':    { label: 'Редактирование',                       parent: 'products'  },
  'orders':          { label: 'Заказы',          to: '/orders',       parent: 'dashboard' },
  'order-detail':    { label: p => `#${String(p.id).slice(0, 8)}`,   parent: 'orders'    },
  'bookings':        { label: 'Записи',          to: '/bookings',     parent: 'dashboard' },
  'booking-detail':  { label: 'Запись',                               parent: 'bookings'  },
  'customers':       { label: 'Клиенты',         to: '/customers',    parent: 'dashboard' },
  'customer-detail': { label: 'Клиент',                               parent: 'customers' },
  'masters':         { label: 'Мастера',         to: '/masters',      parent: 'dashboard' },
  'master-detail':   { label: 'Мастер',                               parent: 'masters'   },
  'discounts':       { label: 'Скидки',          to: '/discounts',    parent: 'dashboard' },
  'reviews':         { label: 'Отзывы',          to: '/reviews',      parent: 'dashboard' },
  'commission':      { label: 'Комиссия',        to: '/commission',   parent: 'dashboard' },
  'settings':        { label: 'Настройки',       to: '/settings',     parent: 'dashboard' },
}

const route = useRoute()

const crumbs = computed(() => {
  const name = route.name as string
  const params = route.params as Params
  const trail: { label: string; to?: string }[] = []

  let current: string | undefined = name
  while (current) {
    const def: CrumbDef | undefined = CRUMBS[current]
    if (!def) break
    const label = typeof def.label === 'function' ? def.label(params) : def.label
    trail.unshift({ label, to: def.to })
    current = def.parent
  }

  // Last item = current page, make non-clickable
  if (trail.length > 0) {
    trail[trail.length - 1] = { label: trail[trail.length - 1].label }
  }

  return trail
})
</script>

<template>
  <nav v-if="crumbs.length > 1" aria-label="Хлебные крошки" class="mb-4 sm:mb-5">
    <ol class="flex items-center gap-x-1 text-sm min-w-0 overflow-hidden">
      <li
        v-for="(crumb, i) in crumbs"
        :key="i"
        :class="['flex items-center gap-1', i < crumbs.length - 1 ? 'flex-shrink-0' : 'min-w-0']"
      >
        <svg
          v-if="i > 0"
          class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600 flex-shrink-0"
          fill="none" stroke="currentColor" viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
        </svg>

        <RouterLink
          v-if="crumb.to"
          :to="crumb.to"
          class="text-gray-400 dark:text-gray-500 hover:text-primary-600 dark:hover:text-primary-400 transition-colors truncate"
        >{{ crumb.label }}</RouterLink>

        <span
          v-else
          class="text-gray-700 dark:text-gray-200 font-medium truncate"
        >{{ crumb.label }}</span>
      </li>
    </ol>
  </nav>
</template>
