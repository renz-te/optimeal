import { createRouter, createWebHistory } from 'vue-router'
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
    children: [
      { path: '', redirect: '/customer/menu' },
      { path: 'menu', component: CustomerMenu },
      { path: 'settings', component: CustomerSettings }
    ]
  },
  {
    path: '/vendor',
    component: DashboardLayout,
    children: [
      { path: '', redirect: '/vendor/dashboard' },
      { path: 'dashboard', component: VendorDashboard },
      { path: 'orders', component: () => import('../views/vendor/Orders.vue') },
      { path: 'roster', name: 'VendorRoster', component: () => import('../views/vendor/DailyRoster.vue') },
      { path: 'catalog', redirect: '/vendor/roster' },
      { path: 'decay', component: () => import('../views/vendor/DecayRules.vue') },
      { path: 'summary', component: () => import('../views/vendor/Summary.vue') }
    ]
  },
  {
    path: '/admin',
    component: DashboardLayout,
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

export default router
