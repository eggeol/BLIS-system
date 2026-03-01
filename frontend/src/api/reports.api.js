import http from './http'

class ReportsApi {
  overview() {
    return http.get('/reports/overview')
  }
}

export const reportsApi = new ReportsApi()
