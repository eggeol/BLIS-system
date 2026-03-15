<template>
  <section class="dashboard-view">
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
          <h3>Pass Probability</h3>
          <span class="pill success">DSS</span>
        </header>

        <div class="probability-meter">
          <div class="meter-ring" :style="{ '--value': '84' }">
            <strong>84%</strong>
            <span>Likely to pass</span>
          </div>
        </div>

        <ul class="metric-list">
          <li><span>Current average</span><strong>{{ averageScore }}%</strong></li>
          <li><span>Passing threshold</span><strong>75%</strong></li>
          <li><span>Margin</span><strong class="ok">+3 pts</strong></li>
        </ul>
      </article>

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
    </div>

    <div class="dashboard-grid bottom">
      <article class="surface-card">
        <header class="surface-head">
          <h3>Score History</h3>
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
import { useStudentInsightsModule } from '../composables/useStudentInsightsModule'

const {
  activities,
  averageScore,
  scoreHistory,
  statCards,
  subjects,
} = useStudentInsightsModule()
</script>

<style scoped src="../dashboard.css"></style>
