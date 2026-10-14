<template>
  <div class="min-h-screen bg-slate-50 font-sans antialiased text-slate-900">
    <!-- Global Announcement Banner (Top of page above navigation) -->
    <GlobalAnnouncement />

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

          <!-- User Avatar Dropdown -->
          <div class="relative">
            <button 
              @click="isUserMenuOpen = !isUserMenuOpen; if (isUserMenuOpen) isDropdownOpen = false" 
              class="flex items-center space-x-2 p-1 pl-1.5 pr-2.5 rounded-full hover:bg-slate-100 transition-colors focus:outline-none border border-slate-200/80 bg-white shadow-sm"
              title="User menu"
            >
              <div class="w-8 h-8 rounded-full bg-emerald-600 flex items-center justify-center text-white font-bold text-xs shadow-sm ring-2 ring-emerald-500/20">
                {{ userInitial }}
              </div>
              <span class="hidden sm:inline-block text-sm font-semibold text-slate-700 max-w-[120px] truncate">
                {{ user.name }}
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
                  <div class="font-bold text-slate-900 truncate">{{ user.name }}</div>
                  <div class="text-[11px] text-slate-500 truncate mt-0.5">{{ user.email }}</div>
                </div>

                <div class="py-1">
                  <router-link 
                    to="/customer/settings" 
                    @click="isUserMenuOpen = false" 
                    class="flex items-center space-x-2.5 px-4 py-2 hover:bg-slate-50 text-slate-700 font-medium transition-colors"
                  >
                    <User :size="16" class="text-slate-400" />
                    <span>My Profile</span>
                  </router-link>

                  <router-link 
                    to="/customer/orders" 
                    @click="isUserMenuOpen = false" 
                    class="flex items-center space-x-2.5 px-4 py-2 hover:bg-slate-50 text-slate-700 font-medium transition-colors"
                  >
                    <Clock :size="16" class="text-slate-400" />
                    <span>My Orders</span>
                  </router-link>
                </div>

                <template v-if="user.role === 'admin'">
                  <div class="border-t border-slate-100 my-1"></div>
                  <button 
                    @click="exitSandbox(); isUserMenuOpen = false" 
                    class="w-full flex items-center space-x-2.5 px-4 py-2 text-amber-700 hover:bg-amber-50 font-semibold transition-colors text-left"
                  >
                    <span>Exit Sandbox</span>
                  </button>
                </template>

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
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { User, ShoppingBag, Clock, X, Bell, ChevronDown, LogOut } from 'lucide-vue-next'
import GlobalAnnouncement from '../components/GlobalAnnouncement.vue'
import { useSandbox } from '../stores/sandbox'
import { getNotifications, markNotificationsRead } from '../services/api'

const { exitSandbox, clearSandbox } = useSandbox()
const router = useRouter()
const user = ref(null)
const isUserMenuOpen = ref(false)

const userInitial = computed(() => {
  if (!user.value || !user.value.name) return 'U'
  return user.value.name.charAt(0).toUpperCase()
})

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
