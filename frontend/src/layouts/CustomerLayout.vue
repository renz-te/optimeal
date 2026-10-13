<template>
  <div class="min-h-screen bg-slate-50 font-sans antialiased text-slate-900">
    <nav class="bg-white/80 backdrop-blur border-b border-slate-200 sticky top-0 z-50 p-4 flex justify-between items-center mb-6">
      <h1 class="text-xl font-bold text-emerald-600 tracking-tight">OptiMeal</h1>
      <div class="flex items-center space-x-2 sm:space-x-4">
        <router-link to="/customer/menu" class="flex items-center space-x-1.5 hover:text-emerald-600 font-medium px-3 py-2 rounded transition-colors text-sm sm:text-base" active-class="bg-emerald-50 text-emerald-700">
          <ShoppingBag :size="18" />
          <span>Browse Menu</span>
        </router-link>

        <router-link to="/customer/orders" class="flex items-center space-x-1.5 hover:text-emerald-600 font-medium px-3 py-2 rounded transition-colors text-sm sm:text-base" active-class="bg-emerald-50 text-emerald-700">
          <Clock :size="18" />
          <span>My Orders</span>
        </router-link>
        
        <div class="border-l border-slate-200 h-6 mx-1 sm:mx-2"></div>
        
        <template v-if="user">
          <!-- Notification Bell -->
          <div class="relative">
            <button 
              @click="isDropdownOpen = !isDropdownOpen" 
              class="relative p-2 text-slate-600 hover:text-emerald-600 hover:bg-slate-100 rounded-full transition-colors focus:outline-none"
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

            <!-- Backdrop to close on click outside -->
            <div v-if="isDropdownOpen" class="fixed inset-0 z-40" @click="isDropdownOpen = false"></div>

            <!-- Notification Dropdown -->
            <div 
              v-if="isDropdownOpen" 
              class="absolute right-0 mt-2 w-80 sm:w-96 bg-white rounded-2xl shadow-xl border border-slate-200 py-2 z-50 overflow-hidden"
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
                  class="px-4 py-3 hover:bg-slate-50 transition-colors cursor-pointer"
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
      <!-- Global Announcement Banner -->
      <div 
        v-if="globalAnnouncement && !isAnnouncementDismissed" 
        class="bg-amber-500 text-slate-950 px-5 py-3 shadow-sm flex items-center justify-between rounded-2xl mb-6 border border-amber-600/30 text-sm font-bold"
      >
        <div class="flex items-center space-x-3">
          <Megaphone :size="18" class="text-slate-950 shrink-0 animate-pulse" />
          <span>{{ globalAnnouncement }}</span>
        </div>
        <button 
          @click="dismissAnnouncement" 
          class="p-1 hover:bg-amber-600/30 rounded-lg transition-colors text-slate-950 ml-3 shrink-0" 
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
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { User, ShoppingBag, Clock, Megaphone, X, Bell } from 'lucide-vue-next'
import { useSandbox } from '../stores/sandbox'
import { getNotifications, markNotificationsRead } from '../services/api'

const { exitSandbox, clearSandbox } = useSandbox()
const router = useRouter()
const user = ref(null)

const globalAnnouncement = ref('')
const isAnnouncementDismissed = ref(false)

// Notifications state
const notifications = ref([])
const unreadCount = ref(0)
const isDropdownOpen = ref(false)
let pollIntervalId = null

const fetchNotifications = async () => {
  if (!user.value) {
    notifications.value = []
    unreadCount.value = 0
    return
  }
  try {
    const data = await getNotifications()
    notifications.value = data.notifications || []
    unreadCount.value = Number(data.unread_count) || 0
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
  fetchAnnouncement()
  fetchNotifications()
  pollIntervalId = setInterval(fetchNotifications, 20000)
})

onUnmounted(() => {
  window.removeEventListener('auth-change', checkAuth)
  if (pollIntervalId) {
    clearInterval(pollIntervalId)
    pollIntervalId = null
  }
})
</script>
