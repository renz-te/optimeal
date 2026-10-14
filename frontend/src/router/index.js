import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import CustomerLayout from '../layouts/CustomerLayout.vue'
import DashboardLayout from '../layouts/DashboardLayout.vue'

import CustomerLogin from '../views/customer/Login.vue'
import CustomerMenu from '../views/customer/Menu.vue'
import CustomerSettings from '../views/customer/Settings.vue'
import VendorDashboard from '../views/vendor/Dashboard.vue'

const routes = [
  { path: '/', component: CustomerLogin },
  { path: '/login', redirect: '/' },
  { path: '/customer/login', redirect: '/' },
  {
    path: '/customer',
    component: CustomerLayout,
    meta: { requiresAuth: true, role: 'customer' },
    children: [
      { path: '', redirect: '/customer/menu' },
      { path: 'menu', component: CustomerMenu },
      { path: 'orders', component: () => import('../views/customer/OrderHistory.vue') },
      { path: 'settings', component: CustomerSettings }
    ]
  },
  {
    path: '/vendor',
    component: DashboardLayout,
    meta: { requiresAuth: true, role: 'vendor' },
    children: [
      { path: '', redirect: '/vendor/dashboard' },
      { path: 'dashboard', component: VendorDashboard },
      { path: 'orders', component: () => import('../views/vendor/Orders.vue') },
      { path: 'roster', name: 'VendorRoster', component: () => import('../views/vendor/DailyRoster.vue') },
      { path: 'catalog', redirect: '/vendor/roster' },
      { path: 'prices', name: 'VendorPrices', component: () => import('../views/vendor/IngredientPrices.vue') },
      { path: 'decay', component: () => import('../views/vendor/DecayRules.vue') },
      { path: 'audit', component: () => import('../views/vendor/DailyAudit.vue') },
      { path: 'settings', redirect: '/vendor/dashboard' },
      { path: 'summary', redirect: '/vendor/audit' }
    ]
  },
  {
    path: '/admin',
    component: DashboardLayout,
    meta: { requiresAuth: true, role: 'admin' },
    children: [
      { path: '', redirect: '/admin/users' },
      { path: 'users', component: () => import('../views/admin/Users.vue') },
      { path: 'dashboard', component: () => import('../views/admin/Dashboard.vue') },
      { path: 'taxonomy', component: () => import('../views/admin/Taxonomy.vue') }
    ]
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()

  if (!authStore.isInitialized) {
    await authStore.initSession()
  }

  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)

  if (requiresAuth) {
    if (!authStore.user) {
      return next('/')
    }

    const matchedRoleRecord = to.matched.find(record => record.meta.role)
    const requiredRole = matchedRoleRecord ? matchedRoleRecord.meta.role : null

    if (requiredRole) {
      const userRole = authStore.role
      const roleMatches = userRole === requiredRole || (requiredRole === 'customer' && userRole === 'student')
      if (!roleMatches) {
        return next('/')
      }
    }
  }

  next()
})

export default router

