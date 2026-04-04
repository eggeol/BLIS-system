<template>
  <section class="dashboard-view student-analytics-bento">
    <div v-if="insightsError" class="feedback danger bento-feedback">
      <AlertCircle :size="15" />
      <span>{{ insightsError }}</span>
    </div>

    <article v-if="showInitialLoading" class="bento-loading">
      <RefreshCw :size="24" class="spin-soft" />
      <h4>Loading your performance data</h4>
    </article>

    <template v-else>
      <div class="bento-grid">
        <!-- 1. The Performance Nexus (Hero) -->
        <article class="bento-card bento-hero">
          <div class="hero-content">
            <span class="hero-kicker">Performance Mastery</span>
            <h3>{{ strongestSubject ? `Excelling in ${strongestSubject.label}` : 'Begin your journey' }}</h3>
            <p v-if="strongestSubject">
              You're holding a powerful {{ averageScoreLabel }} average across {{ summary.attemptsSubmitted }} submissions.
              {{ focusSubjects.length === 0 ? 'Everything is on track. Keep it up!' : `Keep pushing on ${focusSubjects.length} specific focus areas.` }}
            </p>
            <p v-else>
              Complete your first exam to unlock deep analytics, mastery tracks, and trend visualizations.
            </p>
            <button v-if="focusSubjects.length > 0" class="hero-action-btn" type="button" @click="scrollToFocus">
              Review Focus Areas
            </button>
          </div>
          <div v-if="summary.attemptsSubmitted > 0" class="hero-visual">
            <div class="hero-ring" :style="{ '--score': summary.averageScorePercent || 0 }">
              <div class="hero-ring-inner">
                <span class="hero-ring-value">{{ Math.round(summary.averageScorePercent || 0) }}<small>%</small></span>
                <span class="hero-ring-label">Aggregate</span>
              </div>
            </div>
          </div>
        </article>

        <!-- 2. The Stat Grid (Small cards) -->
        <div class="bento-stat-group">
          <article class="bento-stat-card">
            <div class="bento-stat-icon tint-blue"><Target :size="20"/></div>
            <div class="bento-stat-info">
              <span class="bento-stat-val">{{ summary.completedExams }}</span>
              <span class="bento-stat-lbl">Completed Exams</span>
            </div>
          </article>
          <article class="bento-stat-card">
            <div class="bento-stat-icon tint-gold"><Award :size="20"/></div>
            <div class="bento-stat-info">
              <span class="bento-stat-val">{{ bestScoreLabel }}</span>
              <span class="bento-stat-lbl">Peak Score</span>
            </div>
          </article>
          <article class="bento-stat-card">
            <div class="bento-stat-icon tint-emerald"><TrendingUp :size="20"/></div>
            <div class="bento-stat-info">
              <span class="bento-stat-val">{{ averageScoreLabel }}</span>
              <span class="bento-stat-lbl">Average Score</span>
            </div>
          </article>
          <article class="bento-stat-card">
            <div class="bento-stat-icon" :class="focusSubjects.length > 0 ? 'tint-rose' : 'tint-slate'"><ShieldCheck :size="20"/></div>
            <div class="bento-stat-info">
              <span class="bento-stat-val">{{ subjects.length }}</span>
              <span class="bento-stat-lbl">Tracked Skills</span>
            </div>
          </article>
        </div>

        <!-- 3. Subject Mastery -->
        <article class="bento-card bento-mastery">
          <header class="bento-head">
            <div class="bento-head-title">
              <h4>Subject Mastery</h4>
              <span class="pill-badge" :class="subjects.length ? 'navy' : 'neutral'">{{ subjects.length }} Active</span>
            </div>
          </header>
          
          <div v-if="subjects.length === 0" class="bento-empty">
            <ClipboardList :size="28" />
            <p>Awaiting exam data</p>
          </div>
          <div v-else class="mastery-list">
            <div v-for="subject in subjects" :key="subject.id" class="mastery-item" :class="{ 'is-focus': subject.score < summary.passingThreshold }">
              <div class="mastery-info">
                <strong>{{ subject.label }}</strong>
                <span>{{ subject.scoreLabel }}</span>
              </div>
              <div class="mastery-track">
                <div class="mastery-fill" :style="{ width: `${subject.score}%` }"></div>
              </div>
            </div>
          </div>
        </article>

        <!-- 4. Progress Trend -->
        <article class="bento-card bento-trend">
          <header class="bento-head">
            <div class="bento-head-title">
              <h4>Progress Momentum</h4>
              <span class="pill-badge neutral">Last {{ scoreHistory.length || 0 }}</span>
            </div>
          </header>

          <div v-if="scoreHistory.length === 0" class="bento-empty">
            <BarChart3 :size="28" />
            <p>Requires attempts</p>
          </div>
          <div v-else class="trend-chart">
            <div v-for="entry in scoreHistory" :key="entry.id" class="trend-bar-wrapper">
              <div class="trend-bar" :title="`${entry.label} • ${entry.scoreLabel}`">
                <div class="trend-fill" :class="{ danger: entry.score < summary.passingThreshold }" :style="{ height: `${entry.score}%` }"></div>
              </div>
              <span class="trend-label">{{ entry.shortLabel }}</span>
            </div>
          </div>
        </article>

        <!-- 5. Recent Activity -->
        <article id="focus-section" class="bento-card bento-recent">
          <header class="bento-head">
            <div class="bento-head-title">
              <h4>Recent Diagnostics</h4>
              <span class="pill-badge rose" v-if="focusSubjects.length > 0">{{ focusSubjects.length }} Need Review</span>
              <span class="pill-badge success" v-else>All Clear</span>
            </div>
          </header>

          <div v-if="recentResults.length === 0" class="bento-empty">
            <Clock3 :size="28" />
            <p>No recent files</p>
          </div>
          <div v-else class="activity-timeline">
            <div v-for="entry in recentResults" :key="entry.id" class="activity-item" :class="{ danger: entry.score < summary.passingThreshold }">
              <div class="activity-node"></div>
              <div class="activity-content">
                <strong>{{ entry.label }}</strong>
                <small>{{ entry.meta }}</small>
              </div>
              <div class="activity-score">{{ entry.scoreLabel }}</div>
            </div>
          </div>
        </article>
      </div>
    </template>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { AlertCircle, AlertTriangle, BarChart3, ClipboardList, Clock3, RefreshCw, ShieldCheck, Target, Award, TrendingUp } from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useStudentInsightsModule } from '../composables/useStudentInsightsModule'

const {
  averageScoreLabel,
  bestScoreLabel,
  focusSubjects,
  insightsError,
  recentResults,
  scoreHistory,
  showInitialLoading,
  strongestSubject,
  subjects,
  summary,
} = useStudentInsightsModule()

const scrollToFocus = () => {
  const el = document.getElementById('focus-section')
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
}
</script>

<style scoped src="../dashboard.css"></style>
<style scoped>
/* Bento Grid Layout */
.student-analytics-bento {
  padding-bottom: 40px;
}
.bento-feedback {
  margin-bottom: 24px;
}
.bento-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80px 20px;
  background: white;
  border-radius: 16px;
  color: #64748b;
  gap: 16px;
  min-height: 400px;
}
.bento-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 24px;
}

/* Individual Bento Cards */
.bento-card {
  background: #ffffff;
  border-radius: 20px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03), 0 1px 3px rgba(0,0,0,0.02);
  padding: 28px;
  display: flex;
  flex-direction: column;
}
.bento-hero {
  grid-column: span 2;
  background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
  color: #ffffff;
  flex-direction: row;
  justify-content: space-between;
  align-items: center;
  overflow: hidden;
  position: relative;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.15);
}
.bento-stat-group {
  grid-column: span 1;
  display: grid;
  grid-template-columns: 1fr 1fr;
  grid-template-rows: 1fr 1fr;
  gap: 16px;
}
.bento-mastery, .bento-trend, .bento-recent {
  grid-column: span 1;
  min-height: 380px;
}

/* Hero Content */
.hero-content {
  max-width: 65%;
  z-index: 2;
}
.hero-kicker {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #93c5fd;
  margin-bottom: 8px;
  display: block;
}
.hero-content h3 {
  font-size: 1.75rem;
  font-weight: 700;
  margin: 0 0 12px 0;
  line-height: 1.2;
}
.hero-content p {
  font-size: 0.95rem;
  color: #bfdbfe;
  line-height: 1.5;
  margin: 0 0 24px 0;
}
.hero-action-btn {
  background: rgba(255, 255, 255, 0.15);
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: white;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  backdrop-filter: blur(4px);
}
.hero-action-btn:hover {
  background: rgba(255, 255, 255, 0.25);
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

/* Hero Visual / Ring */
.hero-visual {
  z-index: 2;
  display: flex;
  justify-content: center;
  align-items: center;
}
.hero-ring {
  width: 140px;
  height: 140px;
  border-radius: 50%;
  background: conic-gradient(from 0deg, #60a5fa calc(var(--score) * 1%), rgba(255, 255, 255, 0.08) 0);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  box-shadow: 0 0 24px rgba(96, 165, 250, 0.2), inset 0 0 16px rgba(0,0,0,0.2);
  animation: fade-in-scale 0.8s ease-out;
}
.hero-ring::before {
  content: "";
  position: absolute;
  width: 116px;
  height: 116px;
  border-radius: 50%;
  background: linear-gradient(135deg, #1e3a8a, #0f172a);
  box-shadow: inset 0 2px 6px rgba(0,0,0,0.4);
}
.hero-ring-inner {
  position: relative;
  z-index: 10;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.hero-ring-value {
  font-size: 1.8rem;
  font-weight: 800;
  line-height: 1;
  color: #fff;
  text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}
.hero-ring-value small {
  font-size: 1rem;
  opacity: 0.8;
}
.hero-ring-label {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #93c5fd;
  margin-top: 4px;
}
@keyframes fade-in-scale {
  0% { transform: scale(0.9); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}

/* Stat Cards */
.bento-stat-card {
  background: #ffffff;
  border-radius: 20px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03), 0 1px 2px rgba(0,0,0,0.02);
  padding: 20px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.bento-stat-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 16px;
}
.bento-stat-icon.tint-blue { background: #eff6ff; color: #3b82f6; }
.bento-stat-icon.tint-gold { background: #fefce8; color: #eab308; }
.bento-stat-icon.tint-emerald { background: #ecfdf5; color: #10b981; }
.bento-stat-icon.tint-rose { background: #fff1f2; color: #f43f5e; border: 1px solid #ffe4e6; }
.bento-stat-icon.tint-slate { background: #f8fafc; color: #64748b; }
.bento-stat-info {
  display: flex;
  flex-direction: column;
}
.bento-stat-val {
  font-size: 1.25rem;
  font-weight: 700;
  color: #0f172a;
}
.bento-stat-lbl {
  font-size: 0.8rem;
  color: #64748b;
  font-weight: 500;
}

/* Bento Headers */
.bento-head {
  margin-bottom: 24px;
}
.bento-head-title {
  display: flex;
  align-items: center;
  gap: 12px;
}
.bento-head-title h4 {
  font-size: 1.1rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
}
.pill-badge {
  font-size: 0.7rem;
  font-weight: 600;
  padding: 4px 8px;
  border-radius: 20px;
  background: #f1f5f9;
  color: #475569;
}
.pill-badge.navy { background: #e0e7ff; color: #4338ca; }
.pill-badge.rose { background: #ffe4e6; color: #e11d48; }
.pill-badge.success { background: #dcfce7; color: #15803d; }

/* Empty States */
.bento-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #cbd5e1;
  gap: 12px;
  text-align: center;
}
.bento-empty p {
  font-size: 0.9rem;
  color: #94a3b8;
}

/* Mastery Track */
.mastery-list {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.mastery-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.mastery-info {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.85rem;
}
.mastery-info strong {
  color: #334155;
  font-weight: 600;
}
.mastery-info span {
  color: #10b981;
  font-weight: 700;
}
.mastery-item.is-focus .mastery-info span {
  color: #f43f5e;
}
.mastery-track {
  width: 100%;
  height: 10px;
  background: #f1f5f9;
  border-radius: 4px;
  overflow: hidden;
  box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
}
.mastery-fill {
  height: 100%;
  background: linear-gradient(90deg, #34d399, #10b981);
  border-radius: 4px;
  transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
}
.mastery-item.is-focus .mastery-fill {
  background: linear-gradient(90deg, #fb7185, #f43f5e);
}

/* Trend Chart */
.trend-chart {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex: 1;
  gap: 8px;
  padding-bottom: 8px;
}
.trend-bar-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  flex: 1;
}
.trend-bar {
  width: 100%;
  max-width: 28px;
  height: 200px;
  background: #f8fafc;
  border-radius: 14px;
  display: flex;
  align-items: flex-end;
  padding: 4px;
  box-sizing: border-box;
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
}
.trend-fill {
  width: 100%;
  background: linear-gradient(to top, #3b82f6, #60a5fa);
  border-radius: 10px;
  min-height: 10px;
  transition: height 1s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 4px 6px rgba(59, 130, 246, 0.2);
}
.trend-fill.danger {
  background: linear-gradient(to top, #f43f5e, #fb7185);
  box-shadow: 0 4px 6px rgba(244, 63, 94, 0.2);
}
.trend-label {
  font-size: 0.75rem;
  color: #64748b;
  font-weight: 600;
}

/* Activity Timeline */
.activity-timeline {
  display: flex;
  flex-direction: column;
  position: relative;
  gap: 24px;
}
.activity-timeline::before {
  content: "";
  position: absolute;
  top: 8px;
  bottom: 0;
  left: 5px;
  width: 2px;
  background: #f1f5f9;
}
.activity-item {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  position: relative;
}
.activity-node {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #cbd5e1;
  border: 3px solid #ffffff;
  box-shadow: 0 0 0 1px #e2e8f0;
  margin-top: 4px;
  z-index: 1;
}
.activity-content {
  display: flex;
  flex-direction: column;
  flex: 1;
}
.activity-content strong {
  font-size: 0.9rem;
  color: #0f172a;
  line-height: 1.3;
  margin-bottom: 2px;
}
.activity-content small {
  font-size: 0.75rem;
  color: #94a3b8;
}
.activity-score {
  font-weight: 700;
  font-size: 0.9rem;
  color: #10b981;
}
.activity-item.danger .activity-node {
  background: #f43f5e;
  box-shadow: 0 0 0 1px #ffe4e6;
}
.activity-item.danger .activity-score {
  color: #f43f5e;
}

/* Responsiveness */
@media (max-width: 1100px) {
  .bento-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .bento-hero { grid-column: span 2; }
  .bento-stat-group { grid-column: span 2; grid-template-columns: repeat(4, 1fr); grid-template-rows: 1fr; }
  .bento-mastery, .bento-trend, .bento-recent { grid-column: span 1; }
}

@media (max-width: 768px) {
  .bento-grid {
    grid-template-columns: 1fr;
  }
  .bento-hero, .bento-stat-group, .bento-mastery, .bento-trend, .bento-recent {
    grid-column: span 1;
  }
  .bento-hero {
    flex-direction: column;
    text-align: center;
    gap: 24px;
  }
  .hero-content {
    max-width: 100%;
  }
  .bento-stat-group {
    grid-template-columns: repeat(2, 1fr);
    grid-template-rows: repeat(2, 1fr);
  }
}
</style>
