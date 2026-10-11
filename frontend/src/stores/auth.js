import { defineStore } from 'pinia'
import { ref } from 'vue'
import router from '../router'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const role = ref('')
  const isInitialized = ref(false)

  const initSession = async () => {
    try {
      const res = await fetch('/api/auth/me')
      if (res.ok) {
        const data = await res.json()
        const userData = data.user || data
        setAuth(userData)
        isInitialized.value = true
        return
      } else if (res.status === 401) {
        clearAuth()
        isInitialized.value = true
        return
      }
    } catch (e) {
      // In case /api/auth/me is unreachable or network error, fallback to localStorage
    }

    const data = localStorage.getItem('optimeal_user')
    if (data) {
      try {
        const parsed = JSON.parse(data)
        user.value = parsed
        role.value = parsed.role || ''
      } catch (e) {
        clearAuth()
      }
    } else {
      clearAuth()
    }
    isInitialized.value = true
  }

  const setAuth = (userData) => {
    user.value = userData
    role.value = userData?.role || ''
    if (userData) {
      localStorage.setItem('optimeal_user', JSON.stringify(userData))
    } else {
      localStorage.removeItem('optimeal_user')
    }
    window.dispatchEvent(new Event('auth-change'))
  }

  const clearAuth = () => {
    user.value = null
    role.value = ''
    localStorage.removeItem('optimeal_user')
    window.dispatchEvent(new Event('auth-change'))
  }

  const login = async (email, password) => {
    const res = await fetch('/api/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password })
    })

    const data = await res.json()

    if (!res.ok) {
      throw new Error(data.error || 'Authentication failed')
    }

    const userData = {
      name: data.name,
      email,
      role: data.role
    }

    setAuth(userData)

    if (data.role === 'vendor') {
      router.push('/vendor/dashboard')
    } else if (data.role === 'admin') {
      router.push('/admin/users')
    } else {
      router.push('/customer/menu')
    }

    return data
  }

  return {
    user,
    role,
    isInitialized,
    initSession,
    setAuth,
    clearAuth,
    login
  }
})

