<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api } from '@/lib/api'
import { plural } from '@/lib/utils'
import { formatPrice, formatDate } from '@/shared/lib/format'
import { UiSpinner, UiEmptyState } from '@/shared/ui'
import PageHeader from '@/components/PageHeader.vue'
import UiModal from '@/shared/ui/UiModal.vue'

const customers = ref<any[]>([])
const loading = ref(true)
const searchQuery = ref('')

const sortedCustomers = computed(() => {
  return [...customers.value].sort((a, b) => (b.total_spent || 0) - (a.total_spent || 0))
})

const totalCustomers = computed(() => customers.value.length)
const totalRevenue = computed(() => customers.value.reduce((sum, c) => sum + (c.total_spent || 0), 0))
const avgOrderValue = computed(() => {
  const totalOrders = customers.value.reduce((sum, c) => sum + (c.total_orders || 0), 0)
  return totalOrders > 0 ? totalRevenue.value / totalOrders : 0
})

async function loadCustomers() {
  loading.value = true
  try {
    const params: Record<string, string> = {}
    if (searchQuery.value.trim()) params.search = searchQuery.value.trim()
    const data = await api.getCustomers(params)
    customers.value = data.data
  } catch { /* ignore */ }
  loading.value = false
}

function onSearch() {
  loadCustomers()
}

// ── Delete ─────────────────────────────────────────────────────────────────
const deleteTarget = ref<any>(null)
const deleteConfirmName = ref('')
const deleting = ref(false)
const deleteError = ref('')

const deleteConfirmValid = computed(() =>
  deleteConfirmName.value.trim() === (deleteTarget.value?.name ?? '').trim()
)

function openDelete(c: any) {
  deleteTarget.value = c
  deleteConfirmName.value = ''
  deleteError.value = ''
}

async function doDelete() {
  if (!deleteConfirmValid.value) return
  deleting.value = true
  deleteError.value = ''
  try {
    await api.deleteCustomer(deleteTarget.value.id)
    customers.value = customers.value.filter(c => c.id !== deleteTarget.value.id)
    deleteTarget.value = null
  } catch (e: any) {
    deleteError.value = e.message || 'Ошибка удаления'
  } finally {
    deleting.value = false
  }
}

onMounted(() => { loadCustomers() })
</script>

<template>
  <div>
    <!-- Header -->
    <PageHeader
      class="mb-6"
      title="Клиенты"
      :subtitle="`${totalCustomers} ${plural(totalCustomers, 'клиент', 'клиента', 'клиентов')}`"
    />

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-3 sm:gap-4 mb-6">
      <div class="card">
        <div class="text-[10px] sm:text-sm text-gray-500 dark:text-gray-400 leading-tight"><span class="sm:hidden">Клиентов</span><span class="hidden sm:inline">Всего клиентов</span></div>
        <div class="text-base sm:text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ totalCustomers }}</div>
      </div>
      <div class="card">
        <div class="text-[10px] sm:text-sm text-gray-500 dark:text-gray-400 leading-tight"><span class="sm:hidden">Выручка</span><span class="hidden sm:inline">Общая выручка</span></div>
        <div class="text-base sm:text-2xl font-bold text-green-600 dark:text-green-400 mt-1">{{ formatPrice(totalRevenue) }}</div>
      </div>
      <div class="card">
        <div class="text-[10px] sm:text-sm text-gray-500 dark:text-gray-400 leading-tight">Ср. чек<span class="hidden sm:inline">овой</span></div>
        <div class="text-base sm:text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ formatPrice(avgOrderValue) }}</div>
      </div>
    </div>

    <!-- Search -->
    <div class="card mb-6">
      <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
          <input
            v-model="searchQuery"
            @input="onSearch"
            type="text"
            class="input"
            placeholder="Поиск по имени, телефону или email..."
          />
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="card py-12 flex justify-center">
      <UiSpinner />
    </div>

    <!-- Empty -->
    <UiEmptyState v-else-if="customers.length === 0" title="Нет клиентов" description="Клиенты появятся после первого заказа или записи">
      <template #icon>
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
      </template>
    </UiEmptyState>

    <!-- Table -->
    <div v-else>
      <!-- Desktop table -->
      <div class="card overflow-hidden p-0 hidden sm:block">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Клиент</th>
                <th>Телефон</th>
                <th>Email</th>
                <th>Заказов</th>
                <th>Потрачено</th>
                <th>Последний заказ</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in sortedCustomers" :key="c.id">
                <td>
                  <RouterLink :to="`/customers/${c.id}`" class="font-medium text-gray-900 dark:text-gray-200 hover:text-primary-600 dark:hover:text-primary-400">
                    {{ c.name || 'Без имени' }}
                  </RouterLink>
                </td>
                <td>
                  <a v-if="c.phone" :href="`tel:${c.phone}`" class="text-primary-600 dark:text-primary-400 hover:text-primary-700">{{ c.phone }}</a>
                  <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                </td>
                <td>
                  <a v-if="c.email" :href="`mailto:${c.email}`" class="text-gray-600 dark:text-gray-400 hover:text-primary-600 text-sm">{{ c.email }}</a>
                  <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                </td>
                <td><span class="font-medium dark:text-gray-200">{{ c.total_orders || 0 }}</span></td>
                <td><span class="font-semibold text-gray-900 dark:text-white">{{ formatPrice(c.total_spent || 0) }}</span></td>
                <td class="text-sm text-gray-500 dark:text-gray-400">{{ formatDate(c.last_order_at) }}</td>
                <td>
                  <div class="flex items-center gap-1">
                    <button
                      type="button"
                      @click.prevent="openDelete(c)"
                      class="btn-ghost btn-sm text-gray-400 hover:text-red-500 dark:hover:text-red-400"
                      title="Удалить клиента"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                    <RouterLink :to="`/customers/${c.id}`" class="btn-ghost btn-sm">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    </RouterLink>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile cards -->
      <div class="sm:hidden card overflow-hidden p-0">
        <div
          v-for="c in sortedCustomers"
          :key="c.id"
          class="flex items-center gap-3 p-4 border-b border-gray-100 dark:border-gray-800 last:border-0"
        >
          <RouterLink :to="`/customers/${c.id}`" class="flex-1 min-w-0 flex items-center justify-between gap-3 hover:opacity-80 transition-opacity">
            <div class="min-w-0">
              <div class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">{{ c.name || 'Без имени' }}</div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">{{ c.phone || c.email || '—' }}</div>
            </div>
            <div class="text-right shrink-0">
              <div class="font-semibold text-sm text-gray-900 dark:text-white">{{ formatPrice(c.total_spent || 0) }}</div>
              <div class="text-xs text-gray-400 dark:text-gray-500">{{ c.total_orders || 0 }} {{ plural(c.total_orders || 0, 'заказ', 'заказа', 'заказов') }}</div>
            </div>
          </RouterLink>
          <button
            type="button"
            @click="openDelete(c)"
            class="shrink-0 p-1.5 text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition-colors rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Delete Modal ──────────────────────────────────────────────────────── -->
  <UiModal :modelValue="!!deleteTarget" @update:modelValue="!$event && (deleteTarget = null)" maxWidth="max-w-md">
    <div v-if="deleteTarget" class="p-6 space-y-5">
      <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Удалить «{{ deleteTarget.name || 'Без имени' }}»?</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
          Будет удалено:
          {{ deleteTarget.total_orders || 0 }} {{ (deleteTarget.total_orders || 0) === 1 ? 'заказ' : (deleteTarget.total_orders || 0) < 5 ? 'заказа' : 'заказов' }}
        </p>
      </div>

      <div class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
        <p class="text-sm text-red-700 dark:text-red-400">Это действие необратимо. Все заказы и записи клиента будут удалены навсегда.</p>
      </div>

      <div class="space-y-2">
        <label class="label text-sm">Введите <strong>{{ deleteTarget.name || 'Без имени' }}</strong> для подтверждения</label>
        <input
          v-model="deleteConfirmName"
          type="text"
          class="input"
          :placeholder="deleteTarget.name || 'Без имени'"
          @keydown.enter="deleteConfirmValid && doDelete()"
        />
      </div>

      <p v-if="deleteError" class="text-xs text-red-500">{{ deleteError }}</p>

      <div class="flex gap-3">
        <button @click="deleteTarget = null" class="btn-secondary flex-1">Отмена</button>
        <button @click="doDelete" class="btn-danger flex-1" :disabled="deleting || !deleteConfirmValid">
          {{ deleting ? 'Удаление...' : 'Удалить навсегда' }}
        </button>
      </div>
    </div>
  </UiModal>
</template>
