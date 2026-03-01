import http from './http'

class SettingsApi {
  getSystem() {
    return http.get('/settings/system')
  }

  updateSystem(payload) {
    return http.put('/settings/system', payload)
  }
}

export const settingsApi = new SettingsApi()
