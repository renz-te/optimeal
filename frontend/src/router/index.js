import { createRouter, createWebHistory } from 'vue-router'
import CustomerMenu from '../views/customer/Menu.vue'
import CustomerLogin from '../views/customer/Login.vue'
import VendorDashboard from '../views/vendor/Dashboard.vue'

import CustomerSettings from '../views/customer/Settings.vue'

const routes = [
  { path: '/', redirect: '/customer/menu' },
  { path: '/customer/login', component: CustomerLogin, meta: { hideSandbox: true } },
  { path: '/customer/menu', component: CustomerMenu },
  { path: '/customer/settings', component: CustomerSettings },
  { path: '/vendor/dashboard', component: VendorDashboard, meta: { layout: 'dashboard', requiresAuth: true, role: 'vendor' } },
  { path: '/vendor/orders', component: () => import('../views/vendor/Orders.vue'), meta: { layout: 'dashboard', requiresAuth: true, role: 'vendor' } },
  { path: '/vendor/roster', name: 'VendorRoster', component: () => import('../views/vendor/DailyRoster.vue'), meta: { layout: 'dashboard', requiresAuth: true, role: 'vendor' } },
  { path: '/vendor/catalog', redirect: '/vendor/roster' },
  { path: '/vendor/decay', component: () => import('../views/vendor/DecayRules.vue'), meta: { layout: 'dashboard', requiresAuth: true, role: 'vendor' } },
  { path: '/vendor/summary', component: () => import('../views/vendor/Summary.vue'), meta: { layout: 'dashboard', requiresAuth: true, role: 'vendor' } },
  { path: '/admin/dashboard', component: () => import('../views/admin/Dashboard.vue'), meta: { layout: 'dashboard', requiresAuth: true, role: 'admin' } },
  { path: '/admin/taxonomy', component: () => import('../views/admin/Taxonomy.vue'), meta: { layout: 'dashboard', requiresAuth: true, role: 'admin' } }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach((to, from, next) => {
  if (to.meta.requiresAuth) {
    const userStr = localStorage.getItem('optimeal_user')
    let userRole = null
    let isAdmin = false

    if (userStr) {
      try {
        const user = JSON.parse(userStr)
        userRole = user.role
        isAdmin = user.role === 'admin'
      } catch (e) {}
    }

    const isSandboxAuthorized = isAdmin && sessionStorage.getItem('sandbox_preview_mode') === to.meta.role

    if (!userRole) {
      next('/customer/login')
      return
    }

    if (userRole !== to.meta.role && !isSandboxAuthorized) {
      next('/')
      return
    }
  }
  next()
})

export default router
