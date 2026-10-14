<template>
  <transition
    enter-active-class="transition duration-300 ease-out"
    enter-from-class="transform -translate-y-full opacity-0"
    enter-to-class="transform translate-y-0 opacity-100"
    leave-active-class="transition duration-200 ease-in"
    leave-from-class="transform translate-y-0 opacity-100"
    leave-to-class="transform -translate-y-full opacity-0"
  >
    <div
      v-if="isVisible && announcementText"
      id="global-announcement-banner"
      role="region"
      aria-label="Global System Announcement"
      class="w-full relative z-[60] px-4 py-2.5 shadow-sm transition-colors duration-200"
      :class="severityClasses"
    >
      <div class="max-w-7xl mx-auto flex items-center justify-between gap-3 text-sm">
        <div class="flex items-center gap-2.5 flex-1 min-w-0">
          <!-- Severity Icon -->
          <component :is="severityIcon" :size="18" class="shrink-0" />
          
          <div class="truncate sm:overflow-visible sm:whitespace-normal font-medium leading-tight">
            <span v-if="severityBadge" class="inline-block mr-2 text-[10px] uppercase font-black px-1.5 py-0.5 rounded tracking-wider" :class="badgeClasses">
              {{ severityBadge }}
            </span>
            <span class="break-words">{{ announcementText }}</span>
          </div>
        </div>

        <!-- Dismiss Button -->
        <button
          id="dismiss-announcement-btn"
          type="button"
          @click="dismissAnnouncement"
          class="shrink-0 p-1 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-white/40"
          :class="dismissButtonClasses"
          title="Dismiss announcement"
          aria-label="Dismiss announcement"
        >
          <X :size="16" />
        </button>
      </div>
    </div>
  </transition>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Info, AlertTriangle, AlertOctagon, X } from 'lucide-vue-next'

const isVisible = ref(false)
const announcementText = ref('')
const announcementSeverity = ref('info')
const announcementExpiresAt = ref(null)

const severityClasses = computed(() => {
  switch (announcementSeverity.value) {
    case 'critical':
      return 'bg-rose-600 text-white font-bold'
    case 'warning':
      return 'bg-amber-500 text-amber-950 font-medium'
    case 'info':
    default:
      return 'bg-blue-600 text-white'
  }
})

const badgeClasses = computed(() => {
  switch (announcementSeverity.value) {
    case 'critical':
      return 'bg-rose-800 text-rose-100 border border-rose-400/40'
    case 'warning':
      return 'bg-amber-800/20 text-amber-950 border border-amber-900/20 font-bold'
    case 'info':
    default:
      return 'bg-blue-700 text-blue-100 border border-blue-400/30 font-bold'
  }
})

const dismissButtonClasses = computed(() => {
  switch (announcementSeverity.value) {
    case 'critical':
      return 'hover:bg-rose-700 text-white'
    case 'warning':
      return 'hover:bg-amber-600/30 text-amber-950'
    case 'info':
    default:
      return 'hover:bg-blue-700 text-white'
  }
})

const severityBadge = computed(() => {
  switch (announcementSeverity.value) {
    case 'critical':
      return 'Urgent'
    case 'warning':
      return 'Notice'
    default:
      return 'Update'
  }
})

const severityIcon = computed(() => {
  switch (announcementSeverity.value) {
    case 'critical':
      return AlertOctagon
    case 'warning':
      return AlertTriangle
    case 'info':
    default:
      return Info
  }
})

const dismissAnnouncement = () => {
  isVisible.value = false
  if (announcementText.value) {
    try {
      sessionStorage.setItem('dismissed_announcement_id', announcementText.value)
    } catch (e) {
      console.warn('SessionStorage is inaccessible:', e)
    }
  }
}

const checkAnnouncement = async () => {
  try {
    // 1. Try public announcement endpoint first (accessible to all roles without 403)
    let res = await fetch('/api/announcement')
    let data = null

    if (res.ok) {
      data = await res.json()
    } else {
      // 2. Fallback to /api/admin/settings if caller is admin
      res = await fetch('/api/admin/settings')
      if (res.ok) {
        data = await res.json()
      }
    }

    if (!data) return

    const rawMessage = data.global_announcement || data.settings?.global_announcement || null
    const severity = data.announcement_severity || data.settings?.announcement_severity || 'info'
    const expiresAt = data.announcement_expires_at || data.settings?.announcement_expires_at || null
    const isEnabled = data.enable_global_announcements !== false && data.settings?.enable_global_announcements !== false

    if (!isEnabled || !rawMessage || !rawMessage.trim()) {
      isVisible.value = false
      return
    }

    // Check expiration if specified
    if (expiresAt) {
      const expiryTime = new Date(expiresAt).getTime()
      if (!isNaN(expiryTime) && expiryTime <= Date.now()) {
        isVisible.value = false
        return
      }
    }

    // Check sessionStorage to prevent reappearance in the same session after dismissal
    try {
      const dismissed = sessionStorage.getItem('dismissed_announcement_id')
      if (dismissed && dismissed === rawMessage.trim()) {
        isVisible.value = false
        return
      }
    } catch (e) {
      console.warn('SessionStorage is inaccessible:', e)
    }

    announcementText.value = rawMessage.trim()
    announcementSeverity.value = severity
    announcementExpiresAt.value = expiresAt
    isVisible.value = true
  } catch (err) {
    console.error('Failed to load global announcement banner:', err)
  }
}

onMounted(() => {
  checkAnnouncement()
})
</script>
