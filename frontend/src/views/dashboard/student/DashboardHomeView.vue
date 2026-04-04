<template>
  <section class="dashboard-view">
    <div v-if="insightsError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ insightsError }}</span>
    </div>

    <article v-if="showInitialLoading" class="surface-card">
      <div class="room-empty-state compact">
        <RefreshCw :size="18" class="spin-soft" />
        <h4>Loading dashboard insights</h4>
        <p>Fetching your latest room activity and exam performance.</p>
      </div>
    </article>

    <template v-else>
      <article class="surface-card overview-card">
        <div class="overview-hero">
          <div class="overview-copy">
            <span class="section-kicker">Quick Overview</span>
            <h3>{{ standingHeadline }}</h3>
            <p>{{ standingMessage }}</p>

            <div class="overview-pill-row">
              <span class="pill" :class="standingTone === 'success' ? 'success' : 'neutral'">{{ standingLabel }}</span>
              <span class="pill navy">{{ averageScoreLabel }} average</span>
              <span class="pill neutral">{{ summary.pendingExams }} pending</span>
            </div>


          </div>

          <div class="overview-meter-panel">
            <span class="overview-metric-label">Current Pass Rate</span>
            <strong>{{ passingRateLabel }}</strong>
            <small>{{ summary.passingAttempts }}/{{ summary.attemptsSubmitted }} submitted attempts are passing</small>
          </div>
        </div>
      </article>

      <div class="stats-grid">
        <article v-for="stat in statCards" :key="stat.label" class="stat-card">
          <div class="stat-icon" :class="`tone-${stat.tone}`">
            <component :is="stat.icon" :size="17" />
          </div>
          <div>
            <p class="stat-label">{{ stat.label }}</p>
            <p class="stat-value">{{ stat.value }}</p>
            <p class="stat-trend" :class="{ positive: stat.positive, negative: !stat.positive }">
              {{ stat.trend }}
            </p>
          </div>
        </article>
      </div>

      <div class="dashboard-grid">
        <article class="surface-card">
          <header class="surface-head">
            <h3>Where To Focus</h3>
            <span class="pill" :class="focusSubjects.length === 0 ? 'success' : 'neutral'">
              {{ focusSubjects.length === 0 ? 'On Track' : `${focusSubjects.length} area(s)` }}
            </span>
          </header>

          <div class="insight-list">
            <div class="insight-item">
              <span>Current average</span>
              <strong>{{ averageScoreLabel }}</strong>
            </div>
            <div class="insight-item">
              <span>Best score</span>
              <strong>{{ bestScoreLabel }}</strong>
            </div>
            <div class="insight-item">
              <span>Passing threshold</span>
              <strong>{{ formatPercent(summary.passingThreshold, { maximumFractionDigits: 0 }) }}</strong>
            </div>
            <div class="insight-item">
              <span>Margin</span>
              <strong :class="{ ok: (marginFromPassing ?? -1) >= 0, danger: (marginFromPassing ?? -1) < 0 }">
                {{ marginLabel }}
              </strong>
            </div>
          </div>

          <div v-if="subjects.length === 0" class="room-empty-state compact">
            <ClipboardList :size="30" />
            <h4>No scored exams yet</h4>
            <p>Finish your first exam to start seeing subject guidance.</p>
          </div>

          <div v-else-if="focusSubjects.length === 0" class="overview-note success-soft">
            <strong>All tracked subjects are above the passing mark.</strong>
            <p>Maintain your current pace and use the analytics page for deeper trend review.</p>
          </div>

          <div v-else class="subject-list compact">
            <div v-for="subject in focusSubjects.slice(0, 3)" :key="subject.id" class="subject-item">
              <div class="subject-head">
                <span>{{ subject.label }}</span>
                <strong class="danger">{{ subject.scoreLabel }}</strong>
              </div>
              <div class="bar-track">
                <div class="bar-fill danger" :style="{ width: `${subject.score}%` }" />
              </div>
            </div>
          </div>
        </article>

        <article class="surface-card">
          <header class="surface-head">
            <h3>Current Standing</h3>
            <span class="pill navy">{{ summary.completedExams }} completed</span>
          </header>

          <div class="insight-list">
            <div class="insight-item">
              <span>Rooms joined</span>
              <strong>{{ summary.roomsJoined }}</strong>
            </div>
            <div class="insight-item">
              <span>Assigned exams</span>
              <strong>{{ summary.availableExams }}</strong>
            </div>
            <div class="insight-item">
              <span>In progress</span>
              <strong>{{ summary.inProgressAttempts }}</strong>
            </div>
            <div class="insight-item">
              <span>Status</span>
              <strong>{{ passingRateStatusLabel }}</strong>
            </div>
          </div>

          <div v-if="strongestSubject" class="overview-note">
            <strong>Strongest subject: {{ strongestSubject.label }}</strong>
            <p>{{ strongestSubject.scoreLabel }} across {{ strongestSubject.attemptsSubmitted }} submitted attempt(s).</p>
          </div>
        </article>
      </div>

      <div class="dashboard-grid bottom">
        <article class="surface-card">
          <header class="surface-head">
            <h3>Recent Activity</h3>
          </header>

          <div v-if="activities.length === 0" class="room-empty-state compact">
            <Clock3 :size="30" />
            <h4>No activity yet</h4>
            <p>Your latest exam attempts and submissions will show up here.</p>
          </div>

          <div v-else class="activity-list">
            <div v-for="item in activities" :key="item.id" class="activity-item">
              <span class="activity-dot" :class="{ ok: item.positive, danger: !item.positive }" />
              <div class="activity-content">
                <strong>{{ item.title }}</strong>
                <small>{{ item.meta }}</small>
              </div>
              <strong :class="{ ok: item.positive, danger: !item.positive }">{{ item.score }}</strong>
            </div>
          </div>
        </article>

        <article class="surface-card">
          <header class="surface-head">
            <h3>Recent Results</h3>
            <span class="pill neutral">Latest {{ recentResults.length }}</span>
          </header>

          <div v-if="recentResults.length === 0" class="room-empty-state compact">
            <BarChart3 :size="30" />
            <h4>No submitted results yet</h4>
            <p>Your latest graded attempts will appear here after submission.</p>
          </div>

          <div v-else class="result-list">
            <div v-for="entry in recentResults" :key="entry.id" class="result-item">
              <div class="result-copy">
                <strong>{{ entry.label }}</strong>
                <small>{{ entry.meta }}</small>
              </div>
              <strong :class="{ ok: entry.score >= summary.passingThreshold, danger: entry.score < summary.passingThreshold }">
                {{ entry.scoreLabel }}
              </strong>
            </div>
          </div>
        </article>
      </div>
    </template>
  </section>
</template>

<script setup>
import { RouterLink } from 'vue-router'
import { AlertCircle, BarChart3, ClipboardList, Clock3, RefreshCw } from 'lucide-vue-next'
import { useStudentInsightsModule } from '../composables/useStudentInsightsModule'

const {
  activities,
  averageScoreLabel,
  bestScoreLabel,
  focusSubjects,
  formatPercent,
  insightsError,
  marginFromPassing,
  marginLabel,
  passingRateLabel,
  passingRateStatusLabel,
  recentResults,
  showInitialLoading,
  statCards,
  standingHeadline,
  standingLabel,
  standingMessage,
  standingTone,
  strongestSubject,
  subjects,
  summary,
} = useStudentInsightsModule()
</script>

<style scoped src="../dashboard.css"></style>
