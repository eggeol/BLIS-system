import http from './http'

class AnalyticsApi {
  overview() {
    return http.get('/student/analytics/overview')
  }
}

export const analyticsApi = new AnalyticsApi()
