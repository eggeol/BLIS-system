<template>
  <section class="room-view">
    <div v-if="examMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ examMessage }}</span>
    </div>
    <div v-if="examError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ examError }}</span>
    </div>

    <article class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <FileText :size="18" />
          <h3>Exams</h3>
        </div>
        <button type="button" class="primary-btn add-room-btn" :disabled="examLoading" @click="openCreateExamModal">
          <Plus :size="16" />
          Add Exam
        </button>
      </header>

      <div v-if="examLoading && exams.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading exams</h4>
        <p>Please wait while we fetch exam records.</p>
      </div>

      <div v-else-if="exams.length === 0" class="room-empty-state">
        <FileText :size="42" />
        <h4>No Exams Yet</h4>
        <p>Create an exam and assign it to one or more rooms.</p>
      </div>

      <div v-else class="management-list">
        <article v-for="exam in exams" :key="exam.id" class="management-item">
          <div class="management-item-main">
            <strong>{{ exam.title }}</strong>
            <p>{{ exam.total_items }} items • {{ exam.duration_minutes }} mins</p>
            <div class="management-inline">
              <span class="pill success">{{ exam.question_bank?.subject || exam.subject || 'General' }}</span>
              <span class="pill navy">{{ exam.rooms_count ?? exam.rooms?.length ?? 0 }} room(s)</span>
              <span class="pill neutral">{{ examDeliveryModeLabel(exam.delivery_mode) }}</span>
              <span class="pill" :class="exam.one_take_only ? 'success' : 'neutral'">
                {{ exam.one_take_only ? 'One Take Only' : 'Retake Allowed' }}
              </span>
              <span class="pill" :class="exam.shuffle_questions ? 'navy' : 'neutral'">
                {{ exam.shuffle_questions ? 'Shuffled Items' : 'Fixed Order' }}
              </span>
              <span v-if="exam.creator?.name" class="pill success">By {{ exam.creator.name }}</span>
            </div>
            <p class="muted">Question Set: {{ exam.question_bank?.title || 'Not linked yet' }}</p>
            <p v-if="exam.description" class="muted">{{ exam.description }}</p>
            <p class="muted">
              Schedule: {{ formatExamSchedule(exam.schedule_start_at ?? exam.scheduled_at, exam.schedule_end_at) }}
            </p>
          </div>

          <div class="management-actions">
            <button type="button" class="ghost-btn" @click="openEditExamModal(exam)">
              <Pencil :size="15" />
              Edit
            </button>
            <button type="button" class="danger-btn" @click="openDeleteExamModal(exam)">
              <Trash2 :size="15" />
              Delete
            </button>
          </div>
        </article>
      </div>
    </article>

    <teleport to="body">
      <div v-if="showExamModal" class="modal-backdrop exam-modal-backdrop" @click.self="closeExamModal">
        <div class="modal-card exam-modal-card">
          <header class="modal-head exam-modal-head">
            <h4>{{ examForm.id ? 'Edit Exam' : 'Create Exam' }}</h4>
            <button type="button" class="modal-close" @click="closeExamModal">
              <X :size="16" />
            </button>
          </header>

          <div class="exam-modal-body">
            <label class="field-stack">
              <span class="field-label">Title</span>
              <input v-model.trim="examForm.title" type="text" class="text-input" maxlength="255" />
            </label>

            <div class="inline-form exam-meta-row">
              <label class="field-stack narrow">
                <span class="field-label">Items</span>
                <input v-model.number="examForm.total_items" type="number" min="1" max="1000" class="text-input" />
              </label>
              <label class="field-stack narrow">
                <span class="field-label">Minutes</span>
                <input v-model.number="examForm.duration_minutes" type="number" min="1" max="600" class="text-input" />
              </label>
            </div>

            <label class="field-stack">
              <span class="field-label">Question Set</span>
              <select v-model="examForm.question_bank_id" class="text-input">
                <option :value="null">No question set linked</option>
                <optgroup v-for="group in groupedExamQuestionBanks" :key="group.label" :label="group.label">
                  <option v-for="bank in group.banks" :key="bank.id" :value="bank.id">
                    {{ bank.title }} • {{ bank.total_items }} items
                  </option>
                </optgroup>
              </select>
              <small v-if="examQuestionBanks.length === 0" class="muted">No saved question sets yet. Add one from Library first.</small>
              <small class="muted">Link a question set so students can attempt this exam.</small>
              <div v-if="selectedQuestionBank" class="management-inline exam-question-bank-summary">
                <span class="pill success">{{ selectedQuestionBank.subject || 'General' }}</span>
                <span class="pill neutral">{{ selectedQuestionBank.total_items }} items</span>
                <span class="pill navy">{{ selectedQuestionBank.title }}</span>
              </div>
            </label>

            <div class="field-stack">
              <span class="field-label">Schedule Window (optional)</span>
              <div class="exam-schedule-row">
                <label class="field-stack">
                  <span class="field-label">Start</span>
                  <input
                    v-model="examForm.schedule_start_at"
                    type="datetime-local"
                    class="text-input"
                    :min="createExamScheduleMin || undefined"
                  />
                </label>
                <label class="field-stack">
                  <span class="field-label">End</span>
                  <input
                    v-model="examForm.schedule_end_at"
                    type="datetime-local"
                    class="text-input"
                    :min="createExamScheduleEndMin || undefined"
                  />
                </label>
              </div>
              <small class="muted">Leave both blank for always available exams. If set, students can only take exams inside this window.</small>
            </div>

            <div class="field-stack">
              <span class="field-label">Exam Attempt Options</span>
              <div class="check-grid">
                <label class="check-item">
                  <input v-model="examForm.one_take_only" type="checkbox" />
                  <span>One take only (students cannot retake after submitting)</span>
                </label>
                <label class="check-item">
                  <input v-model="examForm.shuffle_questions" type="checkbox" />
                  <span>Shuffle questions for each attempt</span>
                </label>
              </div>
            </div>

            <label class="field-stack">
              <span class="field-label">Description</span>
              <textarea v-model.trim="examForm.description" class="text-input textarea-input" rows="3" />
            </label>

            <div class="field-stack">
              <span class="field-label">Assign to rooms</span>
              <div v-if="manageableRooms.length === 0" class="muted">No rooms available for assignment.</div>
              <div v-else class="check-grid exam-room-grid">
                <label v-for="room in manageableRooms" :key="room.id" class="check-item">
                  <input v-model="examForm.room_ids" type="checkbox" :value="room.id" />
                  <span>{{ room.name }} <small>({{ room.code }})</small></span>
                </label>
              </div>
            </div>
          </div>

          <div class="modal-actions exam-modal-actions">
            <button type="button" class="ghost-btn" :disabled="examSaving" @click="closeExamModal">Cancel</button>
            <button
              type="button"
              class="primary-btn"
              :disabled="examSaving || !examForm.title.trim() || examForm.total_items < 1 || examForm.duration_minutes < 1"
              @click="handleSaveExam"
            >
              <RefreshCw v-if="examSaving" :size="15" class="spin-soft" />
              <span>{{ examSaving ? 'Saving...' : (examForm.id ? 'Update Exam' : 'Create Exam') }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showDeleteExamModal" class="modal-backdrop" @click.self="closeDeleteExamModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Delete Exam</h4>
            <button type="button" class="modal-close" @click="closeDeleteExamModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            Delete <strong>{{ selectedExam?.title }}</strong>? This also removes its room assignments.
          </p>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="examSaving" @click="closeDeleteExamModal">Cancel</button>
            <button type="button" class="danger-btn" :disabled="examSaving" @click="handleDeleteExam">
              <Trash2 :size="15" />
              Delete
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import { AlertCircle, CheckCircle2, FileText, Pencil, Plus, RefreshCw, Trash2, X } from 'lucide-vue-next'
import { examDeliveryModeLabel, formatExamSchedule } from '../composables/useDashboardFormatters'
import { useExamsModule } from '../composables/useExamsModule'

const {
  exams,
  manageableRooms,
  examQuestionBanks,
  groupedExamQuestionBanks,
  selectedQuestionBank,
  createExamScheduleMin,
  createExamScheduleEndMin,
  examLoading,
  examSaving,
  examError,
  examMessage,
  showExamModal,
  showDeleteExamModal,
  selectedExam,
  examForm,
  openCreateExamModal,
  openEditExamModal,
  closeExamModal,
  openDeleteExamModal,
  closeDeleteExamModal,
  handleSaveExam,
  handleDeleteExam,
} = useExamsModule()
</script>

<style scoped src="../dashboard.css"></style>
