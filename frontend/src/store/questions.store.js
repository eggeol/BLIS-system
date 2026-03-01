import { defineStore } from 'pinia'
import { ref } from 'vue'
import { questionsApi } from '@/api/questions.api'

export const useQuestionsStore = defineStore('questions', () => {
  const banks = ref([])
  const loading = ref(false)
  const error = ref('')

  async function fetchBanks() {
    loading.value = true
    error.value = ''

    try {
      const { data } = await questionsApi.listBanks()
      banks.value = Array.isArray(data?.banks) ? data.banks : []
      return banks.value
    } catch (requestError) {
      error.value = requestError?.response?.data?.message ?? 'Unable to load question banks.'
      throw requestError
    } finally {
      loading.value = false
    }
  }

  return {
    banks,
    loading,
    error,
    fetchBanks,
  }
})
