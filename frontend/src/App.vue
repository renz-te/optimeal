<template>
  <div id="app" class="min-h-screen flex flex-col">
    <!-- Persistent Sandbox Bar for Admin -->
    <AdminSandboxBar v-if="!route.meta.hideSandbox && isSandboxActive" />
    <component :is="layout">
      <router-view></router-view>
    </component>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import CustomerLayout from './layouts/CustomerLayout.vue'
import DashboardLayout from './layouts/DashboardLayout.vue'
import AdminSandboxBar from './components/AdminSandboxBar.vue'

const route = useRoute()
const layout = computed(() => {
  return route.meta.layout === 'dashboard' ? DashboardLayout : CustomerLayout
})

const isSandboxActive = computed(() => {
  try {
    const user = JSON.parse(localStorage.getItem('optimeal_user') || '{}')
    return user.role === 'admin'
  } catch (e) {
    return false
  }
})
</script>
