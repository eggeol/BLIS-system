import http from './http'

class StudentAnalyticsApi {
  summary() {
    return http.get('/student/analytics')
  }
}

export const studentAnalyticsApi = new StudentAnalyticsApi()
