import http from './http'

class StudentDashboardApi {
  summary() {
    return http.get('/student/dashboard')
  }
}

export const studentDashboardApi = new StudentDashboardApi()
