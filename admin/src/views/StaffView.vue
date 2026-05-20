<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import PageHeader from '@/components/PageHeader.vue'
import AppInput from '@/components/AppInput.vue'
import PlanGate from '@/components/PlanGate.vue'
import UiConfirmDialog from '@/shared/ui/UiConfirmDialog.vue'
import UiEmptyState from '@/shared/ui/UiEmptyState.vue'
import UiSpinner from '@/shared/ui/UiSpinner.vue'
import type { StaffMember } from '@/types'

const authStore = useAuthStore()

const staff          = ref<StaffMember[]>([])
const loading        = ref(false)
const loadError      = ref('')

const inviteEmail        = ref('')
const inviteEmailTouched = ref(false)
const inviting           = ref(false)
const inviteError        = ref('')
const inviteSuccess      = ref('')

const revokeTarget     = ref<StaffMember | null>(null)
const revokeDialogOpen = ref(false)
const revoking         = ref(false)

const inviteEmailError = computed(() => {
  if (!inviteEmailTouched.value) return ''
  if (!inviteEmail.value) return 'Введите email'
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(inviteEmail.value) ? '' : 'Некорректный email'
})

const hasFeature = computed(() => {
  const plan = authStore.shop?.subscription_plan
  return plan === 'business' || plan === 'pro'
})

async function load() {
  try {
    loading.value = true
    loadError.value = ''
    const res = await api.getStaff()
    staff.value = res.data
  } catch (err) {
    loadError.value = err instanceof ApiError ? err.message : 'Ошибка загрузки'
  } finally {
    loading.value = false
  }
}

async function sendInvite() {
  inviteEmailTouched.value = true
  if (inviteEmailError.value || !inviteEmail.value) return

  inviting.value = true
  inviteError.value = ''
  inviteSuccess.value = ''

  try {
    const res = await api.inviteStaff(inviteEmail.value)
    inviteSuccess.value = res.message || 'Приглашение отправлено'
    inviteEmail.value = ''
    inviteEmailTouched.value = false
    await load()
  } catch (err) {
    inviteError.value = err instanceof ApiError ? err.message : 'Ошибка отправки'
  } finally {
    inviting.value = false
  }
}

function openRevoke(member: StaffMember) {
  revokeTarget.value = member
  revokeDialogOpen.value = true
}

async function confirmRevoke() {
  if (!revokeTarget.value) return
  revoking.value = true
  try {
    await api.revokeStaff(revokeTarget.value.id)
    revokeDialogOpen.value = false
    revokeTarget.value = null
    await load()
  } catch {
    // ignore
  } finally {
    revoking.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Команда" subtitle="Управление доступом администраторов" />

    <PlanGate v-if="!hasFeature" required-plan="Business" feature="Управление командой" />

    <template v-else>
      <!-- Invite form -->
      <div class="card">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Пригласить администратора</h2>
        <form @submit.prevent="sendInvite" class="flex flex-col sm:flex-row gap-3">
          <div class="flex-1">
            <AppInput
              v-model="inviteEmail"
              type="email"
              placeholder="admin@example.com"
              :error="inviteEmailError"
              @blur="inviteEmailTouched = true"
            />
          </div>
          <button type="submit" class="btn-primary shrink-0" :disabled="inviting">
            {{ inviting ? 'Отправка...' : 'Отправить приглашение' }}
          </button>
        </form>
        <p v-if="inviteError"   class="mt-2 text-sm text-red-600 dark:text-red-400">{{ inviteError }}</p>
        <p v-if="inviteSuccess" class="mt-2 text-sm text-green-600 dark:text-green-400">{{ inviteSuccess }}</p>
        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">Ссылка-приглашение будет отправлена на email. Действует 48 часов.</p>
      </div>

      <!-- Staff list -->
      <div v-if="loading" class="card flex items-center justify-center py-16">
        <UiSpinner />
      </div>

      <div v-else-if="loadError" class="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-600 dark:text-red-400 text-sm">
        {{ loadError }}
      </div>

      <UiEmptyState
        v-else-if="staff.length === 0"
        title="Нет администраторов"
        description="Пригласите первого администратора выше"
      >
        <template #icon>
          <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
        </template>
      </UiEmptyState>

      <div v-else class="card p-0 overflow-hidden">
        <!-- Table header (desktop) -->
        <div class="hidden sm:grid grid-cols-[1fr_120px_110px_80px] gap-4 px-4 py-2.5 border-b border-gray-100 dark:border-gray-800 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
          <span>Пользователь</span>
          <span>Роль</span>
          <span>Статус</span>
          <span></span>
        </div>

        <!-- Rows -->
        <div
          v-for="member in staff"
          :key="member.id"
          class="flex flex-wrap sm:grid sm:grid-cols-[1fr_120px_110px_80px] sm:items-center gap-x-4 gap-y-2 px-4 py-3 border-b border-gray-100 dark:border-gray-800 last:border-0"
        >
          <!-- User info -->
          <div class="flex items-center gap-3 min-w-0 w-full sm:w-auto">
            <div class="w-9 h-9 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center flex-shrink-0">
              <span class="text-sm font-medium text-gray-600 dark:text-gray-300">
                {{ (member.user?.name || member.invite_email || '?').charAt(0).toUpperCase() }}
              </span>
            </div>
            <div class="min-w-0">
              <p v-if="member.user" class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ member.user.name }}</p>
              <p v-else class="text-sm text-gray-500 dark:text-gray-400 truncate italic">{{ member.invite_email }}</p>
              <p class="text-xs text-gray-400 dark:text-gray-500 truncate">
                {{ member.user?.email || member.invite_email }}
              </p>
            </div>
          </div>

          <!-- Role -->
          <span class="text-sm text-gray-700 dark:text-gray-300">Администратор</span>

          <!-- Status badge -->
          <div>
            <span
              v-if="member.accepted_at"
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400"
            >
              <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Активен
            </span>
            <span
              v-else-if="member.is_expired"
              class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400"
            >
              Истёк
            </span>
            <span
              v-else
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400"
            >
              <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Ожидает
            </span>
          </div>

          <!-- Actions -->
          <div class="text-right sm:text-right">
            <button
              @click="openRevoke(member)"
              class="text-xs text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 font-medium transition-colors"
            >
              Удалить
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- Revoke confirm dialog -->
    <UiConfirmDialog
      v-model="revokeDialogOpen"
      title="Удалить администратора?"
      :loading="revoking"
      @confirm="confirmRevoke"
    >
      <template v-if="revokeTarget?.user">
        Доступ <strong>{{ revokeTarget.user.name }}</strong> будет немедленно отозван — при следующем запросе произойдёт выход из системы.
      </template>
      <template v-else>
        Приглашение для <strong>{{ revokeTarget?.invite_email }}</strong> будет аннулировано.
      </template>
    </UiConfirmDialog>
  </div>
</template>
