import http from './http'

class AuditApi {
  list(params = {}) {
    return http.get('/admin/audit-logs', { params })
  }
}

export const auditApi = new AuditApi()
