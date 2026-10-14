<template>
  <div class="flex flex-col h-screen bg-slate-100 font-sans antialiased text-slate-900 overflow-hidden">
    <!-- Global Announcement Banner (Top of layout above sidebar & main content) -->
    <GlobalAnnouncement />

    <div class="flex flex-1 min-h-0 overflow-hidden">
      <!-- Desktop Sidebar -->
      <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col hidden md:flex h-full flex-shrink-0 relative z-20 shadow-xl">
      <div class="p-6 flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-black text-emerald-400 tracking-tight">OptiMeal</h1>
          <div class="text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-1">{{ role === 'admin' ? 'System Admin' : 'Vendor Portal' }}</div>
        </div>

        <!-- Notification Bell -->
        <div class="relative">
          <button 
            @click="isDropdownOpen = !isDropdownOpen" 
            class="relative p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-full transition-colors focus:outline-none"
            title="Notifications"
          >
            <Bell :size="18" />
            <span 
              v-if="unreadCount > 0" 
              class="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 bg-rose-600 text-white text-[10px] font-bold rounded-full flex items-center justify-center leading-none shadow-sm"
            >
              {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
          </button>

          <!-- Backdrop -->
          <div v-if="isDropdownOpen" class="fixed inset-0 z-40" @click="isDropdownOpen = false"></div>

          <!-- Notification Dropdown -->
          <div 
            v-if="isDropdownOpen" 
            class="absolute left-0 mt-2 w-80 bg-white text-slate-900 rounded-2xl shadow-2xl border border-slate-200 py-2 z-50 overflow-hidden"
          >
            <div class="px-4 py-2.5 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
              <div class="flex items-center space-x-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Notifications</span>
                <span v-if="unreadCount > 0" class="text-[10px] bg-rose-100 text-rose-700 font-bold px-1.5 py-0.5 rounded-full">
                  {{ unreadCount }} new
                </span>
              </div>
              <button 
                v-if="notifications.length > 0" 
                @click="handleMarkAllRead" 
                class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 transition-colors"
              >
                Mark all as read
              </button>
            </div>

            <div class="max-h-80 overflow-y-auto divide-y divide-slate-100 text-xs">
              <div v-if="notifications.length === 0" class="py-8 text-center text-slate-400 font-medium">
                No new notifications.
              </div>
              <div 
                v-for="notif in notifications" 
                :key="notif.id" 
                class="px-4 py-3 hover:bg-slate-50 transition-colors cursor-pointer text-left"
                :class="{ 'bg-emerald-50/40': !notif.is_read }"
                @click="handleMarkSingleRead(notif)"
              >
                <div class="flex items-start justify-between gap-2">
                  <p class="text-slate-800 leading-snug" :class="{ 'font-bold text-slate-900': !notif.is_read }">
                    {{ notif.message }}
                  </p>
                  <span v-if="!notif.is_read" class="w-2 h-2 rounded-full bg-emerald-500 shrink-0 mt-1"></span>
                </div>
                <span class="text-[10px] text-slate-400 mt-1 block">
                  {{ formatTime(notif.created_at) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <nav class="flex-1 px-4 space-y-1.5 overflow-y-auto hide-scrollbar">
        <template v-if="displayRole === 'admin'">
          <router-link to="/admin/dashboard" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <LayoutDashboard :size="18" /><span>Overview</span>
          </router-link>
          <router-link to="/admin/users" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <Users :size="18" /><span>User Management</span>
          </router-link>
          <router-link to="/admin/taxonomy" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <ListTree :size="18" /><span>Allergen Taxonomy</span>
          </router-link>
        </template>
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
          <router-link to="/vendor/prices" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <Coins :size="18" /><span>Ingredient Price Catalog</span>
          </router-link>
          <router-link to="/vendor/decay" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
            <Tags :size="18" /><span>Surplus Decay Rules</span>
          </router-link>
          <router-link to="/vendor/audit" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
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
      <!-- Top Navigation Header -->
      <header class="bg-white border-b border-slate-200 px-6 py-2.5 flex items-center justify-between sticky top-0 z-30 shrink-0">
        <div class="flex items-center space-x-3">
          <span class="text-xs font-bold uppercase tracking-wider text-slate-500">
            {{ displayRole === 'admin' ? 'Admin Control Center' : 'Vendor Operations Portal' }}
          </span>
        </div>

        <div class="flex items-center space-x-4">
          <!-- Avatar Dropdown Toggle -->
          <div class="relative">
            <button 
              @click="isUserMenuOpen = !isUserMenuOpen" 
              class="flex items-center space-x-2 p-1 pl-1.5 pr-2.5 rounded-full hover:bg-slate-100 transition-colors focus:outline-none border border-slate-200/80 bg-white shadow-sm"
              title="User menu"
            >
              <div class="w-8 h-8 rounded-full bg-emerald-600 flex items-center justify-center text-white font-bold text-xs shadow-sm ring-2 ring-emerald-500/20">
                {{ userInitial }}
              </div>
              <span class="hidden sm:inline-block text-sm font-semibold text-slate-700 max-w-[140px] truncate">
                {{ userName }}
              </span>
              <ChevronDown :size="14" class="text-slate-400 transition-transform duration-200" :class="{ 'rotate-180': isUserMenuOpen }" />
            </button>

            <!-- Backdrop to close on click outside -->
            <div v-if="isUserMenuOpen" class="fixed inset-0 z-40" @click="isUserMenuOpen = false"></div>

            <!-- Dropdown Menu -->
            <transition
              enter-active-class="transition duration-100 ease-out"
              enter-from-class="transform scale-95 opacity-0"
              enter-to-class="transform scale-100 opacity-100"
              leave-active-class="transition duration-75 ease-in"
              leave-from-class="transform scale-100 opacity-100"
              leave-to-class="transform scale-95 opacity-0"
            >
              <div 
                v-if="isUserMenuOpen" 
                class="absolute right-0 mt-2 w-56 bg-white rounded-2xl shadow-xl border border-slate-200 py-1.5 z-50 text-sm overflow-hidden"
              >
                <div class="px-4 py-2.5 border-b border-slate-100 bg-slate-50/50">
                  <div class="font-bold text-slate-900 truncate">{{ userName }}</div>
                  <div class="text-[11px] text-slate-500 truncate mt-0.5">{{ userEmail }}</div>
                </div>

                <div class="py-1">
                  <!-- Vendor role links -->
                  <template v-if="displayRole === 'vendor'">
                    <router-link 
                      to="/vendor/settings" 
                      @click="isUserMenuOpen = false" 
                      class="flex items-center space-x-2.5 px-4 py-2 hover:bg-slate-50 text-slate-700 font-medium transition-colors"
                    >
                      <Settings :size="16" class="text-slate-400" />
                      <span>Settings</span>
                    </router-link>
                    <router-link 
                      to="/vendor/orders" 
                      @click="isUserMenuOpen = false" 
                      class="flex items-center space-x-2.5 px-4 py-2 hover:bg-slate-50 text-slate-700 font-medium transition-colors"
                    >
                      <ListOrdered :size="16" class="text-slate-400" />
                      <span>Live Orders</span>
                    </router-link>
                  </template>

                  <!-- Admin role links -->
                  <template v-else-if="displayRole === 'admin'">
                    <router-link 
                      to="/admin/dashboard" 
                      @click="isUserMenuOpen = false" 
                      class="flex items-center space-x-2.5 px-4 py-2 hover:bg-slate-50 text-slate-700 font-medium transition-colors"
                    >
                      <Settings :size="16" class="text-slate-400" />
                      <span>Settings</span>
                    </router-link>
                    <router-link 
                      to="/admin/users" 
                      @click="isUserMenuOpen = false" 
                      class="flex items-center space-x-2.5 px-4 py-2 hover:bg-slate-50 text-slate-700 font-medium transition-colors"
                    >
                      <Users :size="16" class="text-slate-400" />
                      <span>User Management</span>
                    </router-link>
                  </template>
                </div>

                <div class="border-t border-slate-100 my-1"></div>
                <button 
                  @click="logout(); isUserMenuOpen = false" 
                  class="w-full flex items-center space-x-2.5 px-4 py-2 text-rose-600 hover:bg-rose-50 font-bold transition-colors text-left"
                >
                  <LogOut :size="16" class="text-rose-500" />
                  <span>Logout</span>
                </button>
              </div>
            </transition>
          </div>
        </div>
      </header>

      <router-view></router-view>
    </main>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  LayoutDashboard,
  Store,
  Tags,
  ListTree,
  Users,
  ListOrdered,
  Utensils,
  Coins,
  LineChart,
  LogOut,
  X,
  Bell,
  Settings,
  ChevronDown
} from 'lucide-vue-next'
import GlobalAnnouncement from '../components/GlobalAnnouncement.vue'
import { useSandbox } from '../stores/sandbox'
import { getNotifications, markNotificationsRead } from '../services/api'

const router = useRouter()
const { activePreviewMode, clearSandbox } = useSandbox()
const role = ref('vendor')
const userName = ref('')
const userEmail = ref('')
const isUserMenuOpen = ref(false)

// Notifications state
const notifications = ref([])
const unreadCount = ref(0)
const isDropdownOpen = ref(false)
let pollIntervalId = null

const fetchNotifications = async () => {
  const data = localStorage.getItem('optimeal_user')
  if (!data) {
    notifications.value = []
    unreadCount.value = 0
    return
  }
  try {
    const res = await getNotifications()
    notifications.value = res.notifications || []
    unreadCount.value = Number(res.unread_count) || 0
  } catch (e) {
    // Ignore unauthenticated or network glitches during polling
  }
}

const handleMarkAllRead = async () => {
  try {
    await markNotificationsRead('all')
    unreadCount.value = 0
    notifications.value.forEach(n => { n.is_read = true })
  } catch (e) {
    console.error('Failed to mark notifications as read', e)
  }
}

const handleMarkSingleRead = async (notif) => {
  if (notif.is_read) return
  try {
    await markNotificationsRead(notif.id)
    notif.is_read = true
    unreadCount.value = Math.max(0, unreadCount.value - 1)
  } catch (e) {
    console.error('Failed to mark notification as read', e)
  }
}

const formatTime = (ts) => {
  if (!ts) return ''
  try {
    const date = new Date(ts)
    return date.toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    })
  } catch (e) {
    return ts
  }
}



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

  fetchNotifications()
  pollIntervalId = setInterval(fetchNotifications, 20000)
})

onUnmounted(() => {
  if (pollIntervalId) {
    clearInterval(pollIntervalId)
    pollIntervalId = null
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
