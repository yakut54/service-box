import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/',
      component: () => import('@/components/layout/AppLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
        },
        {
          path: 'analytics',
          name: 'analytics',
          component: () => import('@/views/AnalyticsView.vue'),
        },
        {
          path: 'products',
          name: 'products',
          component: () => import('@/views/ProductsView.vue'),
        },
        {
          path: 'products/new',
          name: 'product-new',
          component: () => import('@/views/ProductEditView.vue'),
        },
        {
          path: 'products/:id/edit',
          name: 'product-edit',
          component: () => import('@/views/ProductEditView.vue'),
        },
        {
          path: 'categories',
          name: 'categories',
          component: () => import('@/views/CategoriesView.vue'),
        },
        {
          path: 'orders',
          name: 'orders',
          component: () => import('@/views/OrdersView.vue'),
        },
        {
          path: 'orders/:id',
          name: 'order-detail',
          component: () => import('@/views/OrderDetailView.vue'),
        },
        {
          path: 'bookings',
          name: 'bookings',
          component: () => import('@/views/BookingsView.vue'),
          meta: { hidden: true },
        },
        {
          path: 'bookings/:id',
          name: 'booking-detail',
          component: () => import('@/views/BookingDetailView.vue'),
          meta: { hidden: true },
        },
        {
          path: 'customers',
          name: 'customers',
          component: () => import('@/views/CustomersView.vue'),
        },
        {
          path: 'chat',
          name: 'chat',
          component: () => import('@/views/ChatView.vue'),
        },
        {
          path: 'customers/:id',
          name: 'customer-detail',
          component: () => import('@/views/CustomerDetailView.vue'),
        },
        {
          path: 'masters',
          name: 'masters',
          component: () => import('@/views/MastersView.vue'),
          meta: { hidden: true },
        },
        {
          path: 'masters/:id',
          name: 'master-detail',
          component: () => import('@/views/MasterDetailView.vue'),
          meta: { hidden: true },
        },
        {
          path: 'discounts',
          name: 'discounts',
          component: () => import('@/views/DiscountsView.vue'),
        },
        {
          path: 'reviews',
          name: 'reviews',
          component: () => import('@/views/ReviewsView.vue'),
        },
        {
          path: 'staff',
          name: 'staff',
          component: () => import('@/views/StaffView.vue'),
          meta: { requiresOwner: true, hidden: true },
        },
        {
          path: 'commission',
          name: 'commission',
          component: () => import('@/views/CommissionView.vue'),
          meta: { requiresOwner: true },
        },
        {
          path: 'legal',
          name: 'legal',
          component: () => import('@/views/LegalView.vue'),
          meta: { requiresOwner: true },
        },
        {
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/SettingsView.vue'),
          meta: { requiresOwner: true },
        },
        // Superadmin routes
        {
          path: 'superadmin/shops',
          name: 'superadmin-shops',
          component: () => import('@/views/superadmin/ShopsView.vue'),
          meta: { requiresSuperadmin: true },
        },
        {
          path: 'superadmin/revenue',
          name: 'superadmin-revenue',
          component: () => import('@/views/superadmin/RevenueView.vue'),
          meta: { requiresSuperadmin: true },
        },
      ],
    },
    {
      path: '/master',
      component: () => import('@/components/layout/MasterLayout.vue'),
      meta: { requiresAuth: true, requiresMaster: true },
      children: [
        {
          path: '',
          name: 'master-schedule',
          component: () => import('@/views/master/MasterScheduleView.vue'),
        },
        {
          path: 'notifications',
          name: 'master-notifications',
          component: () => import('@/views/master/MasterNotificationsView.vue'),
        },
        {
          path: 'stats',
          name: 'master-stats',
          component: () => import('@/views/master/MasterStatsView.vue'),
        },
      ],
    },
    {
      path: '/collector',
      component: () => import('@/components/layout/CollectorLayout.vue'),
      meta: { requiresAuth: true, requiresCollector: true },
      children: [
        {
          path: '',
          name: 'collector-orders',
          component: () => import('@/views/collector/CollectorOrdersView.vue'),
        },
        {
          path: 'orders/:id',
          name: 'collector-order-detail',
          component: () => import('@/views/collector/CollectorOrderDetailView.vue'),
        },
      ],
    },
    {
      path: '/invite/:token',
      name: 'invite-accept',
      component: () => import('@/views/InviteAcceptView.vue'),
      meta: { requiresAuth: false },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
    },
  ],
})

router.beforeEach(async (to, _from, next) => {
  const authStore = useAuthStore()

  if (!authStore.initialized) {
    await authStore.initialize()
  }

  const requiresAuth = to.meta.requiresAuth !== false

  if (requiresAuth && !authStore.isAuthenticated) {
    next({ name: 'login', query: { redirect: to.fullPath } })
    return
  }

  // Мастер/сборщик после логина/регистрации → в свой кабинет, не в dashboard
  if (!requiresAuth && authStore.isAuthenticated && (to.name === 'login' || to.name === 'register')) {
    if (authStore.isMaster) { next({ name: 'master-schedule' }); return }
    if (authStore.isCollector) { next({ name: 'collector-orders' }); return }
    next({ name: 'dashboard' })
    return
  }

  // Мастер пытается открыть обычную admin-панель → redirect в свой кабинет
  if (authStore.isMaster && !to.meta.requiresMaster && requiresAuth && to.name !== 'not-found') {
    next({ name: 'master-schedule' })
    return
  }

  // Не-мастер пытается открыть мастерский раздел
  if (to.meta.requiresMaster && !authStore.isMaster) {
    next({ name: 'dashboard' })
    return
  }

  // Сборщик пытается открыть обычную admin-панель → redirect в свой кабинет
  if (authStore.isCollector && !to.meta.requiresCollector && requiresAuth && to.name !== 'not-found') {
    next({ name: 'collector-orders' })
    return
  }

  // Не-сборщик пытается открыть раздел сборщика
  if (to.meta.requiresCollector && !authStore.isCollector) {
    next({ name: 'dashboard' })
    return
  }

  if (to.meta.requiresSuperadmin && !authStore.user?.is_superadmin) {
    next({ name: 'dashboard' })
    return
  }

  if (to.meta.requiresOwner && authStore.isAuthenticated && !authStore.isOwner) {
    next({ name: 'dashboard' })
    return
  }

  // Раздел временно скрыт из навигации — прямой переход по URL тоже не пускаем
  if (to.meta.hidden) {
    next({ name: 'dashboard' })
    return
  }

  next()
})

export default router
