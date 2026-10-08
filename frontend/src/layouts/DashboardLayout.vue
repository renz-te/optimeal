<template>
  <div class="flex h-screen bg-slate-100 font-sans antialiased text-slate-900 overflow-hidden">
    <!-- Desktop Sidebar -->
    <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col hidden md:flex h-full flex-shrink-0 relative z-20 shadow-xl">
      <div class="p-6">
        <h1 class="text-2xl font-black text-emerald-400 tracking-tight">OptiMeal</h1>
        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-1">{{ role === 'admin' ? 'System Admin' : 'Vendor Portal' }}</div>
      </div>
      
      <nav class="flex-1 px-4 space-y-1.5 overflow-y-auto hide-scrollbar">
        <template v-if="displayRole === 'admin'">
          <router-link to="/admin/dashboard" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <LayoutDashboard :size="18" /><span>Overview</span>
          </router-link>
          <router-link to="/admin/taxonomy" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <ListTree :size="18" /><span>Allergen Taxonomy</span>
          </router-link>
        
        <template v-if="displayRole === 'vendor'">
          <router-link to="/vendor/orders" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <ListOrdered :size="18" /><span>Live Orders & Queue</span>
          </router-link>
          <router-link to="/vendor/dashboard" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <Store :size="18" /><span>Today's Live Stock</span>
          </router-link>
          <router-link to="/vendor/roster" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <Utensils :size="18" /><span>Master Catalog & Lineup</span>
          </router-link>
          <router-link to="/vendor/decay" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <Tags :size="18" /><span>Surplus Decay Rules</span>
          </router-link>
          <router-link to="/vendor/summary" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <LineChart :size="18" /><span>Daily Audit Summary</span>
          </router-link>
        </template>
      </nav>
      
      <!-- Footer Logout -->
      <div class="p-4 border-t border-slate-800 bg-slate-900/50 backdrop-blur">
        <div class="mb-3 px-2 flex items-center space-x-3">
          <div class="w-8 h-8 rounded-full bg-emerald-600 flex items-center justify-center text-white font-bold text-sm shadow-sm">{{ userInitial }}</div>
          <div class="overflow-hidden flex-1">
            <div class="text-sm font-bold text-white truncate">{{ userName }}</div>
            <div class="text-[10px] uppercase font-bold text-slate-500 tracking-wider truncate">{{ userEmail }}</div>
          </div>
        </div>
        <button @click="logout" class="w-full flex items-center justify-center space-x-2 px-4 py-2.5 bg-slate-800/80 hover:bg-rose-900/40 hover:text-rose-400 text-slate-400 rounded-lg transition-colors text-sm font-semibold border border-slate-700/50 hover:border-rose-900/50">
          <LogOut :size="16" /><span>Logout</span>
        </button>
      </div>
    </aside>
    
    <!-- Main Content -->
    <main class="flex-1 overflow-auto bg-slate-50 relative z-10 flex flex-col h-full">
      <router-view></router-view>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { LayoutDashboard, Store, Tags, ListTree, Users, ListOrdered, Utensils, LineChart, LogOut } from 'lucide-vue-next'
import { useSandbox } from '../stores/sandbox'

const router = useRouter()
const { activePreviewMode, clearSandbox } = useSandbox()
const role = ref('vendor')
const userName = ref('')
const userEmail = ref('')

const displayRole = computed(() => {
  if (role.value === 'admin' && activePreviewMode.value === 'vendor') return 'vendor'
  return role.value
})

onMounted(() => {
  const data = localStorage.getItem('optimeal_user')
  if (data) {
    try {
      const user = JSON.parse(data)
      role.value = user.role || 'vendor'
      userName.value = user.name || 'User'
      userEmail.value = user.email || ''
    } catch (e) {}
  } else {
    router.push('/customer/login')
  }
})

const userInitial = computed(() => userName.value.charAt(0).toUpperCase())

const logout = () => {
  clearSandbox()
  localStorage.removeItem('optimeal_user')
  window.dispatchEvent(new Event('auth-change'))
  router.push('/customer/login')
}
</script>
