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
  { path: '/vendor/dashboard', component: VendorDashboard, meta: { layout: 'dashboard' } },
  { path: '/vendor/orders', component: () => import('../views/vendor/Orders.vue'), meta: { layout: 'dashboard' } },
  { path: '/vendor/roster', name: 'VendorRoster', component: () => import('../views/vendor/DailyRoster.vue'), meta: { layout: 'dashboard' } },
  { path: '/vendor/catalog', redirect: '/vendor/roster' },
  { path: '/vendor/decay', component: () => import('../views/vendor/DecayRules.vue'), meta: { layout: 'dashboard' } },
  { path: '/vendor/summary', component: () => import('../views/vendor/Summary.vue'), meta: { layout: 'dashboard' } },
  { path: '/admin/dashboard', component: () => import('../views/admin/Dashboard.vue'), meta: { layout: 'dashboard' } },
  { path: '/admin/taxonomy', component: () => import('../views/admin/Taxonomy.vue'), meta: { layout: 'dashboard' } }
]

export default createRouter({
  history: createWebHistory(),
  routes
})
