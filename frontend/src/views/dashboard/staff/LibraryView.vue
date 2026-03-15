<template>
  <section class="library-view">
    <div v-if="libraryMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ libraryMessage }}</span>
    </div>
    <div v-if="libraryError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ libraryError }}</span>
    </div>

    <article class="surface-card room-shell-card">
      <header class="room-page-head">
        <div class="room-page-title">
          <BookOpen :size="18" />
          <h3>Library</h3>
        </div>
        <button type="button" class="primary-btn add-room-btn" @click="openLibraryQuestionModal">
          <Plus :size="16" />
          Add Questions
        </button>
      </header>

      <div v-if="libraryLoading && libraryQuestionBanks.length === 0" class="room-empty-state">
        <RefreshCw :size="34" class="spin-soft" />
        <h4>Loading question banks</h4>
        <p>Please wait while we fetch your library entries.</p>
      </div>

      <div v-else-if="libraryQuestionBanks.length === 0" class="room-empty-state">
        <BookOpen :size="42" />
        <h4>No Question Banks Yet</h4>
        <p>Upload a DOCX to convert and save your question set in the library.</p>
      </div>

      <div v-else class="management-list">
        <article v-for="bank in libraryQuestionBanks" :key="bank.id" class="management-item">
          <div class="management-item-main">
            <strong>{{ bank.title }}</strong>
            <p>{{ bank.subject || 'General' }} • {{ bank.total_items }} items</p>
            <div class="management-inline">
              <span class="pill neutral">{{ bank.questions_count ?? bank.total_items }} question(s)</span>
              <span v-if="bank.creator?.name" class="pill navy">By {{ bank.creator.name }}</span>
              <span v-if="bank.source_filename" class="pill success">{{ bank.source_filename }}</span>
            </div>
            <p class="muted">Updated {{ formatDateTime(bank.updated_at) }}</p>
          </div>

          <div class="management-actions">
            <button
              type="button"
              class="danger-btn"
              :disabled="libraryDeleting"
              @click="openDeleteLibraryBankModal(bank)"
            >
              <Trash2 :size="15" />
              Delete
            </button>
          </div>
        </article>
      </div>
    </article>

    <teleport to="body">
      <div v-if="showLibraryQuestionModal" class="modal-backdrop" @click.self="closeLibraryQuestionModal">
        <div class="modal-card library-modal-card">
          <header class="modal-head library-modal-head">
            <div class="library-modal-title-wrap">
              <h4>Add Questions</h4>
              <p>Upload your DOCX and review digitalized questions before saving.</p>
            </div>
            <button type="button" class="modal-close" @click="closeLibraryQuestionModal">
              <X :size="16" />
            </button>
          </header>

          <div class="library-modal-body">
            <section class="library-form-panel">
              <label class="field-stack">
                <span class="field-label">Question Name</span>
                <input
                  v-model.trim="libraryForm.questionName"
                  type="text"
                  class="text-input"
                  maxlength="255"
                  placeholder="e.g. Mock Exam - Library Science Set A"
                />
              </label>

              <label class="field-stack">
                <span class="field-label">Subject Category</span>
                <select v-model="libraryForm.subjectCategory" class="text-input">
                  <option disabled value="">Select subject category</option>
                  <option v-for="subject in librarySubjectCategories" :key="subject" :value="subject">
                    {{ subject }}
                  </option>
                </select>
              </label>

              <label class="field-stack">
                <span class="field-label">Upload a DOCX file (Questions)</span>
                <div class="library-upload-panel">
                  <input
                    :key="libraryFileInputKey"
                    type="file"
                    accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    class="file-input"
                    :disabled="libraryParsing"
                    @change="handleLibraryDocxChange"
                  />
                  <p class="library-upload-note">
                    Use numbered questions with optional choices like <code>A.</code>, <code>B.</code>, <code>C.</code>.
                  </p>
                </div>
              </label>

              <p v-if="libraryDocxName" class="library-file-chip">{{ libraryDocxName }}</p>

              <div v-if="libraryParsing" class="feedback info">
                <RefreshCw :size="15" class="spin-soft" />
                <span>Converting DOCX to digitalized questions...</span>
              </div>

              <div v-if="libraryParseError" class="feedback danger">
                <AlertCircle :size="15" />
                <span>{{ libraryParseError }}</span>
              </div>

              <div v-if="groupedLibraryPreviewWarnings.length > 0" class="library-warning-list">
                <p class="warning-list-title">Parser Notes</p>
                <ul>
                  <li v-for="(warning, warningIndex) in groupedLibraryPreviewWarnings" :key="`warning-${warningIndex}`">
                    {{ warning }}
                  </li>
                </ul>
              </div>
            </section>

            <section class="digitalized-preview">
              <header class="digitalized-head">
                <h5>Digitalized Questions</h5>
                <span class="pill neutral">{{ digitalizedQuestions.length }} items</span>
              </header>

              <div v-if="digitalizedQuestions.length === 0" class="digitalized-empty">
                <FileText :size="30" />
                <p>Converted questions will appear here after DOCX upload.</p>
              </div>

              <div v-else class="digitalized-list">
                <article
                  v-for="(question, index) in digitalizedQuestions"
                  :key="`${question.id}-${index}`"
                  class="digitalized-card"
                >
                  <p class="digitalized-question">{{ index + 1 }}. {{ question.text }}</p>

                  <div v-if="question.options.length > 0" class="digitalized-options">
                    <label
                      v-for="(option, optionIndex) in question.options"
                      :key="optionIndex"
                      class="digitalized-option"
                      :class="{ correct: option.is_correct }"
                    >
                      <input type="radio" :name="`preview-question-${index}`" :checked="option.is_correct" disabled />
                      <span>{{ option.label }}. {{ option.text }}</span>
                    </label>
                  </div>

                  <p v-else class="digitalized-open-ended">Open-ended response</p>

                  <p v-if="question.answer_label" class="digitalized-answer">
                    Answer: {{ question.answer_label }}<span v-if="question.answer_text">. {{ question.answer_text }}</span>
                  </p>
                </article>
              </div>
            </section>
          </div>

          <div class="modal-actions library-modal-actions">
            <button type="button" class="ghost-btn" :disabled="librarySaving" @click="closeLibraryQuestionModal">Close</button>
            <button
              type="button"
              class="primary-btn"
              :disabled="!canSaveLibraryQuestionBank"
              @click="handleSaveLibraryQuestionBank"
            >
              <RefreshCw v-if="librarySaving" :size="16" class="spin-soft" />
              <Plus v-else :size="16" />
              {{ librarySaving ? 'Saving...' : 'Save Question Set' }}
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <teleport to="body">
      <div v-if="showDeleteLibraryBankModal" class="modal-backdrop" @click.self="closeDeleteLibraryBankModal">
        <div class="modal-card">
          <header class="modal-head">
            <h4>Delete Question Set</h4>
            <button type="button" class="modal-close" @click="closeDeleteLibraryBankModal">
              <X :size="16" />
            </button>
          </header>

          <p class="muted">
            Delete <strong>{{ selectedLibraryBank?.title }}</strong>? This will also unlink it from exams that reference it.
          </p>
          <p class="muted">
            This action is blocked if the set already has exam attempt records.
          </p>

          <div class="modal-actions">
            <button type="button" class="ghost-btn" :disabled="libraryDeleting" @click="closeDeleteLibraryBankModal">
              Cancel
            </button>
            <button type="button" class="danger-btn" :disabled="libraryDeleting" @click="handleDeleteLibraryBank">
              <RefreshCw v-if="libraryDeleting" :size="14" class="spin-soft" />
              <Trash2 v-else :size="14" />
              <span>{{ libraryDeleting ? 'Deleting...' : 'Delete Question Set' }}</span>
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </section>
</template>

<script setup>
import { AlertCircle, BookOpen, CheckCircle2, FileText, Plus, RefreshCw, Trash2, X } from 'lucide-vue-next'
import { formatDateTime } from '../composables/useDashboardFormatters'
import { useLibraryModule } from '../composables/useLibraryModule'

const {
  showLibraryQuestionModal,
  showDeleteLibraryBankModal,
  libraryLoading,
  librarySaving,
  libraryDeleting,
  libraryError,
  libraryMessage,
  libraryParsing,
  libraryParseError,
  libraryDocxName,
  libraryFileInputKey,
  libraryQuestionBanks,
  selectedLibraryBank,
  groupedLibraryPreviewWarnings,
  digitalizedQuestions,
  libraryForm,
  librarySubjectCategories,
  canSaveLibraryQuestionBank,
  openLibraryQuestionModal,
  closeLibraryQuestionModal,
  openDeleteLibraryBankModal,
  closeDeleteLibraryBankModal,
  handleLibraryDocxChange,
  handleSaveLibraryQuestionBank,
  handleDeleteLibraryBank,
} = useLibraryModule()
</script>

<style scoped src="../dashboard.css"></style>
