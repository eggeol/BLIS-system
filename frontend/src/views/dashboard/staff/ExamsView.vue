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
            <p>{{ exam.total_items }} items | {{ exam.duration_minutes }} mins</p>
            <div class="management-inline">
              <span class="pill success">{{ examQuestionBankSubject(exam) }}</span>
              <span class="pill neutral">{{ examQuestionBankCount(exam) }} question set(s)</span>
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
            <p class="muted">Question Sets: {{ examQuestionBankSummaryText(exam) }}</p>
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
            <div class="exam-modal-title-wrap">
              <div class="management-inline exam-modal-top-pills">
                <span class="pill success">{{ examForm.id ? 'Edit mode' : 'New exam' }}</span>
                <span class="pill neutral">{{ examQuestionBanks.length }} saved question set(s)</span>
              </div>
              <h4>{{ examForm.id ? 'Update Exam Setup' : 'Create Exam Setup' }}</h4>
              <p>Build the exam in sections, then review the live summary before saving.</p>
            </div>
            <button type="button" class="modal-close" @click="closeExamModal">
              <X :size="16" />
            </button>
          </header>

          <div class="exam-modal-body">
            <div class="exam-modal-layout">
              <div class="exam-modal-main">
                <section class="exam-modal-panel">
                  <div class="exam-panel-head">
                    <span class="exam-panel-kicker">Section 1</span>
                    <h5>Core Details</h5>
                    <p>Give the exam a clear identity and set the base workload.</p>
                  </div>

                  <div class="exam-panel-grid single">
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
                      <span class="field-label">Description</span>
                      <textarea v-model.trim="examForm.description" class="text-input textarea-input" rows="4" />
                    </label>
                  </div>
                </section>

                <section class="exam-modal-panel">
                  <div class="exam-panel-head">
                    <span class="exam-panel-kicker">Section 2</span>
                    <h5>Question Set Mix</h5>
                    <p>Choose one or more sources. Students will receive items from the combined pool.</p>
                  </div>

                  <div class="exam-selection-note">
                    <span class="pill success">{{ selectedQuestionBankSubject || 'General' }}</span>
                    <span class="pill neutral">{{ selectedQuestionBankTotalItems }} combined items</span>
                    <span class="pill navy">{{ selectedQuestionBanks.length }} selected</span>
                  </div>

                  <p v-if="examQuestionBanks.length === 0" class="muted">No saved question sets yet. Add one from Library first.</p>
                  <div
                    v-else
                    ref="questionSetDropdownRef"
                    class="exam-bank-dropdown"
                    @keydown.escape.stop.prevent="closeQuestionSetDropdown"
                  >
                    <button
                      type="button"
                      class="exam-bank-trigger"
                      :class="{ 'is-open': questionSetDropdownOpen }"
                      :aria-expanded="questionSetDropdownOpen ? 'true' : 'false'"
                      @click="toggleQuestionSetDropdown"
                    >
                      <span class="exam-bank-trigger-copy">
                        <span class="exam-bank-trigger-label">Question set selector</span>
                        <strong>{{ questionSetDropdownTitle }}</strong>
                        <small>{{ questionSetDropdownSubtitle }}</small>
                      </span>
                      <span class="exam-bank-trigger-side">
                        <span class="exam-bank-trigger-count">{{ selectedQuestionBanks.length }}</span>
                        <ChevronDown :size="18" class="exam-bank-trigger-icon" :class="{ 'is-open': questionSetDropdownOpen }" />
                      </span>
                    </button>

                    <div v-if="questionSetDropdownOpen" class="exam-bank-menu">
                      <div class="exam-bank-menu-head">
                        <div class="exam-bank-menu-copy">
                          <strong>Select question sets</strong>
                          <small>Check one or more sources to build the exam pool.</small>
                        </div>
                        <button
                          v-if="selectedQuestionBanks.length > 0"
                          type="button"
                          class="exam-bank-clear-btn"
                          @click="clearSelectedQuestionBanks"
                        >
                          Clear
                        </button>
                      </div>

                      <div class="exam-bank-menu-scroll">
                        <div class="exam-bank-groups">
                          <section v-for="group in groupedExamQuestionBanks" :key="group.label" class="exam-bank-group">
                            <strong class="exam-bank-group-title">{{ group.label }}</strong>
                            <div class="exam-bank-grid">
                              <label
                                v-for="bank in group.banks"
                                :key="bank.id"
                                class="check-item exam-bank-choice"
                                :class="{ 'is-selected': examForm.question_bank_ids.includes(bank.id) }"
                              >
                                <input v-model="examForm.question_bank_ids" type="checkbox" :value="bank.id" />
                                <span class="exam-bank-copy">
                                  <strong>{{ bank.title }}</strong>
                                  <small>{{ bank.total_items }} items</small>
                                </span>
                              </label>
                            </div>
                          </section>
                        </div>
                      </div>

                      <div class="exam-bank-menu-foot">
                        <span>{{ selectedQuestionBanks.length }} selected</span>
                        <span>{{ selectedQuestionBankTotalItems }} combined items</span>
                      </div>
                    </div>
                  </div>
                </section>

                <section class="exam-modal-panel">
                  <div class="exam-panel-head">
                    <span class="exam-panel-kicker">Section 3</span>
                    <h5>Availability And Rules</h5>
                    <p>Control when the exam opens and how many times students can take it.</p>
                  </div>

                  <div class="exam-panel-grid">
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
                      <small class="muted">Leave both blank for always-available exams.</small>
                    </div>

                    <div class="field-stack">
                      <span class="field-label">Exam Attempt Options</span>
                      <div class="exam-policy-list">
                        <label class="check-item exam-policy-item">
                          <input v-model="examForm.one_take_only" type="checkbox" />
                          <span>
                            <strong>One take only</strong>
                            <small>Students cannot retake after submitting.</small>
                          </span>
                        </label>
                        <label class="check-item exam-policy-item">
                          <input v-model="examForm.shuffle_questions" type="checkbox" />
                          <span>
                            <strong>Shuffle questions</strong>
                            <small>Each attempt gets a randomized order when possible.</small>
                          </span>
                        </label>
                      </div>
                    </div>
                  </div>
                </section>

                <section class="exam-modal-panel">
                  <div class="exam-panel-head">
                    <span class="exam-panel-kicker">Section 4</span>
                    <h5>Room Assignment</h5>
                    <p>Pick the rooms that should immediately receive this exam.</p>
                  </div>

                  <div v-if="manageableRooms.length === 0" class="muted">No rooms available for assignment.</div>
                  <div v-else class="check-grid exam-room-grid">
                    <label v-for="room in manageableRooms" :key="room.id" class="check-item exam-room-choice">
                      <input v-model="examForm.room_ids" type="checkbox" :value="room.id" />
                      <span class="exam-room-copy">
                        <strong>{{ room.name }}</strong>
                        <small>{{ room.code }}</small>
                      </span>
                    </label>
                  </div>
                </section>
              </div>

              <aside class="exam-modal-side">
                <section class="exam-modal-summary">
                  <div class="exam-summary-hero">
                    <span class="exam-summary-kicker">Live Preview</span>
                    <strong>{{ examForm.title.trim() || 'Untitled exam' }}</strong>
                    <p>
                      {{ examForm.description.trim() || 'Add a short description so the purpose of the exam is easy to recognize.' }}
                    </p>
                  </div>

                  <div class="exam-summary-stats">
                    <article class="exam-summary-stat">
                      <span>Items</span>
                      <strong>{{ examForm.total_items || 0 }}</strong>
                    </article>
                    <article class="exam-summary-stat">
                      <span>Minutes</span>
                      <strong>{{ examForm.duration_minutes || 0 }}</strong>
                    </article>
                    <article class="exam-summary-stat">
                      <span>Sets</span>
                      <strong>{{ selectedQuestionBanks.length }}</strong>
                    </article>
                    <article class="exam-summary-stat">
                      <span>Rooms</span>
                      <strong>{{ examForm.room_ids.length }}</strong>
                    </article>
                  </div>

                  <div class="exam-summary-block">
                    <span class="exam-summary-label">Question Set Coverage</span>
                    <p>
                      <strong>{{ selectedQuestionBankTotalItems }}</strong> combined source items available
                    </p>
                    <small v-if="selectedQuestionBanks.length === 0">No question sets selected yet.</small>
                    <small v-else-if="selectedQuestionBankTotalItems < examForm.total_items">
                      Current selection is smaller than the requested item count.
                    </small>
                    <small v-else>
                      Selection is large enough for the requested item count.
                    </small>
                  </div>

                  <div class="exam-summary-block">
                    <span class="exam-summary-label">Schedule</span>
                    <p>{{ formatExamSchedule(examForm.schedule_start_at, examForm.schedule_end_at) }}</p>
                    <small>
                      {{
                        examForm.one_take_only
                          ? 'Students get one submission only.'
                          : 'Students can submit once and retake one more time.'
                      }}
                    </small>
                  </div>

                  <div class="exam-summary-block">
                    <span class="exam-summary-label">Selected Question Sets</span>
                    <div v-if="selectedQuestionBanks.length === 0" class="exam-summary-empty">
                      No question sets selected yet.
                    </div>
                    <div v-else class="exam-summary-stack">
                      <article v-for="bank in selectedQuestionBanks" :key="bank.id" class="exam-summary-chip">
                        <strong>{{ bank.title }}</strong>
                        <small>{{ bank.subject || 'General' }} | {{ bank.total_items }} items</small>
                      </article>
                    </div>
                  </div>

                  <div class="exam-summary-block">
                    <span class="exam-summary-label">Assigned Rooms</span>
                    <div v-if="examForm.room_ids.length === 0" class="exam-summary-empty">
                      No rooms selected yet.
                    </div>
                    <div v-else class="exam-summary-stack compact">
                      <article
                        v-for="room in manageableRooms.filter((room) => examForm.room_ids.includes(room.id))"
                        :key="room.id"
                        class="exam-summary-chip compact"
                      >
                        <strong>{{ room.name }}</strong>
                        <small>{{ room.code }}</small>
                      </article>
                    </div>
                  </div>
                </section>
              </aside>
            </div>
          </div>

          <div class="modal-actions exam-modal-actions">
            <p class="exam-modal-action-note">
              Choose enough question sets to cover the requested item count before saving.
            </p>
            <div class="exam-modal-action-buttons">
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
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  AlertCircle,
  CheckCircle2,
  ChevronDown,
  FileText,
  Pencil,
  Plus,
  RefreshCw,
  Trash2,
  X,
} from 'lucide-vue-next'
import { examDeliveryModeLabel, formatExamSchedule } from '../composables/useDashboardFormatters'
import { useExamsModule } from '../composables/useExamsModule'

const {
  exams,
  manageableRooms,
  examQuestionBanks,
  groupedExamQuestionBanks,
  selectedQuestionBanks,
  selectedQuestionBankSubject,
  selectedQuestionBankTotalItems,
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
  examQuestionBankCount,
  examQuestionBankSubject,
  examQuestionBankSummaryText,
  openCreateExamModal,
  openEditExamModal,
  closeExamModal,
  openDeleteExamModal,
  closeDeleteExamModal,
  handleSaveExam,
  handleDeleteExam,
} = useExamsModule()

const questionSetDropdownRef = ref(null)
const questionSetDropdownOpen = ref(false)

const questionSetDropdownTitle = computed(() => {
  if (selectedQuestionBanks.value.length === 0) {
    return 'Choose question sets'
  }

  if (selectedQuestionBanks.value.length === 1) {
    return selectedQuestionBanks.value[0].title
  }

  return `${selectedQuestionBanks.value.length} question sets selected`
})

const questionSetDropdownSubtitle = computed(() => {
  if (selectedQuestionBanks.value.length === 0) {
    return 'Open this dropdown and tick one or more question sets.'
  }

  const previewNames = selectedQuestionBanks.value
    .slice(0, 2)
    .map((bank) => bank.title)
    .join(' | ')

  const remainingCount = selectedQuestionBanks.value.length - Math.min(selectedQuestionBanks.value.length, 2)
  const combinedItemsText = `${selectedQuestionBankTotalItems.value} combined items`

  if (remainingCount > 0) {
    return `${previewNames} | +${remainingCount} more | ${combinedItemsText}`
  }

  return `${previewNames} | ${combinedItemsText}`
})

const toggleQuestionSetDropdown = () => {
  questionSetDropdownOpen.value = !questionSetDropdownOpen.value
}

const closeQuestionSetDropdown = () => {
  questionSetDropdownOpen.value = false
}

const clearSelectedQuestionBanks = () => {
  examForm.question_bank_ids = []
}

const handleQuestionSetDropdownPointerDown = (event) => {
  if (!questionSetDropdownOpen.value) {
    return
  }

  if (
    questionSetDropdownRef.value &&
    event.target instanceof Node &&
    questionSetDropdownRef.value.contains(event.target)
  ) {
    return
  }

  closeQuestionSetDropdown()
}

const handleQuestionSetDropdownKeydown = (event) => {
  if (event.key === 'Escape') {
    closeQuestionSetDropdown()
  }
}

watch(showExamModal, (isOpen) => {
  if (!isOpen) {
    closeQuestionSetDropdown()
  }
})

onMounted(() => {
  document.addEventListener('pointerdown', handleQuestionSetDropdownPointerDown)
  document.addEventListener('keydown', handleQuestionSetDropdownKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', handleQuestionSetDropdownPointerDown)
  document.removeEventListener('keydown', handleQuestionSetDropdownKeydown)
})
</script>

<style scoped src="../dashboard.css"></style>
