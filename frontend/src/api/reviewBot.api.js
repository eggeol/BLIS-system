import http from './http'

class ReviewBotApi {
  listSubjects() {
    return http.get('/student/review-bot/subjects')
  }

  generateQuiz(payload) {
    return http.post('/student/review-bot/generate', payload)
  }
}

export const reviewBotApi = new ReviewBotApi()
