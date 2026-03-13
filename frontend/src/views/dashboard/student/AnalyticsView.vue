<template>
  <section class="dashboard-view">
    <div class="stats-grid">
      <article class="stat-card">
        <div class="stat-icon tone-navy">
          <BarChart3 :size="17" />
        </div>
        <div>
          <p class="stat-label">Average Score</p>
          <p class="stat-value">{{ averageScore }}%</p>
          <p class="stat-trend positive">Across {{ subjects.length }} tracked subjects</p>
        </div>
      </article>

      <article class="stat-card">
        <div class="stat-icon tone-success">
          <ShieldCheck :size="17" />
        </div>
        <div>
          <p class="stat-label">Strongest Subject</p>
          <p class="stat-value">{{ strongestSubject?.label || 'N/A' }}</p>
          <p class="stat-trend positive">{{ strongestSubject?.score ?? 0 }}% latest score</p>
        </div>
      </article>

      <article class="stat-card">
        <div class="stat-icon tone-gold">
          <AlertTriangle :size="17" />
        </div>
        <div>
          <p class="stat-label">Needs Review</p>
          <p class="stat-value">{{ focusSubjects.length }}</p>
          <p class="stat-trend" :class="{ positive: focusSubjects.length === 0, negative: focusSubjects.length > 0 }">
            {{ focusSubjects.length === 0 ? 'No subjects below passing mark' : 'Below 75% threshold' }}
          </p>
        </div>
      </article>
    </div>

    <div class="dashboard-grid">
      <article class="surface-card">
        <header class="surface-head">
          <h3>Subject Performance</h3>
          <span class="pill navy">{{ subjects.length }} subjects</span>
        </header>

        <div class="subject-list">
          <div v-for="subject in subjects" :key="subject.label" class="subject-item">
            <div class="subject-head">
              <span>{{ subject.label }}</span>
              <strong :class="{ ok: subject.score >= 75, danger: subject.score < 75 }">{{ subject.score }}%</strong>
            </div>
            <div class="bar-track">
              <div class="bar-fill" :style="{ width: `${subject.score}%` }" :class="{ ok: subject.score >= 75, danger: subject.score < 75 }" />
            </div>
          </div>
        </div>
      </article>

      <article class="surface-card">
        <header class="surface-head">
          <h3>Progress Trend</h3>
          <span class="pill neutral">Last 6 tests</span>
        </header>

        <div class="history-chart">
          <div v-for="(score, index) in scoreHistory" :key="index" class="history-item">
            <span>{{ score }}%</span>
            <div class="history-bar-track">
              <div class="history-bar-fill" :style="{ height: `${score}%` }" :class="{ ok: score >= 75, danger: score < 75 }" />
            </div>
            <small>T{{ index + 1 }}</small>
          </div>
        </div>
      </article>
    </div>

    <div class="dashboard-grid bottom">
      <article class="surface-card">
        <header class="surface-head">
          <h3>Focus Subjects</h3>
          <span class="pill" :class="focusSubjects.length === 0 ? 'success' : 'neutral'">
            {{ focusSubjects.length === 0 ? 'On Track' : `${focusSubjects.length} item(s)` }}
          </span>
        </header>

        <div v-if="focusSubjects.length === 0" class="room-empty-state compact">
          <ShieldCheck :size="30" />
          <h4>All subjects are above passing</h4>
          <p>Maintain your current review pace to keep this trend.</p>
        </div>

        <div v-else class="subject-list">
          <div v-for="subject in focusSubjects" :key="subject.label" class="subject-item">
            <div class="subject-head">
              <span>{{ subject.label }}</span>
              <strong class="danger">{{ subject.score }}%</strong>
            </div>
            <div class="bar-track">
              <div class="bar-fill danger" :style="{ width: `${subject.score}%` }" />
            </div>
          </div>
        </div>
      </article>

      <article class="surface-card">
        <header class="surface-head">
          <h3>Recent Activity</h3>
        </header>

        <div class="activity-list">
          <div v-for="item in activities" :key="item.title" class="activity-item">
            <span class="activity-dot" :class="{ ok: item.positive, danger: !item.positive }" />
            <div class="activity-content">
              <strong>{{ item.title }}</strong>
              <small>{{ item.meta }}</small>
            </div>
            <strong :class="{ ok: item.positive, danger: !item.positive }">{{ item.score }}</strong>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>

<script setup>
import { AlertTriangle, BarChart3, ShieldCheck } from 'lucide-vue-next'
import { useStudentInsightsModule } from '../composables/useStudentInsightsModule'

const {
  activities,
  averageScore,
  focusSubjects,
  scoreHistory,
  strongestSubject,
  subjects,
} = useStudentInsightsModule()
</script>

<style scoped src="../dashboard.css"></style>
