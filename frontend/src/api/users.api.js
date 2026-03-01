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
}

export const usersApi = new UsersApi()
