<template>
  <section class="room-view">
    <div v-if="settingsMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ settingsMessage }}</span>
    </div>
    <div v-if="settingsError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ settingsError }}</span>
    </div>

    <article class="surface-card">
      <header class="surface-head">
        <h3>System Settings</h3>
        <span class="pill" :class="settingsCanEdit ? 'success' : 'neutral'">
          {{ settingsCanEdit ? 'Admin editable' : 'Read only' }}
        </span>
      </header>

      <div v-if="settingsLoading" class="room-detail-loading">
        <RefreshCw :size="15" class="spin-soft" />
        <span>Loading settings...</span>
      </div>

      <template v-else>
        <div class="settings-grid">
          <label class="field-stack">
            <span class="field-label">Platform Name</span>
            <input v-model.trim="settingsForm.platform_name" type="text" class="text-input" :disabled="!settingsCanEdit" />
          </label>

          <label class="field-stack">
            <span class="field-label">Academic Term</span>
            <input v-model.trim="settingsForm.academic_term" type="text" class="text-input" :disabled="!settingsCanEdit" />
          </label>

          <label class="check-item">
            <input v-model="settingsForm.allow_public_registration" type="checkbox" :disabled="!settingsCanEdit" />
            <span>Allow public student registration</span>
          </label>

          <label class="check-item">
            <input v-model="settingsForm.maintenance_mode" type="checkbox" :disabled="!settingsCanEdit" />
            <span>Enable maintenance mode</span>
          </label>

          <label class="field-stack">
            <span class="field-label">Announcement Banner</span>
            <textarea
              v-model.trim="settingsForm.announcement_banner"
              rows="3"
              class="text-input textarea-input"
              :disabled="!settingsCanEdit"
            />
          </label>

          <label class="field-stack">
            <span class="field-label">Terms of Use</span>
            <textarea
              v-model.trim="settingsForm.terms_of_use_text"
              rows="10"
              class="text-input textarea-input"
              :disabled="!settingsCanEdit"
            />
          </label>

          <label class="field-stack">
            <span class="field-label">Privacy Policy</span>
            <textarea
              v-model.trim="settingsForm.privacy_policy_text"
              rows="10"
              class="text-input textarea-input"
              :disabled="!settingsCanEdit"
            />
          </label>
        </div>

        <div class="modal-actions">
          <button type="button" class="ghost-btn" :disabled="settingsLoading" @click="loadSystemSettings">Reload</button>
          <button type="button" class="primary-btn" :disabled="settingsSaving || !settingsCanEdit" @click="saveSystemSettings">
            <RefreshCw v-if="settingsSaving" :size="14" class="spin-soft" />
            <span>{{ settingsSaving ? 'Saving...' : 'Save Settings' }}</span>
          </button>
        </div>
      </template>
    </article>
  </section>
</template>

<script setup>
import { AlertCircle, CheckCircle2, RefreshCw } from 'lucide-vue-next'
import { useSettingsModule } from '../composables/useSettingsModule'

const {
  settingsLoading,
  settingsSaving,
  settingsError,
  settingsMessage,
  settingsCanEdit,
  settingsForm,
  loadSystemSettings,
  saveSystemSettings,
} = useSettingsModule()
</script>

<style scoped src="../dashboard.css"></style>
