<template>
  <section class="room-view">
    <div v-if="directoryError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ directoryError }}</span>
    </div>

    <article class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <UserRound :size="18" />
          <h3>Students</h3>
        </div>
        <button type="button" class="ghost-btn" :disabled="directoryLoading" @click="loadStudentsDirectory">
          <RefreshCw :size="14" :class="{ 'spin-soft': directoryLoading }" />
          Refresh
        </button>
      </header>

      <div class="user-directory-summary">
        <article
          v-for="card in studentDirectoryCards"
          :key="card.key"
          class="user-directory-summary-card"
          :class="`is-${card.tone}`"
        >
          <span>{{ card.label }}</span>
          <strong>{{ card.value }}</strong>
          <small>{{ card.note }}</small>
        </article>
      </div>

      <div class="management-toolbar">
        <input
          v-model.trim="studentFilters.search"
          type="text"
          class="text-input"
          placeholder="Search name, student ID, email, room, or exam"
        />
        <select v-model="studentFilters.year_level" class="text-input narrow">
          <option value="">All year levels</option>
          <option v-for="option in yearLevelOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <select v-model="studentFilters.archive_state" class="text-input narrow">
          <option value="current">Current records</option>
          <option value="archived">Archived records</option>
          <option value="all">All records</option>
        </select>
      </div>

      <div v-if="directoryLoading && filteredStudents.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading students</h4>
        <p>Preparing the year-level directory and performance summary.</p>
      </div>

      <div v-else-if="filteredStudents.length === 0" class="room-empty-state">
        <UserRound :size="42" />
        <h4>No students found</h4>
        <p>Adjust the filters to show current or archived student records.</p>
      </div>

      <div v-else class="user-directory-shell">
        <section v-if="currentStudentGroups.length > 0" class="directory-section">
          <header class="directory-section-head">
            <div>
              <h4>Current Students</h4>
              <p>Active student records are grouped by year level for quicker scanning.</p>
            </div>
          </header>

          <div class="directory-group-stack">
            <article v-for="group in currentStudentGroups" :key="group.key" class="directory-group-card">
              <div class="directory-group-head">
                <div>
                  <strong>{{ group.label }}</strong>
                  <p>{{ group.count }} student{{ group.count === 1 ? '' : 's' }}</p>
                </div>
                <span class="pill navy">{{ group.count }}</span>
              </div>

              <div class="user-directory-grid">
                <article v-for="student in group.students" :key="student.id" class="directory-user-card">
                  <div class="directory-user-copy">
                    <strong>{{ student.name }}</strong>
                    <p v-if="student.student_id">Student ID: {{ student.student_id }}</p>
                    <p>{{ student.email }}</p>
                  </div>

                  <div class="management-inline">
                    <span class="pill navy">{{ yearLevelLabel(student.year_level) }}</span>
                    <span class="pill neutral">{{ student.room_count }} section{{ student.room_count === 1 ? '' : 's' }}</span>
                    <span
                      class="pill"
                      :class="student.attempts_submitted > 0 ? (Number(student.average_score_percent ?? 0) >= 75 ? 'success' : 'danger') : 'neutral'"
                    >
                      {{ formatPercent(student.average_score_percent) }}
                    </span>
                  </div>

                  <div v-if="student.room_names.length > 0" class="management-inline">
                    <span v-for="roomName in student.room_names.slice(0, 2)" :key="`${student.id}-${roomName}`" class="pill neutral">
                      {{ roomName }}
                    </span>
                    <span v-if="student.room_names.length > 2" class="pill neutral">
                      +{{ student.room_names.length - 2 }} more
                    </span>
                  </div>

                  <div class="management-meta-grid three-up">
                    <div class="directory-student-metric">
                      <span>Submitted</span>
                      <strong>{{ student.attempts_submitted }}</strong>
                    </div>
                    <div class="directory-student-metric">
                      <span>Pass Rate</span>
                      <strong>{{ formatPercent(student.pass_rate_percent) }}</strong>
                    </div>
                    <div class="directory-student-metric">
                      <span>Latest</span>
                      <strong>{{ formatPercent(student.latest_score_percent) }}</strong>
                    </div>
                  </div>

                  <p class="directory-student-note">
                    <strong>Latest Result:</strong>
                    {{ latestPerformanceLabel(student) }}
                  </p>
                  <p class="directory-student-note">{{ studentActivityLabel(student) }}</p>
                </article>
              </div>
            </article>
          </div>
        </section>

        <section v-if="archivedStudentGroups.length > 0" class="directory-section">
          <header class="directory-section-head">
            <div>
              <h4>Archived Student Records</h4>
              <p>Historical records stay here instead of appearing inside room rosters.</p>
            </div>
          </header>

          <div class="directory-group-stack">
            <article v-for="group in archivedStudentGroups" :key="group.key" class="directory-group-card archived">
              <div class="directory-group-head">
                <div>
                  <strong>{{ group.label }}</strong>
                  <p>{{ group.count }} archived record{{ group.count === 1 ? '' : 's' }}</p>
                </div>
                <span class="pill neutral">{{ group.count }}</span>
              </div>

              <div class="user-directory-grid">
                <article v-for="student in group.students" :key="student.id" class="directory-user-card archived">
                  <div class="directory-user-copy">
                    <strong>{{ student.name }}</strong>
                    <p v-if="student.student_id">Student ID: {{ student.student_id }}</p>
                    <p>{{ student.email }}</p>
                    <p v-if="student.archived_at">Archived {{ new Date(student.archived_at).toLocaleDateString() }}</p>
                  </div>

                  <div class="management-inline">
                    <span class="pill danger">Archived</span>
                    <span class="pill navy">{{ yearLevelLabel(student.year_level) }}</span>
                    <span class="pill neutral">{{ student.room_count }} section{{ student.room_count === 1 ? '' : 's' }}</span>
                  </div>

                  <div class="management-meta-grid three-up">
                    <div class="directory-student-metric">
                      <span>Submitted</span>
                      <strong>{{ student.attempts_submitted }}</strong>
                    </div>
                    <div class="directory-student-metric">
                      <span>Pass Rate</span>
                      <strong>{{ formatPercent(student.pass_rate_percent) }}</strong>
                    </div>
                    <div class="directory-student-metric">
                      <span>Latest</span>
                      <strong>{{ formatPercent(student.latest_score_percent) }}</strong>
                    </div>
                  </div>

                  <p class="directory-student-note">
                    <strong>Latest Result:</strong>
                    {{ latestPerformanceLabel(student) }}
                  </p>
                  <p class="directory-student-note">{{ studentActivityLabel(student) }}</p>
                </article>
              </div>
            </article>
          </div>
        </section>
      </div>
    </article>
  </section>
</template>

<script setup>
import { AlertCircle, RefreshCw, UserRound } from 'lucide-vue-next'
import { useStudentsModule } from '../composables/useStudentsModule'

const {
  directoryLoading,
  directoryError,
  studentFilters,
  yearLevelOptions,
  filteredStudents,
  currentStudentGroups,
  archivedStudentGroups,
  studentDirectoryCards,
  yearLevelLabel,
  formatPercent,
  latestPerformanceLabel,
  studentActivityLabel,
  loadStudentsDirectory,
} = useStudentsModule()
</script>

<style scoped src="../dashboard.css"></style>
