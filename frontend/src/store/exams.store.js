import { defineStore } from 'pinia'
import { ref } from 'vue'
import { examsApi } from '@/api/exams.api'

export const useExamsStore = defineStore('exams', () => {
  const exams = ref([])
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  async function fetchExams() {
    loading.value = true
    error.value = ''

    try {
      const { data } = await examsApi.list()
      exams.value = Array.isArray(data?.exams) ? data.exams : []
      return exams.value
    } catch (requestError) {
      error.value = requestError?.response?.data?.message ?? 'Unable to load exams.'
      throw requestError
    } finally {
      loading.value = false
    }
  }

  async function saveExam(payload) {
    saving.value = true
    error.value = ''

    try {
      const { data } = payload?.id
        ? await examsApi.update(payload.id, payload)
        : await examsApi.create(payload)
      return data?.exam ?? null
    } catch (requestError) {
      error.value = requestError?.response?.data?.message ?? 'Unable to save exam.'
      throw requestError
    } finally {
      saving.value = false
    }
  }

  return {
    exams,
    loading,
    saving,
    error,
    fetchExams,
    saveExam,
  }
})
