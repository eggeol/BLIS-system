import http from './http'

class StudentsApi {
  directory() {
    return http.get('/students/directory')
  }
}

export const studentsApi = new StudentsApi()
