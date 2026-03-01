import http from './http'

class QuestionsApi {
  listBanks() {
    return http.get('/library/banks')
  }

  importPreview(formData) {
    return http.post('/library/import/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  }

  saveBank(payload) {
    return http.post('/library/banks', payload)
  }

  deleteBank(bankId) {
    return http.delete(`/library/banks/${bankId}`)
  }
}

export const questionsApi = new QuestionsApi()
