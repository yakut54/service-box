<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/composables/useToast'
import PageHeader from '@/components/PageHeader.vue'
import AdminFormModal from '@/components/modals/AdminFormModal.vue'
import { UiConfirmDialog, UiEmptyState, UiSpinner, UiTooltip } from '@/shared/ui'
import type { StaffMember } from '@/types'

const toast = useToast()

const staff   = ref<StaffMember[]>([])
const loading = ref(false)
const error   = ref('')

const showModal    = ref(false)
const editingAdmin = ref<StaffMember | null>(null)

const deleteTarget  = ref<StaffMember | null>(null)
const deleting      = ref(false)
const resendingId   = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getStaff()
    staff.value = res.data.filter(s => s.role === 'admin')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingAdmin.value = null
  showModal.value = true
}

function openEdit(admin: StaffMember) {
  editingAdmin.value = admin
  showModal.value = true
}

function handleSaved(admin: StaffMember, mode: 'create' | 'edit') {
  if (mode === 'create') {
    staff.value.unshift(admin)
    toast.success('Приглашение отправлено')
  } else {
    const idx = staff.value.findIndex(s => s.id === admin.id)
    if (idx !== -1) staff.value[idx] = admin
    toast.success('Сохранено')
  }
}

async function resend(admin: StaffMember) {
  resendingId.value = admin.id
  try {
    await api.resendAdminInvite(admin.id)
    const idx = staff.value.findIndex(s => s.id === admin.id)
    if (idx !== -1) {
      staff.value[idx] = {
        ...staff.value[idx],
        is_expired: false,
        is_pending: true,
      }
    }
    toast.success('Приглашение отправлено повторно')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Ошибка отправки')
  } finally {
    resendingId.value = null
  }
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await api.revokeStaff(deleteTarget.value.id)
    staff.value = staff.value.filter(s => s.id !== deleteTarget.value!.id)
    deleteTarget.value = null
    toast.success('Доступ отозван')
  } catch (e) {
    toast.error(e instanceof ApiError ? e.message : 'Не удалось удалить администратора')
  } finally {
    deleting.value = false
  }
}

function displayName(admin: StaffMember) {
  return admin.user?.name || admin.invite_name || admin.invite_email || '?'
}

function displayEmail(admin: StaffMember) {
  return admin.user?.email || admin.invite_email || ''
}

function initials(admin: StaffMember) {
  const name = displayName(admin)
  return name.split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()
}

function lastLoginText(admin: StaffMember): string {
  if (!admin.last_login_at) return 'Ещё не входил'
  const diff = Date.now() - new Date(admin.last_login_at).getTime()
  const min  = Math.floor(diff / 60000)
  if (min < 5)   return 'Сейчас онлайн'
  if (min < 60)  return `${min} мин. назад`
  const hrs = Math.floor(min / 60)
  if (hrs < 24)  return `${hrs} ч. назад`
  const days = Math.floor(hrs / 24)
  if (days === 1) return 'Вчера'
  if (days < 30)  return `${days} дн. назад`
  const months = Math.floor(days / 30)
  return `${months} мес. назад`
}

function lastLoginColor(admin: StaffMember): string {
  if (!admin.last_login_at) return 'text-gray-400 dark:text-gray-500'
  const days = Math.floor((Date.now() - new Date(admin.last_login_at).getTime()) / 86400000)
  if (days < 1)  return 'text-green-600 dark:text-green-400'
  if (days < 7)  return 'text-gray-500 dark:text-gray-400'
  return 'text-amber-500 dark:text-amber-400'
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">

    <PageHeader title="Администраторы" subtitle="Люди с доступом к панели управления">
      <button @click="openCreate" class="btn-primary shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span class="hidden sm:inline">Добавить администратора</span>
      </button>
    </PageHeader>

      <div v-if="loading" class="card flex items-center justify-center py-16">
        <UiSpinner />
      </div>

      <div v-else-if="error" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
        {{ error }}
      </div>

      <UiEmptyState
        v-else-if="staff.length === 0"
        title="Нет администраторов"
        description="Добавьте первого администратора — он получит доступ к панели управления"
      >
        <template #icon>
          <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
        </template>
        <button @click="openCreate" class="btn-primary">Добавить администратора</button>
      </UiEmptyState>

      <!-- Cards grid -->
      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="admin in staff"
          :key="admin.id"
          class="card flex flex-col gap-4"
        >
          <!-- Avatar + info -->
          <div class="flex items-start gap-3">
            <div class="flex-shrink-0">
              <img
                v-if="admin.avatar_url"
                :src="admin.avatar_url"
                :alt="displayName(admin)"
                class="w-12 h-12 rounded-full object-cover border border-gray-200 dark:border-gray-700"
              />
              <div
                v-else
                class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-primary-600 dark:text-primary-400 font-semibold text-lg"
              >{{ initials(admin) }}</div>
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-gray-900 dark:text-white truncate">{{ displayName(admin) }}</p>
              <p class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ displayEmail(admin) }}</p>
              <a
                v-if="admin.phone"
                :href="`tel:${admin.phone}`"
                class="text-xs text-gray-400 dark:text-gray-500 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
              >{{ admin.phone }}</a>
            </div>
          </div>

          <!-- Status badge -->
          <div>
            <span
              v-if="admin.accepted_at"
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400"
            >
              <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Активен
            </span>
            <span
              v-else-if="admin.is_expired"
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400"
            >
              <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Приглашение истекло
            </span>
            <span
              v-else
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400"
            >
              <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Ожидает принятия
            </span>
          </div>

          <!-- Last login -->
          <div class="flex items-center gap-1.5 text-xs" :class="lastLoginColor(admin)">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            {{ lastLoginText(admin) }}
          </div>

          <!-- Actions -->
          <div class="flex gap-2 pt-1 border-t border-gray-100 dark:border-gray-700">
            <button @click="openEdit(admin)" class="btn-secondary btn-sm flex-1">
              Редактировать
            </button>

            <UiTooltip v-if="!admin.accepted_at">
              <button
                @click="resend(admin)"
                :disabled="resendingId === admin.id"
                class="btn-ghost btn-sm"
              >
                <UiSpinner v-if="resendingId === admin.id" class="w-4 h-4" />
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
              </button>
              <template #content>Переслать приглашение</template>
            </UiTooltip>

            <button @click="deleteTarget = admin" class="btn-danger btn-sm">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </div>
      </div>

    <AdminFormModal
      v-model="showModal"
      :admin="editingAdmin"
      @saved="handleSaved"
    />

    <UiConfirmDialog
      :model-value="!!deleteTarget"
      @update:model-value="!$event && (deleteTarget = null)"
      title="Удалить администратора?"
      :loading="deleting"
      @confirm="confirmDelete"
    >
      <template v-if="deleteTarget?.accepted_at">
        Доступ <strong>{{ displayName(deleteTarget) }}</strong> будет немедленно отозван.
      </template>
      <template v-else>
        Приглашение для <strong>{{ displayName(deleteTarget!) }}</strong> будет аннулировано.
      </template>
    </UiConfirmDialog>

  </div>
</template>
