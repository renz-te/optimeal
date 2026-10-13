<template>
  <div class="flex h-screen bg-slate-100 font-sans antialiased text-slate-900 overflow-hidden">
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
          <router-link v-if="role === 'admin'" to="/admin/users" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg hover:bg-slate-800 hover:text-white transition-colors" active-class="bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 font-semibold shadow-inner">
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
      <!-- Global Announcement Banner -->
      <div 
        v-if="globalAnnouncement && !isAnnouncementDismissed" 
        class="bg-amber-500 text-slate-950 px-6 py-2.5 shadow-sm flex items-center justify-between border-b border-amber-600/30 text-sm font-bold z-30 shrink-0"
      >
        <div class="flex items-center space-x-2.5">
          <Megaphone :size="18" class="text-slate-950 shrink-0 animate-pulse" />
          <span>{{ globalAnnouncement }}</span>
        </div>
        <button 
          @click="dismissAnnouncement" 
          class="p-1 hover:bg-amber-600/30 rounded-lg transition-colors text-slate-950" 
          title="Dismiss announcement"
        >
          <X :size="16" />
        </button>
      </div>

      <router-view></router-view>
    </main>
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
  Megaphone,
  X,
  Bell
} from 'lucide-vue-next'
import { useSandbox } from '../stores/sandbox'
import { getNotifications, markNotificationsRead } from '../services/api'

const router = useRouter()
const { activePreviewMode, clearSandbox } = useSandbox()
const role = ref('vendor')
const userName = ref('')
const userEmail = ref('')

const globalAnnouncement = ref('')
const isAnnouncementDismissed = ref(false)

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

const fetchAnnouncement = async () => {
  try {
    const res = await fetch('/api/announcement')
    if (res.ok) {
      const data = await res.json()
      globalAnnouncement.value = data.global_announcement || ''
      const dismissed = localStorage.getItem('optimeal_dismissed_announcement')
      if (dismissed === globalAnnouncement.value) {
        isAnnouncementDismissed.value = true
      } else {
        isAnnouncementDismissed.value = false
      }
    }
  } catch (e) {
    console.error('Failed to fetch announcement', e)
  }
}

const dismissAnnouncement = () => {
  isAnnouncementDismissed.value = true
  if (globalAnnouncement.value) {
    localStorage.setItem('optimeal_dismissed_announcement', globalAnnouncement.value)
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

  fetchAnnouncement()
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
