<template>
  <div class="min-h-screen bg-slate-50 font-sans antialiased text-slate-900">
    <nav class="bg-white/80 backdrop-blur border-b border-slate-200 sticky top-0 z-50 p-4 flex justify-between items-center mb-6">
      <h1 class="text-xl font-bold text-emerald-600 tracking-tight">OptiMeal</h1>
      <div class="flex items-center space-x-4">
        <router-link to="/customer/menu" class="flex items-center space-x-1.5 hover:text-emerald-600 font-medium px-3 py-2 rounded transition-colors" active-class="bg-emerald-50 text-emerald-700">
          <ShoppingBag :size="18" />
          <span>Browse Menu</span>
        </router-link>
        
        <div class="border-l border-slate-200 h-6 mx-2"></div>
        
        <template v-if="user">
          <div class="flex items-center space-x-3 bg-white px-3 py-1.5 rounded-full border border-slate-200 shadow-sm">
            <User :size="18" class="text-slate-500" />
            <router-link to="/customer/settings" class="text-sm font-semibold text-slate-700 hover:text-emerald-600 transition-colors cursor-pointer">
              {{ user.name }}
            </router-link>
            <button v-if="user.role === 'admin'" @click="exitSandbox" class="text-xs bg-amber-500/20 text-amber-700 border border-amber-300 px-3 py-1 rounded font-semibold">Exit Sandbox</button>
            <button v-else @click="logout" class="text-xs font-bold text-rose-600 hover:bg-rose-50 px-2 py-1 bg-white rounded-lg transition-colors">Logout</button>
          </div>
        </template>
        <template v-else>
          <router-link to="/customer/login" class="bg-emerald-600 text-white font-bold px-4 py-2 rounded-lg hover:bg-emerald-700 shadow-sm transition-colors">Log In</router-link>
        </template>
      </div>
    </nav>
    <main class="max-w-6xl mx-auto p-4">
      <router-view></router-view>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { User, ShoppingBag } from 'lucide-vue-next'
import { useSandbox } from '../stores/sandbox'

const { exitSandbox, clearSandbox } = useSandbox()
const router = useRouter()
const user = ref(null)

const checkAuth = () => {
  const data = localStorage.getItem('optimeal_user')
  if (data) {
    try {
      user.value = JSON.parse(data)
    } catch (e) {
      user.value = null
    }
  } else {
    user.value = null
  }
}

const logout = () => {
  clearSandbox()
  localStorage.removeItem('optimeal_user')
  user.value = null
  router.push('/customer/login')
}

onMounted(() => {
  checkAuth()
  window.addEventListener('auth-change', checkAuth)
})

onUnmounted(() => {
  window.removeEventListener('auth-change', checkAuth)
})
</script>
