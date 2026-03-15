import http from './http'

class SettingsApi {
  getSystem() {
    return http.get('/settings/system')
  }

  getPublicLegal() {
    return http.get('/settings/public/legal')
  }

  updateSystem(payload) {
    return http.put('/settings/system', payload)
  }
}

export const settingsApi = new SettingsApi()
