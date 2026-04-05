<template>
  <section class="dashboard-view student-dashboard-home">
    <div v-if="dashboardError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ dashboardError }}</span>
    </div>

    <article class="surface-card student-home-topbar">
      <div class="student-home-intro">
        <p class="student-home-kicker">Student workspace</p>
        <h3>{{ studentFirstName }}, this is your command center.</h3>
        <p class="student-home-summary">{{ dashboardSummary }}</p>

        <div class="student-home-signals">
          <span v-if="primaryNotice" class="signal-pill" :class="`is-${primaryNotice.tone}`">
            {{ primaryNotice.badge }}: {{ primaryNotice.title }}
          </span>
          <span v-else class="signal-pill is-neutral">No urgent exam notices right now</span>
        </div>

      <div class="student-home-actions">
        <button type="button" class="ghost-btn student-refresh-btn" :disabled="dashboardLoading" @click="loadDashboard">
          <RefreshCw :size="15" :class="{ 'spin-soft': dashboardLoading }" />
          Refresh
        </button>
      </div>
      </div>

      <div class="student-metric-grid">
        <article
          v-for="stat in decoratedStats"
          :key="stat.label"
          class="student-metric-card"
          :class="`is-${stat.tone}`"
        >
          <div class="student-metric-icon" :class="`tone-${stat.tone}`">
            <component :is="stat.icon" :size="17" />
          </div>
          <div>
            <p class="student-metric-label">{{ stat.label }}</p>
            <strong class="student-metric-value">{{ stat.value }}</strong>
            <p class="student-metric-hint">{{ stat.hint }}</p>
          </div>
        </article>
      </div>
    </article>

    <div class="student-home-shell">
      <article class="surface-card student-panel activity-panel">
        <header class="surface-head student-panel-head">
          <div>
            <h3>Notification Center</h3>
            <p>Everything that needs attention, plus your latest completed exams.</p>
          </div>
          <span class="pill navy">{{ notifications.length + recentExamCards.length }} items</span>
        </header>

        <div v-if="isInitialLoading" class="room-empty-state compact student-empty-state">
          <RefreshCw :size="28" class="spin-soft" />
          <h4>Loading activity</h4>
          <p>Pulling your latest notices and submitted exam results.</p>
        </div>

        <div v-else class="panel-scroll activity-feed-scroll">
          <section class="activity-group notices-group">
            <div class="activity-group-head">
              <div>
                <h4>Exam Notices</h4>
                <p>Available, resumable, retake-ready, or upcoming exams.</p>
              </div>
              <span class="pill success">{{ notifications.length }}</span>
            </div>

            <div v-if="notifications.length === 0" class="panel-empty">
              <BellRing :size="24" />
              <strong>No active exam notices</strong>
              <p>When a room opens a new exam or you can resume one, it will appear here.</p>
            </div>

            <div v-else class="activity-card-list">
              <article
                v-for="notice in notifications"
                :key="notice.id"
                class="activity-card notice-card"
                :class="`is-${notice.tone}`"
              >
                <div class="activity-card-icon" :class="`tone-${iconTone(notice.tone)}`">
                  <component :is="notificationIcon(notice.state)" :size="17" />
                </div>

                <div class="activity-card-body">
                  <div class="activity-card-head">
                    <div>
                      <strong>{{ notice.title }}</strong>
                      <p>{{ notice.roomName }} / {{ notice.roomCode }}</p>
                    </div>
                    <span class="pill" :class="pillClass(notice.tone)">{{ notice.badge }}</span>
                  </div>

                  <p class="activity-card-message">{{ notice.message }}</p>

                  <div class="activity-card-meta">
                    <span>{{ notice.totalItems }} items</span>
                    <span>{{ notice.durationMinutes }} mins</span>
                    <span v-if="notice.subject">{{ notice.subject }}</span>
                  </div>
                </div>
              </article>
            </div>
          </section>

          <section class="activity-group results-group">
            <div class="activity-group-head">
              <div>
                <h4>Recent Exams Taken</h4>
                <p>Your most recent submitted attempts and scores.</p>
              </div>
              <span class="pill neutral">{{ recentExamCards.length }}</span>
            </div>

            <div v-if="recentExamCards.length === 0" class="panel-empty">
              <ClipboardCheck :size="24" />
              <strong>No submitted exams yet</strong>
              <p>Once you finish an exam, the result summary will show up here.</p>
            </div>

            <div v-else class="activity-card-list">
              <article v-for="attempt in recentExamCards" :key="attempt.attempt_id" class="activity-card result-card">
                <div class="activity-card-icon" :class="`tone-${resultIconTone(attempt.scoreTone)}`">
                  <ClipboardCheck :size="17" />
                </div>

                <div class="activity-card-body">
                  <div class="activity-card-head">
                    <div>
                      <strong>{{ attempt.title }}</strong>
                      <p>
                        {{ attempt.room?.name || 'Room unavailable' }}
                        <span v-if="attempt.room?.code"> / {{ attempt.room.code }}</span>
                      </p>
                    </div>
                    <span class="pill" :class="scorePillClass(attempt.scoreTone)">{{ attempt.scoreDisplay }}</span>
                  </div>

                  <div class="activity-card-meta">
                    <span>{{ attempt.correct_answers }}/{{ attempt.total_items }} correct</span>
                    <span v-if="attempt.duration_minutes">{{ attempt.duration_minutes }} mins</span>
                    <span v-if="attempt.subject">{{ attempt.subject }}</span>
                  </div>

                  <p class="activity-card-message">{{ attempt.submittedAtText }}</p>
                </div>
              </article>
            </div>
          </section>
        </div>
      </article>

      <article class="surface-card student-panel rooms-panel">
        <header class="surface-head student-panel-head">
          <div>
            <h3>Your Rooms</h3>
          </div>
          <span class="pill gold">{{ roomSnapshots.length }}</span>
        </header>

        <div v-if="isInitialLoading" class="room-empty-state compact student-empty-state">
          <RefreshCw :size="28" class="spin-soft" />
          <h4>Loading rooms</h4>
          <p>Fetching your room list and exam status snapshots.</p>
        </div>

        <div v-else-if="roomSnapshots.length === 0" class="room-empty-state compact student-empty-state">
          <DoorOpen :size="24" />
          <h4>No rooms joined yet</h4>
          <p>Join a room from the Rooms page to receive exams and activity here.</p>
        </div>

        <div v-else class="panel-scroll rooms-scroll">
          <article v-for="room in roomSnapshots" :key="room.id" class="room-card">
            <div class="room-card-head">
              <div>
                <strong>{{ room.name }}</strong>
                <p>Code: {{ room.code }}</p>
              </div>
              <span class="pill neutral">{{ room.members_count }} members</span>
            </div>

            <div class="room-chip-row">
              <span class="room-chip">{{ room.openCount }} open</span>
              <span class="room-chip">{{ room.upcomingCount }} upcoming</span>
              <span class="room-chip">{{ room.exams_count }} assigned</span>
            </div>

            <p class="room-card-meta">
              Joined {{ room.joinedAtText }}
              <span v-if="room.creator?.name"> / {{ room.creator.name }}</span>
            </p>

            <div v-if="room.nextExam" class="room-card-highlight" :class="`is-${room.nextExam.tone}`">
              <span class="pill" :class="pillClass(room.nextExam.tone)">{{ room.nextExam.badge }}</span>
              <strong>{{ room.nextExam.title }}</strong>
              <p>{{ room.nextExam.message }}</p>
            </div>

            <div v-else class="room-card-highlight is-neutral">
              <strong>No active notices right now</strong>
              <p>This room is currently quiet.</p>
            </div>
          </article>
        </div>
      </article>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import {
  AlertCircle,
  BarChart3,
  BellRing,
  ClipboardCheck,
  Clock3,
  DoorOpen,
  RefreshCw,
  RotateCcw,
} from 'lucide-vue-next'
import { useStudentDashboardModule } from '../composables/useStudentDashboardModule'

const {
  studentFirstName,
  dashboardLoading,
  dashboardLoaded,
  dashboardError,
  statsCards,
  notifications,
  roomSnapshots,
  recentExamCards,
  loadDashboard,
} = useStudentDashboardModule()

const statIcons = [ClipboardCheck, BellRing, BarChart3]
const statTones = ['navy', 'gold', 'success']

const decoratedStats = computed(() => (
  statsCards.value.map((stat, index) => ({
    ...stat,
    icon: statIcons[index] ?? BellRing,
    tone: statTones[index] ?? 'navy',
  }))
))

const isInitialLoading = computed(() => dashboardLoading.value && !dashboardLoaded.value)

const primaryNotice = computed(() => notifications.value[0] ?? null)

const dashboardSummary = computed(() => {
  if (notifications.value.length > 0) {
    return `${notifications.value.length} exam notice${notifications.value.length === 1 ? '' : 's'} waiting in your feed.`
  }

  if (recentExamCards.value.length > 0) {
    return 'No urgent notices right now. Your latest exam results are still visible below.'
  }

  if (roomSnapshots.value.length > 0) {
    return 'Your rooms are ready. New exam activity will surface here as soon as it is assigned.'
  }

  return 'Join a room to start receiving assigned exams and activity updates.'
})

function notificationIcon(state) {
  if (state === 'in_progress') return RefreshCw
  if (state === 'retake') return RotateCcw
  if (state === 'upcoming') return Clock3
  return BellRing
}

function pillClass(tone) {
  if (tone === 'success') return 'success'
  if (tone === 'navy') return 'navy'
  if (tone === 'gold') return 'gold'
  if (tone === 'danger') return 'danger'
  return 'neutral'
}

function iconTone(tone) {
  if (tone === 'gold' || tone === 'danger') return 'gold'
  return tone
}

function resultIconTone(tone) {
  if (tone === 'danger') return 'danger'
  if (tone === 'gold') return 'gold'
  return 'success'
}

function scorePillClass(tone) {
  return pillClass(tone)
}
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.student-dashboard-home {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-height: 0;
}

.student-home-topbar {
  display: grid;
  grid-template-columns: minmax(0, 1.1fr) minmax(420px, 0.9fr);
  gap: 16px;
  padding: 18px;
  border-color: rgba(31, 63, 136, 0.34);
  background: #1f418f;
  box-shadow: 0 18px 32px rgba(20, 39, 88, 0.18);
}

.student-home-intro {
  display: grid;
  gap: 10px;
  align-content: start;
}

.student-home-kicker {
  margin: 0;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #f2d27a;
}

.student-home-intro h3 {
  margin: 0;
  font-size: 24px;
  color: #ffffff;
}

.student-home-summary {
  margin: 0;
  color: rgba(241, 245, 255, 0.88);
  line-height: 1.55;
  max-width: 640px;
}

.student-home-signals {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.signal-pill {
  display: inline-flex;
  align-items: center;
  max-width: 100%;
  padding: 7px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  background: #dbe6ff;
  color: #183b89;
}

.signal-pill.is-success {
  background: #ddf3e5;
  color: #22683e;
}

.signal-pill.is-navy {
  background: #dbe6ff;
  color: #183b89;
}

.signal-pill.is-gold,
.signal-pill.is-neutral {
  background: #ffe8b0;
  color: #7a5312;
}

.student-home-actions {
  display: flex;
  gap: 10px;
}

.student-home-topbar .ghost-btn {
  border-color: rgba(255, 232, 177, 0.36);
  background: rgba(255, 255, 255, 0.12);
  color: #fff4d6;
}

.student-home-topbar .ghost-btn:hover:not(:disabled) {
  border-color: rgba(255, 232, 177, 0.54);
  background: rgba(255, 255, 255, 0.18);
  color: #ffffff;
}

.student-metric-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  align-content: stretch;
}

.student-metric-card {
  border: 1px solid rgba(26, 35, 126, 0.1);
  border-radius: 16px;
  background: #ffffff;
  padding: 14px;
  display: flex;
  gap: 12px;
  align-items: flex-start;
  box-shadow: 0 10px 18px rgba(17, 33, 73, 0.1);
}

.student-metric-card.is-navy {
  background: #dfe9ff;
  border-color: rgba(58, 101, 198, 0.28);
}

.student-metric-card.is-gold {
  background: #ffefc4;
  border-color: rgba(205, 151, 43, 0.3);
}

.student-metric-card.is-success {
  background: #ddf3e5;
  border-color: rgba(63, 146, 94, 0.28);
}

.student-metric-icon {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.student-metric-label {
  margin: 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.student-metric-value {
  display: block;
  margin-top: 4px;
  font-size: 22px;
  color: var(--lnu-text);
}

.student-metric-hint {
  margin: 6px 0 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
  line-height: 1.45;
}

.student-home-shell {
  flex: 1;
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(330px, 0.88fr);
  gap: 14px;
}

.student-panel {
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.activity-panel {
  background: #eef4ff;
  border-color: rgba(57, 102, 199, 0.2);
}

.rooms-panel {
  background: #fff3d8;
  border-color: rgba(212, 162, 55, 0.22);
}

.student-panel-head {
  align-items: flex-start;
  margin-bottom: 12px;
}

.student-panel-head h3 {
  margin-bottom: 4px;
}

.student-panel-head p {
  margin: 0;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.activity-panel .student-panel-head h3 {
  color: #1e4699;
}

.rooms-panel .student-panel-head h3 {
  color: #8b5c10;
}

.panel-scroll {
  flex: 1;
  min-height: 0;
  overflow: auto;
  padding-right: 4px;
}

.activity-feed-scroll {
  display: grid;
  gap: 16px;
}

.activity-group {
  display: grid;
  gap: 10px;
  padding: 12px;
  border-radius: 18px;
  border: 1px solid rgba(26, 35, 126, 0.1);
}

.activity-group.notices-group {
  background: #ffffff;
  border-color: rgba(57, 102, 199, 0.18);
}

.activity-group.results-group {
  background: #fff8ec;
  border-color: rgba(212, 162, 55, 0.18);
}

.activity-group-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.activity-group-head h4 {
  margin: 0 0 4px;
  font-size: 15px;
  color: var(--lnu-text);
}

.activity-group-head p {
  margin: 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.activity-card-list {
  display: grid;
  gap: 10px;
}

.activity-card,
.room-card {
  border: 1px solid rgba(26, 35, 126, 0.12);
  border-radius: 16px;
  background: #ffffff;
  box-shadow: var(--shadow-sm);
}

.activity-card {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 12px;
  padding: 14px;
}

.activity-card-icon {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.activity-card-body {
  display: grid;
  gap: 9px;
  min-width: 0;
}

.activity-card-head,
.room-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.activity-card-head strong,
.room-card-head strong {
  display: block;
  font-size: 15px;
  color: var(--lnu-text);
}

.activity-card-head p,
.room-card-head p {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--lnu-text-muted);
}

.activity-card-message,
.room-card-meta {
  margin: 0;
  font-size: 13px;
  line-height: 1.5;
  color: var(--lnu-text-muted);
}

.activity-card-meta,
.room-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.activity-card-meta span,
.room-chip {
  border-radius: 999px;
  background: #e2ebff;
  padding: 6px 10px;
  font-size: 12px;
  color: #284892;
}

.notice-card.is-success {
  border-color: rgba(46, 125, 50, 0.22);
  background: #effaf2;
}

.notice-card.is-navy {
  border-color: rgba(26, 35, 126, 0.22);
  background: #eff4ff;
}

.notice-card.is-gold {
  border-color: rgba(201, 168, 76, 0.26);
  background: #fff5de;
}

.result-card {
  border-color: rgba(205, 151, 43, 0.2);
  background: #fffdf8;
}

.panel-empty,
.student-empty-state {
  min-height: 0;
}

.student-empty-state h4 {
  font-size: 22px;
}

.panel-empty {
  border: 1px dashed rgba(26, 35, 126, 0.18);
  border-radius: 14px;
  padding: 18px;
  display: grid;
  place-items: center;
  text-align: center;
  color: var(--lnu-text-muted);
  background: rgba(255, 255, 255, 0.74);
}

.panel-empty strong {
  display: block;
  margin-top: 8px;
  color: var(--lnu-text);
}

.panel-empty p {
  margin: 6px 0 0;
  max-width: 300px;
  line-height: 1.5;
}

.rooms-scroll {
  display: grid;
  gap: 10px;
}

.room-card {
  padding: 14px;
  display: grid;
  gap: 12px;
  border-color: rgba(212, 162, 55, 0.18);
  background: #fffdf7;
}

.room-card-highlight {
  border-radius: 14px;
  padding: 12px;
  display: grid;
  gap: 6px;
  border: 1px solid transparent;
}

.room-card-highlight strong,
.room-card-highlight p {
  margin: 0;
}

.room-card-highlight.is-success {
  background: #e8f6ec;
  border-color: rgba(46, 125, 50, 0.16);
}

.room-card-highlight.is-navy {
  background: #ebf2ff;
  border-color: rgba(57, 102, 199, 0.16);
}

.room-card-highlight.is-gold,
.room-card-highlight.is-neutral {
  background: #fff2d3;
  border-color: rgba(212, 162, 55, 0.18);
}

.pill.success {
  background: #ddf3e5;
  color: #22683e;
}

.pill.navy {
  background: #dbe6ff;
  color: #183b89;
}

.pill.neutral {
  background: #e6eefc;
  color: #385496;
}

.pill.gold {
  background: #ffe8b0;
  color: #7a5312;
}

.pill.danger {
  background: #fde0e0;
  color: #a13232;
}

@media (min-width: 901px) {
  .student-dashboard-home {
    height: 100%;
    overflow: hidden;
  }
}

@media (max-width: 1280px) {
  .student-home-topbar {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 1200px) {
  .student-home-shell {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 900px) {
  .student-dashboard-home {
    height: auto;
    overflow: visible;
  }

  .student-metric-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .student-home-actions {
    flex-direction: column;
  }

  .student-link-btn,
  .student-refresh-btn {
    width: 100%;
    justify-content: center;
  }

  .activity-card {
    grid-template-columns: 1fr;
  }
}
</style>
