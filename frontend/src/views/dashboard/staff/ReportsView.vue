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

    <div class="stats-grid">
      <article v-for="card in reportMetricCards" :key="card.label" class="stat-card">
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

    <div class="dashboard-grid">
      <article class="surface-card">
        <header class="surface-head">
          <h3>Session Performance</h3>
          <button type="button" class="ghost-btn" :disabled="reportLoading" @click="loadReports">
            <RefreshCw :size="14" :class="{ 'spin-soft': reportLoading }" />
            Refresh
          </button>
        </header>

        <div v-if="reportLoading" class="room-detail-loading">
          <RefreshCw :size="15" class="spin-soft" />
          <span>Loading report data...</span>
        </div>

        <div v-else-if="reportSessionPerformance.length === 0" class="room-empty-state compact">
          <BarChart3 :size="30" />
          <h4>No session data yet</h4>
          <p>Assign exams to rooms and wait for student activity to populate this view.</p>
        </div>

        <div v-else class="session-list">
          <article v-for="session in reportSessionPerformance" :key="`${session.exam_id}-${session.room_id}`" class="session-item">
            <div class="session-copy">
              <strong>{{ session.exam_title }}</strong>
              <small>{{ session.room_name }}{{ session.room_code ? ` (${session.room_code})` : '' }}</small>
            </div>

            <div class="session-metrics">
              <span class="pill neutral">{{ session.students_submitted }}/{{ session.students_total }} submitted</span>
              <span class="pill navy">{{ formatPercent(session.completion_rate_percent) }} completion</span>
              <strong :class="{ ok: Number(session.average_score_percent ?? 0) >= 75, danger: Number(session.average_score_percent ?? 0) < 75 }">
                {{ formatPercent(session.average_score_percent) }}
              </strong>
            </div>
          </article>
        </div>
      </article>

      <article class="surface-card">
        <header class="surface-head">
          <h3>Subject Performance</h3>
          <span class="pill navy">{{ reportSubjectPerformance.length }} subject(s)</span>
        </header>

        <div v-if="reportLoading" class="room-detail-loading">
          <RefreshCw :size="15" class="spin-soft" />
          <span>Loading subject trends...</span>
        </div>

        <div v-else-if="reportSubjectPerformance.length === 0" class="room-empty-state compact">
          <BookOpen :size="30" />
          <h4>No subject trends yet</h4>
          <p>Submitted student attempts will populate the score breakdown by subject.</p>
        </div>

        <div v-else class="subject-list">
          <div v-for="subject in reportSubjectPerformance" :key="subject.label" class="subject-item">
            <div class="subject-head">
              <span>{{ subject.label }}</span>
              <strong :class="{ ok: Number(subject.score ?? 0) >= 75, danger: Number(subject.score ?? 0) < 75 }">
                {{ formatPercent(subject.score) }}
              </strong>
            </div>
            <div class="bar-track">
              <div
                class="bar-fill"
                :style="{ width: `${Number(subject.score ?? 0)}%` }"
                :class="{ ok: Number(subject.score ?? 0) >= 75, danger: Number(subject.score ?? 0) < 75 }"
              />
            </div>
          </div>
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

    <article class="surface-card">
      <header class="surface-head">
        <h3>Item-Level Analytics</h3>
        <span class="pill navy">Question Difficulty Tracking</span>
      </header>

      <div class="room-form-grid" style="margin-bottom: 24px;">
        <label class="field-stack">
          <span class="field-label">Target Exam</span>
          <select v-model="itemAnalyticsExamId" class="text-input" :disabled="reportTargetsLoading">
            <option value="">Select an exam to analyze</option>
            <option v-for="exam in reportExportExams" :key="exam.id" :value="String(exam.id)">
              {{ exam.title }}
            </option>
          </select>
        </label>
      </div>

      <div v-if="itemAnalyticsLoading" class="room-detail-loading">
        <RefreshCw :size="15" class="spin-soft" />
        <span>Calculating item difficulties...</span>
      </div>
      
      <div v-else-if="itemAnalyticsError" class="feedback danger" style="margin-bottom: 0;">
        <AlertCircle :size="15" />
        <span>{{ itemAnalyticsError }}</span>
      </div>

      <div v-else-if="itemAnalyticsExamId && itemAnalyticsData.length === 0" class="room-empty-state compact">
        <h4>No attempts recorded</h4>
        <p>Wait for students to complete this exam to analyze item failure rates.</p>
      </div>
      
      <div v-else-if="itemAnalyticsData.length" class="activity-list">
        <div v-for="item in itemAnalyticsData" :key="item.question_id" class="activity-item">
          <div class="activity-content">
            <strong>{{ item.question_text }}</strong>
            <small>{{ item.total_attempts }} total attempts recorded.</small>
          </div>
          <strong :class="{ danger: item.failure_rate_percent >= 50, ok: item.failure_rate_percent < 50 }">
            {{ item.failure_rate_percent }}% failure
          </strong>
        </div>
      </div>
    </article>

    <details class="surface-card collapsible-card report-export-card">
      <summary class="collapsible-summary">
        <div class="surface-head-copy">
          <span class="section-kicker">Export Center</span>
          <h3>Generate Session Reports</h3>
          <p>Download or email result files only when you need them, without crowding the main report view.</p>
        </div>

        <div class="collapsible-summary-meta">
          <span class="pill" :class="canExportSessionReports ? 'success' : 'neutral'">
            {{ canExportSessionReports ? 'Ready' : 'Select exam and room' }}
          </span>
          <ChevronDown :size="18" class="collapsible-icon" />
        </div>
      </summary>

      <div class="collapsible-content">
        <div class="surface-head compact">
          <h3>Export Setup</h3>
          <button type="button" class="ghost-btn" :disabled="reportTargetsLoading" @click="loadReportExportTargets(true)">
            <RefreshCw :size="14" :class="{ 'spin-soft': reportTargetsLoading }" />
            Refresh
          </button>
        </div>

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
              <span class="field-label">Student (optional)</span>
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

          <div class="overview-pill-row report-selection-row" v-if="selectedReportExam || selectedReportRoom || selectedReportStudent">
            <span v-if="selectedReportExam" class="pill navy">{{ selectedReportExam.title }}</span>
            <span v-if="selectedReportRoom" class="pill neutral">
              {{ selectedReportRoom.name }}{{ selectedReportRoom.code ? ` (${selectedReportRoom.code})` : '' }}
            </span>
            <span v-if="selectedReportStudent" class="pill success">{{ selectedReportStudent.name }}</span>
          </div>

          <div class="report-action-sections">
            <section class="action-section">
              <header class="action-section-head">
                <h4>Downloads</h4>
                <p>Use these when you need printable or downloadable files for the panel or class records.</p>
              </header>

              <div class="report-action-grid">
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('xlsx')"
                >
                  <RefreshCw v-if="reportExportingKey === 'xlsx'" :size="14" class="spin-soft" />
                  <span>Results XLSX</span>
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('csv')"
                >
                  <RefreshCw v-if="reportExportingKey === 'csv'" :size="14" class="spin-soft" />
                  <span>Results CSV</span>
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('summary_pdf')"
                >
                  <RefreshCw v-if="reportExportingKey === 'summary_pdf'" :size="14" class="spin-soft" />
                  <span>Summary PDF</span>
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('answer_key_pdf')"
                >
                  <RefreshCw v-if="reportExportingKey === 'answer_key_pdf'" :size="14" class="spin-soft" />
                  <span>Answer Key PDF</span>
                </button>
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSingleStudentReport || reportExportingKey !== ''"
                  @click="exportReport('student_pdf')"
                >
                  <RefreshCw v-if="reportExportingKey === 'student_pdf'" :size="14" class="spin-soft" />
                  <span>Student PDF</span>
                </button>
                <button
                  type="button"
                  class="primary-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="exportReport('student_zip')"
                >
                  <RefreshCw v-if="reportExportingKey === 'student_zip'" :size="14" class="spin-soft" />
                  <span>All PDFs ZIP</span>
                </button>
              </div>
            </section>

            <section class="action-section">
              <header class="action-section-head">
                <h4>Email Delivery</h4>
                <p>Use these only when you are ready to send finalized student reports.</p>
              </header>

              <div class="report-action-grid">
                <button
                  type="button"
                  class="ghost-btn"
                  :disabled="!canExportSingleStudentReport || reportExportingKey !== ''"
                  @click="sendReportEmail('student_email')"
                >
                  <RefreshCw v-if="reportExportingKey === 'student_email'" :size="14" class="spin-soft" />
                  <span>Email Student PDF</span>
                </button>
                <button
                  type="button"
                  class="primary-btn"
                  :disabled="!canExportSessionReports || reportExportingKey !== ''"
                  @click="sendReportEmail('student_email_bulk')"
                >
                  <RefreshCw v-if="reportExportingKey === 'student_email_bulk'" :size="14" class="spin-soft" />
                  <span>Email All Reports</span>
                </button>
              </div>
            </section>
          </div>
        </template>
      </div>
    </details>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { AlertCircle, BarChart3, BookOpen, CheckCircle2, ChevronDown, RefreshCw } from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useReportsModule } from '../composables/useReportsModule'

const {
  reportLoading,
  reportError,
  reportActivity,
  reportSessionPerformance,
  reportSubjectPerformance,
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
  formatPercent,
  loadReports,
  loadReportExportTargets,
  exportReport,
  sendReportEmail,
  itemAnalyticsData,
  itemAnalyticsLoading,
  itemAnalyticsError,
  itemAnalyticsExamId,
} = useReportsModule()

const selectedReportExam = computed(() => (
  reportExportExams.value.find((exam) => String(exam.id) === String(reportExportForm.exam_id)) ?? null
))

const selectedReportRoom = computed(() => (
  reportExportRoomOptions.value.find((room) => String(room.id) === String(reportExportForm.room_id)) ?? null
))

const selectedReportStudent = computed(() => (
  reportExportStudents.value.find((student) => String(student.id) === String(reportExportForm.student_id)) ?? null
))
</script>

<style scoped src="../dashboard.css"></style>
