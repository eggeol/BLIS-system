import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/api/auth.api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!user.value)

  // ─── actions ─────────────────────────────────────────────────────────────────
  async function login(identifier, password) {
    const { data } = await authApi.login({ identifier, password })
    user.value = data.user
    initialized.value = true
  }

  async function register(first_name, middle_name, last_name, student_id, email, password, password_confirmation, agreed) {
    const { data } = await authApi.register({
      first_name,
      middle_name,
      last_name,
      student_id,
      email,
      password,
      password_confirmation,
      agreed,
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
    } catch (error) {
      // If token/session is already invalid, still proceed with local logout.
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
