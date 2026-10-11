import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const role = ref('')

  const initSession = () => {
    const data = localStorage.getItem('optimeal_user')
    if (data) {
      try {
        const parsed = JSON.parse(data)
        user.value = parsed
        role.value = parsed.role || ''
      } catch (e) {
        user.value = null
        role.value = ''
      }
    } else {
      user.value = null
      role.value = ''
    }
  }

  const setAuth = (userData) => {
    user.value = userData
    role.value = userData?.role || ''
    if (userData) {
      localStorage.setItem('optimeal_user', JSON.stringify(userData))
    } else {
      localStorage.removeItem('optimeal_user')
    }
  }

  const clearAuth = () => {
    user.value = null
    role.value = ''
    localStorage.removeItem('optimeal_user')
  }

  return {
    user,
    role,
    initSession,
    setAuth,
    clearAuth
  }
})
