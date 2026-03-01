import { defineStore } from 'pinia'
import { ref } from 'vue'
import { usersApi } from '@/api/users.api'

export const useUsersStore = defineStore('users', () => {
  const users = ref([])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  async function fetchUsers(params = {}) {
    loading.value = true
    error.value = ''

    try {
      const { data } = await usersApi.list(params)
      users.value = Array.isArray(data?.users) ? data.users : []
      return users.value
    } catch (requestError) {
      error.value = requestError?.response?.data?.message ?? 'Unable to load users.'
      throw requestError
    } finally {
      loading.value = false
    }
  }

  async function saveUser(payload) {
    saving.value = true
    error.value = ''

    try {
      const { data } = payload?.id
        ? await usersApi.update(payload.id, payload)
        : await usersApi.create(payload)
      return data?.user ?? null
    } catch (requestError) {
      error.value = requestError?.response?.data?.message ?? 'Unable to save user.'
      throw requestError
    } finally {
      saving.value = false
    }
  }

  return {
    users,
    loading,
    saving,
    error,
    fetchUsers,
    saveUser,
  }
})
