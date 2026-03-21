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
        },
        {
          path: 'bookings/:id',
          name: 'booking-detail',
          component: () => import('@/views/BookingDetailView.vue'),
        },
        {
          path: 'customers',
          name: 'customers',
          component: () => import('@/views/CustomersView.vue'),
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
        },
        {
          path: 'masters/:id',
          name: 'master-detail',
          component: () => import('@/views/MasterDetailView.vue'),
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
          path: 'subscription',
          name: 'subscription',
          component: () => import('@/views/SubscriptionView.vue'),
        },
        {
          path: 'legal',
          name: 'legal',
          component: () => import('@/views/LegalView.vue'),
        },
        {
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/SettingsView.vue'),
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
        {
          path: 'superadmin/pricing',
          name: 'superadmin-pricing',
          component: () => import('@/views/superadmin/PricingView.vue'),
          meta: { requiresSuperadmin: true },
        },
      ],
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
  } else if (!requiresAuth && authStore.isAuthenticated && (to.name === 'login' || to.name === 'register')) {
    next({ name: 'dashboard' })
  } else if (to.meta.requiresSuperadmin && !authStore.user?.is_superadmin) {
    next({ name: 'dashboard' })
  } else {
    next()
  }
})

export default router
