<template>
  <section class="review-bot-view">
    <div v-if="reviewMessage" class="feedback success">
      <CheckCircle2 :size="15" />
      <span>{{ reviewMessage }}</span>
    </div>

    <div v-if="reviewError" class="feedback danger">
      <AlertCircle :size="15" />
      <span>{{ reviewError }}</span>
    </div>

    <article class="surface-card review-bot-head">
      <p class="review-bot-kicker">Review Bot</p>
      <h3>BLIS Core Review Bot</h3>
      <p>Choose subjects and number of items, then generate your quiz.</p>
    </article>

    <div class="review-bot-layout">
      <article class="surface-card review-bot-setup">
        <header class="review-section-head">
          <h3>Setup</h3>
          <span class="pill navy">{{ form.questionCount }} items</span>
        </header>

        <section class="review-field">
          <div class="review-field-head">
            <strong>Subjects</strong>

            <div class="review-field-actions">
              <button type="button" class="ghost-btn review-small-btn" :disabled="reviewLoading" @click="selectAllSubjects">
                All
              </button>
              <button
                type="button"
                class="ghost-btn review-small-btn"
                :disabled="reviewLoading || form.subjects.length === 0"
                @click="clearSelectedSubjects"
              >
                Clear
              </button>
            </div>
          </div>

          <div v-if="reviewLoading" class="review-bot-empty compact">
            <RefreshCw :size="18" class="spin-soft" />
            <p>Loading subjects...</p>
          </div>

          <div v-else-if="subjectOptions.length === 0" class="review-bot-empty compact">
            <BookOpen :size="20" />
            <p>No subjects available.</p>
          </div>

          <div v-else class="review-subject-grid">
            <button
              v-for="subject in subjectOptions"
              :key="subject.subject"
              type="button"
              class="review-subject-chip"
              :class="[
                `tone-${subjectToneKey(subject.subject)}`,
                { 'is-selected': form.subjects.includes(subject.subject) },
              ]"
              @click="toggleSubject(subject.subject)"
            >
              {{ shortSubjectLabel(subject.subject) }}
            </button>
          </div>
        </section>

        <section class="review-field">
          <div class="review-field-head">
            <strong>Items</strong>
          </div>

          <div class="review-count-row">
            <div class="review-count-chips">
              <button
                v-for="count in questionCountOptions"
                :key="count"
                type="button"
                class="review-count-chip"
                :class="{ active: form.questionCount === count }"
                @click="setQuestionCount(count)"
              >
                {{ count }}
              </button>
            </div>

            <label class="field-stack review-count-field">
              <span class="field-label">Custom</span>
              <input v-model.number="form.questionCount" type="number" min="3" max="30" class="text-input" />
            </label>
          </div>
        </section>

        <section class="review-field">
          <div class="review-field-head">
            <strong>Selected</strong>
          </div>

          <div v-if="selectedSubjects.length > 0" class="review-selected-list">
            <span
              v-for="subject in selectedSubjects"
              :key="subject.subject"
              class="review-selected-chip"
              :class="`tone-${subjectToneKey(subject.subject)}`"
            >
              {{ shortSubjectLabel(subject.subject) }}
            </span>
          </div>

          <p v-else class="review-muted">No subjects selected yet.</p>
        </section>

        <div class="review-bot-actions">
          <button
            type="button"
            class="primary-btn"
            :disabled="quizLoading || reviewLoading || !canGenerate"
            @click="generateQuiz"
          >
            <Sparkles :size="16" />
            {{ quizLoading ? 'Generating...' : 'Generate Quiz' }}
          </button>

          <button v-if="hasQuiz" type="button" class="ghost-btn" :disabled="quizLoading" @click="resetQuizState">
            Clear Quiz
          </button>
        </div>
      </article>

      <article class="surface-card review-bot-session">
        <header class="review-section-head">
          <h3>Quiz</h3>

          <div class="review-session-pills">
            <span class="pill neutral">{{ selectedSubjects.length }} subjects</span>
            <span class="pill gold">{{ form.questionCount }} items</span>
            <span v-if="hasQuiz" class="pill navy">{{ answeredCount }}/{{ generatedQuestions.length }} answered</span>
          </div>
        </header>

        <div class="review-session-body">
          <div v-if="quizLoading" class="review-bot-empty">
            <RefreshCw :size="22" class="spin-soft" />
            <h4>Generating quiz</h4>
            <p>Please wait.</p>
          </div>

          <div v-else-if="!hasQuiz" class="review-bot-empty">
            <Sparkles :size="24" />
            <h4>No quiz yet</h4>
            <p>Generate a set to start.</p>
          </div>

          <template v-else>
            <div class="review-session-top">
              <div class="review-session-status">
                <span class="review-session-label">Progress</span>
                <strong>{{ answeredCount }} of {{ generatedQuestions.length }}</strong>
                <span>{{ quizSubmitted ? 'Checked' : 'Submit anytime' }}</span>
              </div>

              <div v-if="quizSubmitted" class="review-bot-results">
                <div class="review-bot-score" :class="scoreToneClass(scorePercent)">
                  <span class="review-score-kicker">Score</span>
                  <strong>{{ scorePercent }}%</strong>
                  <span>{{ correctCount }} / {{ generatedQuestions.length }} correct</span>
                </div>

                <button type="button" class="ghost-btn" :disabled="quizLoading" @click="generateQuiz">
                  <RefreshCw :size="15" />
                  New Quiz
                </button>
              </div>
            </div>

            <div class="review-question-scroll">
              <div class="review-question-list">
                <article v-for="(question, index) in generatedQuestions" :key="question.id" class="review-question-card">
                  <div class="review-question-head">
                    <div>
                      <span class="review-question-number">Question {{ index + 1 }}</span>
                      <span class="review-question-subject" :class="`tone-${subjectToneKey(question.subject)}`">
                        {{ shortSubjectLabel(question.subject) }}
                      </span>
                    </div>

                    <span v-if="quizSubmitted" class="pill" :class="questionResultClass(question)">
                      {{ questionResultLabel(question) }}
                    </span>
                  </div>

                  <p class="review-question-text">{{ question.prompt }}</p>

                  <div class="review-option-list">
                    <label
                      v-for="option in question.options"
                      :key="option.id"
                      class="review-option"
                      :class="{
                        'is-selected': answers[question.id] === option.id,
                        'is-correct': quizSubmitted && option.id === question.correct_option_id,
                        'is-incorrect': quizSubmitted && answers[question.id] === option.id && option.id !== question.correct_option_id,
                      }"
                    >
                      <input
                        :checked="answers[question.id] === option.id"
                        type="radio"
                        :name="`review-question-${question.id}`"
                        :value="option.id"
                        :disabled="quizSubmitted"
                        @change="answerQuestion(question.id, option.id)"
                      />
                      <span>{{ option.text }}</span>
                    </label>
                  </div>

                  <div v-if="quizSubmitted" class="review-explanation">
                    <strong>{{ questionResultLabel(question) }}</strong>
                    <p>{{ question.explanation }}</p>
                  </div>
                </article>
              </div>
            </div>

            <footer class="review-bot-footer">
              <button
                v-if="!quizSubmitted"
                type="button"
                class="primary-btn"
                :disabled="quizLoading"
                @click="submitQuiz"
              >
                Submit Quiz
              </button>

              <button v-else type="button" class="ghost-btn" @click="resetQuizState">
                Start Again
              </button>
            </footer>
          </template>
        </div>
      </article>
    </div>
  </section>
</template>

<script setup>
import { AlertCircle, BookOpen, CheckCircle2, RefreshCw, Sparkles } from 'lucide-vue-next'
import { useReviewBotModule } from '../composables/useReviewBotModule'

const {
  subjectOptions,
  reviewLoading,
  quizLoading,
  reviewError,
  reviewMessage,
  quizSubmitted,
  generatedQuestions,
  answers,
  form,
  questionCountOptions,
  selectedSubjects,
  hasQuiz,
  canGenerate,
  answeredCount,
  correctCount,
  scorePercent,
  setQuestionCount,
  toggleSubject,
  selectAllSubjects,
  clearSelectedSubjects,
  answerQuestion,
  submitQuiz,
  generateQuiz,
  resetQuizState,
} = useReviewBotModule()

const subjectToneLookup = {
  'Cataloging and Classification': 'cataloging',
  'Indexing and Abstracting': 'indexing',
  'Information Technology': 'technology',
  'Reference Services': 'reference',
  'Library Management': 'management',
  'Selection and Acquisition': 'acquisition',
}

const shortSubjectLookup = {
  'Cataloging and Classification': 'Cataloging',
  'Indexing and Abstracting': 'Indexing',
  'Information Technology': 'IT',
  'Reference Services': 'Reference',
  'Library Management': 'Management',
  'Selection and Acquisition': 'Acquisition',
}

function subjectToneKey(subject) {
  return subjectToneLookup[subject] ?? 'cataloging'
}

function shortSubjectLabel(subject) {
  return shortSubjectLookup[subject] ?? subject
}

function scoreToneClass(score) {
  if (score >= 85) return 'strong'
  if (score >= 75) return 'steady'
  return 'focus'
}

function questionResultLabel(question) {
  return answers[question.id] === question.correct_option_id ? 'Correct' : 'Review'
}

function questionResultClass(question) {
  return answers[question.id] === question.correct_option_id ? 'success' : 'gold'
}
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.review-bot-view {
  display: grid;
  gap: 16px;
}

.review-bot-head {
  display: grid;
  gap: 8px;
  background: #f4f7ff;
  border-color: rgba(31, 65, 143, 0.18);
}

.review-bot-kicker {
  margin: 0;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #234a9c;
}

.review-bot-head h3 {
  margin: 0;
  color: var(--lnu-navy-deep);
  font-size: clamp(26px, 4vw, 34px);
  line-height: 1.08;
}

.review-bot-head p {
  margin: 0;
  color: var(--lnu-text-muted);
  line-height: 1.55;
}

.review-bot-layout {
  display: grid;
  grid-template-columns: minmax(320px, 390px) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.review-bot-setup,
.review-bot-session {
  display: grid;
  gap: 14px;
  padding: 18px;
}

.review-bot-setup {
  background: #fffaf0;
}

.review-bot-session {
  background: #fcfdff;
  grid-template-rows: auto minmax(0, 1fr);
  height: min(760px, calc(100vh - 220px));
}

.review-section-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.review-section-head h3 {
  margin: 0;
  color: var(--lnu-navy-deep);
}

.review-field {
  display: grid;
  gap: 10px;
  padding: 14px;
  border-radius: 16px;
  border: 1px solid rgba(13, 21, 71, 0.1);
  background: #ffffff;
}

.review-field-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.review-field-head strong {
  color: var(--lnu-navy-deep);
  font-size: 15px;
}

.review-field-actions {
  display: flex;
  gap: 8px;
}

.review-small-btn {
  height: 34px;
  padding: 0 12px;
}

.review-subject-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.review-subject-chip,
.review-selected-chip {
  border-radius: 14px;
  padding: 12px;
  font-size: 13px;
  font-weight: 700;
  text-align: center;
}

.review-subject-chip {
  border: 1px solid rgba(13, 21, 71, 0.12);
  transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
}

.review-subject-chip:hover {
  transform: translateY(-1px);
}

.review-subject-chip.is-selected {
  border-color: rgba(31, 65, 143, 0.28);
  box-shadow: 0 10px 18px rgba(16, 32, 73, 0.08);
}

.review-count-row {
  display: grid;
  gap: 12px;
}

.review-count-chips,
.review-selected-list,
.review-session-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.review-count-chip {
  border: 1px solid rgba(23, 49, 108, 0.12);
  border-radius: 999px;
  background: #ffffff;
  color: var(--lnu-text);
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 700;
}

.review-count-chip.active,
.review-count-chip:hover {
  border-color: rgba(49, 92, 191, 0.2);
  background: #dfe9ff;
  color: #20428f;
}

.review-count-field {
  width: 110px;
}

.review-muted {
  margin: 0;
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.review-bot-actions,
.review-bot-results,
.review-bot-footer {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.review-bot-results {
  justify-content: space-between;
}

.review-bot-footer {
  justify-content: flex-end;
  padding-top: 8px;
  border-top: 1px solid rgba(13, 21, 71, 0.08);
}

.review-session-body {
  min-height: 0;
  display: grid;
  grid-template-rows: min-content minmax(0, 1fr) auto;
  gap: 12px;
}

.review-session-body > .review-bot-empty {
  grid-row: 1 / -1;
}

.review-session-top {
  display: grid;
  gap: 12px;
}

.review-session-status {
  display: grid;
  gap: 3px;
  padding: 14px 16px;
  border-radius: 16px;
  border: 1px solid rgba(31, 65, 143, 0.12);
  background: #eef3ff;
}

.review-session-label {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(16, 32, 73, 0.58);
}

.review-session-status strong {
  color: var(--lnu-navy-deep);
  font-size: 20px;
  line-height: 1.1;
}

.review-session-status span:last-child {
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.review-bot-empty {
  min-height: 250px;
  height: 100%;
  display: grid;
  place-items: center;
  text-align: center;
  gap: 8px;
  border: 1px dashed rgba(13, 21, 71, 0.18);
  border-radius: 18px;
  background: #f6f8fd;
  padding: 18px;
  color: var(--lnu-text-muted);
}

.review-bot-empty.compact {
  min-height: 110px;
}

.review-bot-empty h4,
.review-bot-empty p {
  margin: 0;
}

.review-bot-score {
  display: grid;
  gap: 4px;
  padding: 16px 18px;
  border-radius: 18px;
  border: 1px solid rgba(13, 21, 71, 0.1);
}

.review-bot-score.strong {
  background: #ddf3e5;
  border-color: rgba(63, 146, 94, 0.2);
}

.review-bot-score.steady {
  background: #dfe9ff;
  border-color: rgba(49, 92, 191, 0.18);
}

.review-bot-score.focus {
  background: #ffefc4;
  border-color: rgba(205, 151, 43, 0.22);
}

.review-score-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: rgba(16, 32, 73, 0.68);
}

.review-bot-score strong {
  font-size: 34px;
  line-height: 1;
  color: var(--lnu-navy-deep);
}

.review-bot-score span {
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.review-question-scroll {
  min-height: 0;
  overflow-y: auto;
  padding-right: 6px;
  scrollbar-gutter: stable;
}

.review-question-list {
  display: grid;
  gap: 14px;
}

.review-question-card {
  display: grid;
  gap: 12px;
  padding: 16px;
  border-radius: 18px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  background: #ffffff;
}

.review-question-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.review-question-number {
  display: block;
  color: var(--lnu-text-muted);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.review-question-subject {
  display: inline-flex;
  margin-top: 8px;
  border-radius: 999px;
  padding: 6px 10px;
  font-size: 12px;
  font-weight: 700;
}

.review-question-text {
  margin: 0;
  color: var(--lnu-text);
  font-size: 17px;
  line-height: 1.55;
}

.review-option-list {
  display: grid;
  gap: 8px;
}

.review-option {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 11px 12px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 13px;
  background: rgba(255, 255, 255, 0.92);
  color: var(--lnu-text);
}

.review-option.is-selected {
  border-color: rgba(31, 65, 143, 0.24);
  background: #ebf1ff;
}

.review-option.is-correct {
  border-color: rgba(63, 146, 94, 0.24);
  background: #eef9f1;
}

.review-option.is-incorrect {
  border-color: rgba(227, 105, 105, 0.24);
  background: #fff0f0;
}

.review-option input {
  margin-top: 2px;
  accent-color: var(--lnu-navy);
}

.review-explanation {
  display: grid;
  gap: 4px;
  padding: 12px 14px;
  border-radius: 14px;
  background: #f6f8fd;
  border: 1px solid rgba(13, 21, 71, 0.1);
}

.review-explanation strong,
.review-explanation p {
  margin: 0;
}

.review-explanation p {
  color: var(--lnu-text-muted);
  font-size: 14px;
  line-height: 1.5;
}

.tone-cataloging {
  background: #e4edff;
  color: #234a9c;
}

.tone-indexing {
  background: #def3f6;
  color: #1c7286;
}

.tone-technology {
  background: #fff0ca;
  color: #966112;
}

.tone-reference {
  background: #ffe6e1;
  color: #ad5340;
}

.tone-management {
  background: #e2f4e7;
  color: #267242;
}

.tone-acquisition {
  background: #f7ead8;
  color: #83551e;
}

@media (max-width: 1100px) {
  .review-bot-layout {
    grid-template-columns: 1fr;
  }

  .review-bot-session {
    height: auto;
  }

  .review-session-body {
    grid-template-rows: auto;
  }

  .review-question-scroll {
    overflow: visible;
    padding-right: 0;
  }
}

@media (max-width: 720px) {
  .review-bot-head,
  .review-bot-setup,
  .review-bot-session,
  .review-question-card {
    padding: 14px;
  }

  .review-section-head,
  .review-field-head,
  .review-question-head {
    flex-direction: column;
    align-items: flex-start;
  }

  .review-subject-grid {
    grid-template-columns: 1fr;
  }

  .review-bot-actions,
  .review-bot-footer {
    width: 100%;
  }

  .review-bot-actions .primary-btn,
  .review-bot-actions .ghost-btn,
  .review-bot-footer .primary-btn,
  .review-bot-footer .ghost-btn {
    width: 100%;
    justify-content: center;
  }
}
</style>
