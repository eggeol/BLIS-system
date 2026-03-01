import http from './http'

class ExamsApi {
  list() {
    return http.get('/exams')
  }

  create(payload) {
    return http.post('/exams', payload)
  }

  update(examId, payload) {
    return http.patch(`/exams/${examId}`, payload)
  }

  remove(examId) {
    return http.delete(`/exams/${examId}`)
  }

  liveBoard(examId, roomId) {
    return http.get(`/exams/${examId}/live-dashboard`, { params: { room_id: roomId } })
  }

  updateTeacherPacing(examId, payload) {
    return http.post(`/exams/${examId}/teacher-paced`, payload)
  }
}

export const examsApi = new ExamsApi()
