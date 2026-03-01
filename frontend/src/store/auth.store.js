import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/api/auth.api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!user.value)

  // ─── actions ─────────────────────────────────────────────────────────────────
  async function login(email, password) {
    const { data } = await authApi.login({ email, password })
    user.value = data.user
    initialized.value = true
  }

  async function register(name, student_id, email, password, password_confirmation) {
    const { data } = await authApi.register({
      name, student_id, email, password, password_confirmation,
    })
    user.value = data.user
    initialized.value = true
  }

  async function fetchMe(options = {}) {
    const { silent = false } = options

    try {
      const { data } = await authApi.me()
      user.value = data
      return data
    } catch (error) {
      user.value = null
      if (!silent && error?.response?.status !== 401) {
        throw error
      }
      return null
    } finally {
      initialized.value = true
    }
  }

  async function logout() {
    try {
      await authApi.logout()
    } finally {
      user.value = null
      initialized.value = true
    }
  }

  return {
    user,
    initialized,
    isAuthenticated,
    login,
    register,
    fetchMe,
    logout,
  }
})
