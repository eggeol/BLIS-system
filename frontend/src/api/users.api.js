import http from './http'

class UsersApi {
  list(params = {}) {
    return http.get('/admin/users', { params })
  }

  create(payload) {
    return http.post('/admin/users', payload)
  }

  update(userId, payload) {
    return http.patch(`/admin/users/${userId}`, payload)
  }

  archive(userId) {
    return http.patch(`/admin/users/${userId}`, { archived: true })
  }

  restore(userId) {
    return http.patch(`/admin/users/${userId}`, { archived: false })
  }

  recoverAccount(userId, payload) {
    return http.post(`/admin/users/${userId}/recover-account`, payload)
  }
}

export const usersApi = new UsersApi()
