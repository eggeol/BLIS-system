import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const user  = ref(null)
  const token = ref(localStorage.getItem('auth_token') ?? null)

  const isAuthenticated = computed(() => !!token.value)

  // ─── helpers ────────────────────────────────────────────────────────────────
  function _persist(newToken) {
    token.value = newToken
    localStorage.setItem('auth_token', newToken)
  }

  function _clear() {
    token.value = null
    user.value  = null
    localStorage.removeItem('auth_token')
  }

  // ─── actions ─────────────────────────────────────────────────────────────────
  async function login(email, password) {
    const { data } = await api.post('/auth/login', { email, password })
    _persist(data.token)
    user.value = data.user
  }

  async function register(name, email, password, password_confirmation) {
    const { data } = await api.post('/auth/register', {
      name, email, password, password_confirmation,
    })
    _persist(data.token)
    user.value = data.user
  }

  async function fetchMe() {
    if (!token.value) return
    try {
      const { data } = await api.get('/auth/me')
      user.value = data
    } catch {
      _clear()
    }
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } finally {
      _clear()
    }
  }

  return { user, token, isAuthenticated, login, register, fetchMe, logout }
})
