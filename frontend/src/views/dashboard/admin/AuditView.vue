<template>
  <section class="room-view">
    <div v-if="auditError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ auditError }}</span>
    </div>

    <article class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <ClipboardList :size="18" />
          <h3>Audit Logs</h3>
        </div>
        <button type="button" class="ghost-btn" :disabled="auditLoading" @click="loadAuditLogs">
          <RefreshCw :size="14" :class="{ 'spin-soft': auditLoading }" />
          Refresh
        </button>
      </header>

      <div v-if="auditLoading && auditLogs.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading logs</h4>
        <p>Retrieving recent system actions.</p>
      </div>

      <div v-else-if="auditLogs.length === 0" class="room-empty-state">
        <ClipboardList :size="42" />
        <h4>No logs yet</h4>
        <p>Audit records will appear after system activity occurs.</p>
      </div>

      <div v-else class="management-list">
        <article v-for="log in auditLogs" :key="log.id" class="management-item">
          <div class="management-item-main">
            <strong>{{ log.description || log.action }}</strong>
            <p>{{ log.action }} • {{ formatDateTime(log.created_at) }}</p>
            <div class="management-inline">
              <span class="pill navy">{{ log.target_type || 'system' }}</span>
              <span class="pill neutral">Target #{{ log.target_id || 'n/a' }}</span>
              <span class="pill success">{{ log.actor?.name || 'System' }}</span>
            </div>
          </div>
        </article>
      </div>
    </article>
  </section>
</template>

<script setup>
import { AlertCircle, ClipboardList, RefreshCw } from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useAuditModule } from '../composables/useAuditModule'

const {
  auditLogs,
  auditLoading,
  auditError,
  loadAuditLogs,
} = useAuditModule()
</script>

<style scoped src="../dashboard.css"></style>
