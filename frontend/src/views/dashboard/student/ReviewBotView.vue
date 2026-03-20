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

    <article class="surface-card review-bot-hero">
      <div class="review-bot-hero-copy">
        <span class="review-bot-kicker">Review Bot</span>
        <h3>Generate a fresh practice set from your teacher library</h3>
        <p>
          Pick your subjects, choose how many items you want, and Review Bot will build a rewritten quiz
          so you can practice the same ideas without seeing the exact exam wording.
        </p>
      </div>

      <div class="review-bot-hero-meta">
        <span class="pill success">Student Practice</span>
        <span class="pill neutral">{{ subjectOptions.length }} subjects ready</span>
      </div>
    </article>

    <div class="review-bot-layout">
      <article class="surface-card review-bot-setup">
        <header class="surface-head">
          <h3>Build My Review Set</h3>
          <span class="pill navy">{{ form.questionCount }} questions</span>
        </header>

        <section class="review-bot-section">
          <label class="field-stack">
            <span class="field-label">How many questions do you want to answer?</span>
            <input v-model.number="form.questionCount" type="number" min="3" max="30" class="text-input" />
            <small class="muted">Choose between 3 and 30 questions.</small>
          </label>

          <div class="review-count-chips">
            <button
              v-for="count in questionCountOptions"
              :key="count"
              type="button"
              class="review-count-chip"
              :class="{ active: form.questionCount === count }"
              @click="setQuestionCount(count)"
            >
              {{ count }} items
            </button>
          </div>
        </section>

        <section class="review-bot-section">
          <div class="review-bot-section-head">
            <div>
              <h4>Subjects to review</h4>
              <p>Choose one or more subject areas from the teacher question library.</p>
            </div>
            <span class="pill neutral">{{ form.subjects.length }} selected</span>
          </div>

          <div v-if="reviewLoading" class="review-bot-empty compact">
            <RefreshCw :size="18" class="spin-soft" />
            <p>Loading subjects from the teacher library...</p>
          </div>

          <div v-else-if="subjectOptions.length === 0" class="review-bot-empty compact">
            <BookOpen :size="22" />
            <p>No teacher question banks are available yet.</p>
          </div>

          <div v-else class="review-subject-grid">
            <label
              v-for="subject in subjectOptions"
              :key="subject.subject"
              class="review-subject-chip"
              :class="{ 'is-selected': form.subjects.includes(subject.subject) }"
            >
              <input v-model="form.subjects" type="checkbox" :value="subject.subject" />
              <span class="review-subject-copy">
                <strong>{{ subject.subject }}</strong>
                <small>{{ subject.question_count }} questions across {{ subject.bank_count }} bank(s)</small>
              </span>
            </label>
          </div>
        </section>

        <div class="review-bot-actions">
          <button
            type="button"
            class="primary-btn"
            :disabled="quizLoading || reviewLoading || !canGenerate"
            @click="generateQuiz"
          >
            <Sparkles :size="16" />
            {{ quizLoading ? 'Generating...' : 'Generate Review Set' }}
          </button>
          <button v-if="hasQuiz" type="button" class="ghost-btn" :disabled="quizLoading" @click="resetQuizState">
            Clear Set
          </button>
        </div>
      </article>

      <article class="surface-card review-bot-session">
        <header class="surface-head">
          <h3>{{ hasQuiz ? 'Practice Set' : 'Generated Quiz' }}</h3>
          <div class="management-inline">
            <span v-if="hasQuiz" class="pill success">{{ generatorLabel }}</span>
            <span v-if="hasQuiz" class="pill neutral">{{ answeredCount }}/{{ generatedQuestions.length }} answered</span>
          </div>
        </header>

        <div v-if="quizLoading" class="review-bot-empty">
          <RefreshCw :size="22" class="spin-soft" />
          <h4>Building your review set</h4>
          <p>Review Bot is remixing teacher questions into a fresh practice quiz.</p>
        </div>

        <div v-else-if="!hasQuiz" class="review-bot-empty">
          <Sparkles :size="26" />
          <h4>Your quiz will appear here</h4>
          <p>Select subjects, choose a question count, and generate a review set to begin.</p>
        </div>

        <template v-else>
          <div v-if="quizSubmitted" class="review-bot-results">
            <div class="review-bot-score" :class="{ strong: scorePercent >= 75, focus: scorePercent < 75 }">
              <strong>{{ scorePercent }}%</strong>
              <span>{{ correctCount }} / {{ generatedQuestions.length }} correct</span>
            </div>

            <button type="button" class="ghost-btn" :disabled="quizLoading" @click="generateQuiz">
              <RefreshCw :size="15" />
              Generate Another Set
            </button>
          </div>

          <div class="review-question-list">
            <article v-for="(question, index) in generatedQuestions" :key="question.id" class="review-question-card">
              <div class="review-question-head">
                <span class="review-question-number">Question {{ index + 1 }}</span>
                <span class="pill neutral">{{ question.subject }}</span>
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
                <strong>{{ answers[question.id] === question.correct_option_id ? 'Correct' : 'Needs review' }}</strong>
                <p>{{ question.explanation }}</p>
              </div>
            </article>
          </div>

          <footer class="review-bot-footer">
            <p>
              {{
                quizSubmitted
                  ? 'Use the explanations to review the concepts you missed before generating another set.'
                  : 'Answer every question, then submit to reveal your score and explanations.'
              }}
            </p>

            <button
              v-if="!quizSubmitted"
              type="button"
              class="primary-btn"
              :disabled="quizLoading || !allAnswered"
              @click="submitQuiz"
            >
              Submit Review Set
            </button>
            <button v-else type="button" class="ghost-btn" @click="resetQuizState">
              Start Fresh
            </button>
          </footer>
        </template>
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
  generatorLabel,
  quizSubmitted,
  generatedQuestions,
  answers,
  form,
  questionCountOptions,
  hasQuiz,
  canGenerate,
  answeredCount,
  allAnswered,
  correctCount,
  scorePercent,
  setQuestionCount,
  answerQuestion,
  submitQuiz,
  generateQuiz,
  resetQuizState,
} = useReviewBotModule()
</script>

<style scoped src="../dashboard.css"></style>

<style scoped>
.review-bot-view {
  display: grid;
  gap: 16px;
}

.review-bot-hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 22px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  background:
    radial-gradient(circle at top right, rgba(201, 168, 76, 0.24), transparent 28%),
    linear-gradient(135deg, rgba(26, 35, 126, 0.08), rgba(255, 255, 255, 0.98) 62%);
}

.review-bot-hero-copy {
  display: grid;
  gap: 8px;
  max-width: 64ch;
}

.review-bot-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--lnu-navy);
}

.review-bot-hero-copy h3 {
  margin: 0;
  font-size: clamp(28px, 4vw, 38px);
  line-height: 1.05;
  color: var(--lnu-navy-deep);
}

.review-bot-hero-copy p {
  margin: 0;
  color: var(--lnu-text-muted);
  font-size: 15px;
  line-height: 1.6;
}

.review-bot-hero-meta {
  display: grid;
  gap: 8px;
  justify-items: end;
}

.review-bot-layout {
  display: grid;
  grid-template-columns: minmax(320px, 380px) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.review-bot-setup,
.review-bot-session {
  border: 1px solid rgba(13, 21, 71, 0.12);
}

.review-bot-setup {
  padding: 18px;
  display: grid;
  gap: 18px;
}

.review-bot-session {
  padding: 18px;
  display: grid;
  gap: 18px;
}

.review-bot-section {
  display: grid;
  gap: 12px;
}

.review-bot-section-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.review-bot-section-head h4 {
  margin: 0;
  font-size: 16px;
  color: var(--lnu-navy-deep);
}

.review-bot-section-head p {
  margin: 4px 0 0;
  color: var(--lnu-text-muted);
  font-size: 13px;
  line-height: 1.45;
}

.review-count-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.review-count-chip {
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.92);
  color: var(--lnu-text);
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 700;
}

.review-count-chip.active,
.review-count-chip:hover {
  border-color: rgba(26, 35, 126, 0.24);
  background: rgba(26, 35, 126, 0.08);
  color: var(--lnu-navy-deep);
}

.review-subject-grid {
  display: grid;
  gap: 10px;
}

.review-subject-chip {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.94);
  transition: border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
}

.review-subject-chip:hover {
  border-color: rgba(26, 35, 126, 0.22);
  background: rgba(255, 255, 255, 0.98);
}

.review-subject-chip.is-selected {
  border-color: rgba(26, 35, 126, 0.28);
  background: rgba(26, 35, 126, 0.06);
  box-shadow: inset 0 0 0 1px rgba(26, 35, 126, 0.04);
}

.review-subject-chip input {
  margin-top: 2px;
  accent-color: var(--lnu-navy);
}

.review-subject-copy {
  display: grid;
  gap: 4px;
}

.review-subject-copy strong {
  color: var(--lnu-text);
  font-size: 14px;
}

.review-subject-copy small {
  color: var(--lnu-text-muted);
  font-size: 12px;
  line-height: 1.4;
}

.review-bot-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.review-bot-empty {
  min-height: 240px;
  display: grid;
  place-items: center;
  text-align: center;
  gap: 8px;
  border: 1px dashed rgba(13, 21, 71, 0.18);
  border-radius: 16px;
  background: rgba(248, 249, 252, 0.9);
  padding: 18px;
  color: var(--lnu-text-muted);
}

.review-bot-empty.compact {
  min-height: 120px;
}

.review-bot-empty h4,
.review-bot-empty p {
  margin: 0;
}

.review-bot-results {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.review-bot-score {
  display: grid;
  gap: 4px;
  padding: 14px 16px;
  border-radius: 16px;
  background: rgba(247, 248, 252, 0.94);
  border: 1px solid rgba(13, 21, 71, 0.1);
}

.review-bot-score.strong {
  background: rgba(67, 160, 71, 0.12);
  border-color: rgba(67, 160, 71, 0.22);
}

.review-bot-score.focus {
  background: rgba(201, 168, 76, 0.16);
  border-color: rgba(201, 168, 76, 0.28);
}

.review-bot-score strong {
  font-size: 32px;
  line-height: 1;
  color: var(--lnu-navy-deep);
}

.review-bot-score span {
  color: var(--lnu-text-muted);
  font-size: 13px;
}

.review-question-list {
  display: grid;
  gap: 14px;
}

.review-question-card {
  display: grid;
  gap: 12px;
  padding: 16px;
  border-radius: 16px;
  border: 1px solid rgba(13, 21, 71, 0.12);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(249, 248, 244, 0.94));
}

.review-question-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.review-question-number {
  color: var(--lnu-text-muted);
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
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
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.92);
  color: var(--lnu-text);
}

.review-option.is-selected {
  border-color: rgba(26, 35, 126, 0.28);
  background: rgba(26, 35, 126, 0.06);
}

.review-option.is-correct {
  border-color: rgba(67, 160, 71, 0.24);
  background: rgba(67, 160, 71, 0.12);
}

.review-option.is-incorrect {
  border-color: rgba(229, 115, 115, 0.24);
  background: rgba(229, 115, 115, 0.12);
}

.review-option input {
  margin-top: 2px;
  accent-color: var(--lnu-navy);
}

.review-explanation {
  display: grid;
  gap: 4px;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(248, 249, 252, 0.92);
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

.review-bot-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  border-top: 1px solid rgba(13, 21, 71, 0.1);
  padding-top: 16px;
}

.review-bot-footer p {
  margin: 0;
  max-width: 58ch;
  color: var(--lnu-text-muted);
  font-size: 13px;
  line-height: 1.5;
}

@media (max-width: 1100px) {
  .review-bot-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .review-bot-hero {
    padding: 18px;
  }

  .review-bot-hero {
    flex-direction: column;
  }

  .review-bot-hero-meta {
    justify-items: start;
  }

  .review-bot-setup,
  .review-bot-session,
  .review-question-card {
    padding: 14px;
  }

  .review-bot-results,
  .review-bot-footer,
  .review-bot-section-head,
  .review-question-head {
    flex-direction: column;
    align-items: flex-start;
  }

  .review-bot-actions {
    display: grid;
    grid-template-columns: 1fr;
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
