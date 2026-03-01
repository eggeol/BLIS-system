import http from './http'

class StudentExamsApi {
  startExam(examId, payload) {
    return http.post(`/student/exams/${examId}/start`, payload)
  }

  getAttempt(attemptId) {
    return http.get(`/student/exam-attempts/${attemptId}`)
  }

  saveAnswer(attemptId, payload) {
    return http.patch(`/student/exam-attempts/${attemptId}/answers`, payload)
  }

  bookmarkQuestion(attemptId, questionId, payload) {
    return http.patch(`/student/exam-attempts/${attemptId}/questions/${questionId}/bookmark`, payload)
  }

  submitAttempt(attemptId) {
    return http.post(`/student/exam-attempts/${attemptId}/submit`)
  }
}

export const studentExamsApi = new StudentExamsApi()
