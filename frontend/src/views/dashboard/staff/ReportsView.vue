<template>
  <section class="dashboard-view">
    <div v-if="reportError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ reportError }}</span>
    </div>
    <div v-if="reportExportMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ reportExportMessage }}</span>
    </div>
    <div v-if="reportExportError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ reportExportError }}</span>
    </div>

    <article class="surface-card">
      <header class="surface-head">
        <h3>Export Session Reports</h3>
        <button type="button" class="ghost-btn" :disabled="reportTargetsLoading" @click="loadReportExportTargets(true)">
          <RefreshCw :size="14" :class="{ 'spin-soft': reportTargetsLoading }" />
          Refresh
        </button>
      </header>

      <div v-if="reportTargetsLoading" class="room-detail-loading">
        <RefreshCw :size="15" class="spin-soft" />
        <span>Loading export targets...</span>
      </div>

      <template v-else>
        <div class="room-form-grid">
          <label class="field-stack">
            <span class="field-label">Exam</span>
            <select v-model="reportExportForm.exam_id" class="text-input">
              <option value="">Select exam</option>
              <option v-for="exam in reportExportExams" :key="exam.id" :value="String(exam.id)">
                {{ exam.title }}
              </option>
            </select>
          </label>

          <label class="field-stack">
            <span class="field-label">Room</span>
            <select v-model="reportExportForm.room_id" class="text-input" :disabled="!reportExportForm.exam_id">
              <option value="">Select room</option>
              <option v-for="room in reportExportRoomOptions" :key="room.id" :value="String(room.id)">
                {{ room.name }}{{ room.code ? ` (${room.code})` : '' }}
              </option>
            </select>
          </label>

          <label class="field-stack">
            <span class="field-label">Student (Individual PDF)</span>
            <select
              v-model="reportExportForm.student_id"
              class="text-input"
              :disabled="!reportExportForm.room_id || reportStudentsLoading || reportExportStudents.length === 0"
            >
              <option value="">Select student</option>
              <option v-for="student in reportExportStudents" :key="student.id" :value="String(student.id)">
                {{ student.name }} ({{ student.student_id || 'No ID' }})
              </option>
            </select>
          </label>

          <div class="field-stack">
            <span class="field-label">Email Delivery</span>
            <label class="check-item">
              <input v-model="reportExportForm.verified_only" type="checkbox" />
              <span>Send only to verified student emails</span>
            </label>
          </div>
        </div>

        <div class="modal-actions report-export-actions">
          <button
            type="button"
            class="ghost-btn"
            :disabled="!canExportSessionReports || reportExportingKey !== ''"
            @click="exportReport('xlsx')"
          >
            <RefreshCw v-if="reportExportingKey === 'xlsx'" :size="14" class="spin-soft" />
            <span>Complete Results (XLSX)</span>
          </button>
          <button
            type="button"
            class="ghost-btn"
            :disabled="!canExportSessionReports || reportExportingKey !== ''"
            @click="exportReport('csv')"
          >
            <RefreshCw v-if="reportExportingKey === 'csv'" :size="14" class="spin-soft" />
            <span>Complete Results (CSV)</span>
          </button>
          <button
            type="button"
            class="ghost-btn"
            :disabled="!canExportSessionReports || reportExportingKey !== ''"
            @click="exportReport('summary_pdf')"
          >
            <RefreshCw v-if="reportExportingKey === 'summary_pdf'" :size="14" class="spin-soft" />
            <span>Results Summary (PDF)</span>
          </button>
          <button
            type="button"
            class="ghost-btn"
            :disabled="!canExportSessionReports || reportExportingKey !== ''"
            @click="exportReport('answer_key_pdf')"
          >
            <RefreshCw v-if="reportExportingKey === 'answer_key_pdf'" :size="14" class="spin-soft" />
            <span>Answer Key (PDF)</span>
          </button>
          <button
            type="button"
            class="ghost-btn"
            :disabled="!canExportSingleStudentReport || reportExportingKey !== ''"
            @click="exportReport('student_pdf')"
          >
            <RefreshCw v-if="reportExportingKey === 'student_pdf'" :size="14" class="spin-soft" />
            <span>Individual Student (PDF)</span>
          </button>
          <button
            type="button"
            class="primary-btn"
            :disabled="!canExportSessionReports || reportExportingKey !== ''"
            @click="exportReport('student_zip')"
          >
            <RefreshCw v-if="reportExportingKey === 'student_zip'" :size="14" class="spin-soft" />
            <span>All Student Reports (ZIP)</span>
          </button>
          <button
            type="button"
            class="ghost-btn"
            :disabled="!canExportSingleStudentReport || reportExportingKey !== ''"
            @click="sendReportEmail('student_email')"
          >
            <RefreshCw v-if="reportExportingKey === 'student_email'" :size="14" class="spin-soft" />
            <span>Email Individual PDF</span>
          </button>
          <button
            type="button"
            class="primary-btn"
            :disabled="!canExportSessionReports || reportExportingKey !== ''"
            @click="sendReportEmail('student_email_bulk')"
          >
            <RefreshCw v-if="reportExportingKey === 'student_email_bulk'" :size="14" class="spin-soft" />
            <span>Email All Student Reports</span>
          </button>
        </div>

        <p class="muted">System administrators do not have access to exam result exports.</p>
      </template>
    </article>

    <div class="stats-grid">
      <article class="stat-card" v-for="card in reportMetricCards" :key="card.label">
        <div class="stat-icon" :class="`tone-${card.tone}`">
          <component :is="card.icon" :size="17" />
        </div>
        <div>
          <p class="stat-label">{{ card.label }}</p>
          <p class="stat-value">{{ card.value }}</p>
          <p class="stat-trend" :class="{ positive: card.positive, negative: !card.positive }">
            {{ card.trend }}
          </p>
        </div>
      </article>
    </div>

    <article class="surface-card">
      <header class="surface-head">
        <h3>Recent Activity</h3>
        <button type="button" class="ghost-btn" :disabled="reportLoading" @click="loadReports">
          <RefreshCw :size="14" :class="{ 'spin-soft': reportLoading }" />
          Refresh
        </button>
      </header>

      <div v-if="reportLoading" class="room-detail-loading">
        <RefreshCw :size="15" class="spin-soft" />
        <span>Loading report data...</span>
      </div>

      <div v-else-if="reportActivity.length === 0" class="room-detail-empty">
        <div>
          <h4>No activity yet</h4>
          <p>Recent operational logs will appear here.</p>
        </div>
      </div>

      <div v-else class="activity-list">
        <div v-for="activity in reportActivity" :key="activity.id" class="activity-item">
          <span class="activity-dot ok" />
          <div class="activity-content">
            <strong>{{ activity.description || activity.action }}</strong>
            <small>{{ formatDateTime(activity.created_at) }} • {{ activity.actor?.name || 'System' }}</small>
          </div>
          <strong class="ok">{{ activity.action }}</strong>
        </div>
      </div>
    </article>
  </section>
</template>

<script setup>
import { AlertCircle, CheckCircle2, RefreshCw } from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useReportsModule } from '../composables/useReportsModule'

const {
  reportLoading,
  reportError,
  reportActivity,
  reportTargetsLoading,
  reportStudentsLoading,
  reportExportingKey,
  reportExportError,
  reportExportMessage,
  reportExportExams,
  reportExportStudents,
  reportExportForm,
  reportExportRoomOptions,
  canExportSessionReports,
  canExportSingleStudentReport,
  reportMetricCards,
  loadReports,
  loadReportExportTargets,
  exportReport,
  sendReportEmail,
} = useReportsModule()
</script>

<style scoped src="../dashboard.css"></style>
