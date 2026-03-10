<template>
  <div class="auth-shell">
    <aside class="auth-brand">
      <div class="brand-card">
        <div class="brand-seal">
          <GraduationCap :size="30" />
        </div>
        <p class="brand-label">LNU LLE Platform</p>
        <h1>Create your reviewer account</h1>
        <p class="brand-copy">
          Register once and access your exam rooms, analytics, and mock examination modules.
        </p>

        <div class="brand-list">
          <div class="brand-item" v-for="item in highlights" :key="item.text">
            <component :is="item.icon" :size="16" class="brand-item-icon" />
            <span>{{ item.text }}</span>
          </div>
        </div>
      </div>
    </aside>

    <main class="auth-main">
      <section class="auth-card">
        <header class="auth-header">
          <p class="eyebrow">Registration</p>
          <h2>Create Account</h2>
          <p class="subtitle">Complete your details to start using the platform.</p>
        </header>

        <div v-if="apiError" class="alert alert-danger">{{ apiError }}</div>

        <form class="auth-form" @submit.prevent="handleSubmit">
          <label class="field-group">
            <span class="field-label">First name</span>
            <div class="field-wrap">
              <UserRound :size="17" class="field-icon" />
              <input
                v-model="form.first_name"
                type="text"
                class="field-input"
                placeholder="e.g. Juan"
                autocomplete="given-name"
              />
            </div>
          </label>

          <label class="field-group">
            <span class="field-label">Middle name (optional)</span>
            <div class="field-wrap">
              <UserRound :size="17" class="field-icon" />
              <input
                v-model="form.middle_name"
                type="text"
                class="field-input"
                placeholder="e.g. Santos"
                autocomplete="additional-name"
              />
            </div>
          </label>

          <label class="field-group">
            <span class="field-label">Last name</span>
            <div class="field-wrap">
              <UserRound :size="17" class="field-icon" />
              <input
                v-model="form.last_name"
                type="text"
                class="field-input"
                placeholder="e.g. Dela Cruz"
                autocomplete="family-name"
              />
            </div>
          </label>

          <label class="field-group">
            <span class="field-label">Student ID</span>
            <div class="field-wrap">
              <Hash :size="17" class="field-icon" />
              <input
                v-model="form.student_id"
                type="text"
                class="field-input"
                placeholder="e.g. 2301290"
                inputmode="numeric"
                pattern="[0-9]*"
                autocomplete="off"
              />
            </div>
          </label>

          <label class="field-group">
            <span class="field-label">Email address</span>
            <div class="field-wrap">
              <Mail :size="17" class="field-icon" />
              <input
                v-model="form.email"
                type="email"
                class="field-input"
                placeholder="you@lnu.edu.ph"
                autocomplete="email"
              />
            </div>
          </label>

          <div class="field-grid">
            <label class="field-group">
              <span class="field-label">Password</span>
              <div class="field-wrap">
                <LockKeyhole :size="17" class="field-icon" />
                <input
                  v-model="form.password"
                  :type="showPw ? 'text' : 'password'"
                  class="field-input field-input-with-toggle"
                  placeholder="Min. 8 characters"
                  autocomplete="new-password"
                />
                <button type="button" class="field-toggle" @click="showPw = !showPw">
                  <EyeOff v-if="showPw" :size="16" />
                  <Eye v-else :size="16" />
                </button>
              </div>
            </label>

            <label class="field-group">
              <span class="field-label">Confirm password</span>
              <div class="field-wrap">
                <LockKeyhole :size="17" class="field-icon" />
                <input
                  v-model="form.password_confirmation"
                  :type="showPw ? 'text' : 'password'"
                  class="field-input"
                  :class="{ 'field-input-error': pwMismatch }"
                  placeholder="Re-enter password"
                  autocomplete="new-password"
                />
              </div>
              <span v-if="pwMismatch" class="field-error">Passwords do not match.</span>
            </label>
          </div>

          <div class="pw-strength" v-if="form.password">
            <div class="pw-track">
              <div class="pw-fill" :style="{ width: `${pwStrength.pct}%`, background: pwStrength.color }" />
            </div>
            <span class="pw-label" :style="{ color: pwStrength.color }">{{ pwStrength.label }}</span>
          </div>

          <div class="terms-section">
            <label class="terms-wrap">
              <input type="checkbox" v-model="form.agreed" :disabled="!canAgree" />
              <span>
                I agree to the
                <a
                  href="#"
                  class="inline-link strong"
                  :style="termsRead ? 'color: var(--lnu-success)' : ''"
                  @click.prevent="openPolicyModal('terms')"
                  >Terms of Service</a
                >
                and
                <a
                  href="#"
                  class="inline-link strong"
                  :style="privacyRead ? 'color: var(--lnu-success)' : ''"
                  @click.prevent="openPolicyModal('privacy')"
                  >Privacy Policy</a
                >.
              </span>
            </label>
            <span v-if="!canAgree" class="terms-helper">Open and read both documents before agreeing.</span>
          </div>

          <button type="submit" class="submit-btn" :disabled="isLoading">
            <span v-if="!isLoading">Create Account</span>
            <span v-else class="spinner" />
          </button>
        </form>

        <p class="auth-footer">
          Already registered?
          <router-link to="/login" class="inline-link strong">Sign in</router-link>
        </p>
      </section>
    </main>

    <div
      v-if="activePolicy"
      class="policy-modal-backdrop"
      role="dialog"
      aria-modal="true"
      :aria-label="activePolicyTitle"
      @click.self="closePolicyModal"
    >
      <section class="policy-modal">
        <header class="policy-modal-header">
          <h3>{{ activePolicyTitle }}</h3>
          <button type="button" class="policy-close-btn" @click="closePolicyModal">Close</button>
        </header>

        <div ref="policyBodyRef" class="policy-modal-body" @scroll="handlePolicyScroll">
          <p class="policy-updated">{{ legalUpdatedLabel }}</p>
          <p v-if="legalLoading" class="policy-loading">Loading latest policy content...</p>
          <p v-else-if="legalError" class="policy-warning">{{ legalError }}</p>
          <article class="policy-article">
            <h4>{{ activePolicyTitle }}</h4>
            <p class="policy-body-text">{{ activePolicyContent }}</p>
          </article>
        </div>

        <footer class="policy-modal-footer">
          <span class="policy-scroll-note">
            {{
              policyReadyToConfirm
                ? 'You reached the end of this document.'
                : 'Scroll to the bottom to mark as read.'
            }}
          </span>
          <button
            type="button"
            class="submit-btn policy-mark-btn"
            :disabled="!policyReadyToConfirm || legalLoading"
            @click="markPolicyAsRead"
          >
            Mark as Read
          </button>
        </footer>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { settingsApi } from '@/api/settings.api'
import { useAuthStore } from '@/store/auth.store'
import {
  BarChart3,
  ClipboardCheck,
  Eye,
  EyeOff,
  GraduationCap,
  Hash,
  LockKeyhole,
  Mail,
  ShieldCheck,
  UserRound,
} from 'lucide-vue-next'

const form = reactive({
  first_name: '',
  middle_name: '',
  last_name: '',
  student_id: '',
  email: '',
  password: '',
  password_confirmation: '',
  agreed: false,
})

const showPw = ref(false)
const isLoading = ref(false)
const apiError = ref('')
const activePolicy = ref('')
const policyBodyRef = ref(null)
const policyReadyToConfirm = ref(false)
const termsRead = ref(false)
const privacyRead = ref(false)
const legalLoading = ref(false)
const legalError = ref('')
const legalUpdatedAt = ref('')

const auth = useAuthStore()
const router = useRouter()

const highlights = [
  { icon: ClipboardCheck, text: 'Room-based class exam management' },
  { icon: BarChart3, text: 'Clear analytics and score tracking' },
  { icon: ShieldCheck, text: 'Role-based access for each account' },
]

const defaultTermsOfUseText = `1. Acceptable Use
Use the platform only for official academic review and examination activities. Sharing accounts, impersonating users, or attempting to bypass exam controls is prohibited.

2. Account Responsibility
You are responsible for keeping your credentials secure. Actions performed using your account are treated as your own unless officially reported as compromised.

3. Academic Integrity
Cheating, unauthorized collaboration, copying exam content, or distributing question material may result in account suspension and disciplinary action.

4. Availability and Changes
System features may be updated or temporarily unavailable for maintenance. Administrators may enforce policy updates to keep the platform compliant and secure.

5. Enforcement
Violations can lead to investigation, removal of access, and formal reporting to relevant academic authorities.`

const defaultPrivacyPolicyText = `1. Data We Collect
The platform stores identity details (name, student ID, email), role information, room enrollments, and exam performance records required for academic operations.

2. How Data Is Used
Collected data is used to authenticate users, manage rooms and exams, generate reports, and maintain audit logs for security and accountability.

3. Data Access
Access to personal and exam-related data is role-based. Authorized staff and administrators can access only the data needed for their responsibilities.

4. Data Retention
Records are retained based on institutional requirements for academic continuity, reporting, and account recovery workflows.

5. Security and Contact
Reasonable safeguards are applied to protect stored data. Report privacy concerns or account issues to the institution administrators for assistance.`

const legalTexts = reactive({
  terms_of_use_text: defaultTermsOfUseText,
  privacy_policy_text: defaultPrivacyPolicyText,
})

const pwMismatch = computed(() =>
  form.password_confirmation.length > 0 && form.password !== form.password_confirmation,
)
const canAgree = computed(() => termsRead.value && privacyRead.value)
const activePolicyTitle = computed(() => (activePolicy.value === 'terms' ? 'Terms of Service' : 'Privacy Policy'))
const activePolicyContent = computed(() =>
  activePolicy.value === 'terms'
    ? legalTexts.terms_of_use_text
    : legalTexts.privacy_policy_text,
)
const legalUpdatedLabel = computed(() => {
  if (!legalUpdatedAt.value) {
    return 'Last updated: March 5, 2026'
  }

  const parsed = new Date(legalUpdatedAt.value)
  if (Number.isNaN(parsed.getTime())) {
    return 'Last updated: March 5, 2026'
  }

  return `Last updated: ${parsed.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })}`
})

const pwStrength = computed(() => {
  const pw = form.password
  if (pw.length < 6) return { pct: 20, color: 'var(--lnu-danger)', label: 'Weak' }
  if (pw.length < 10) return { pct: 55, color: 'var(--lnu-gold)', label: 'Fair' }
  if (/[A-Z]/.test(pw) && /[0-9]/.test(pw)) return { pct: 100, color: 'var(--lnu-success)', label: 'Strong' }
  return { pct: 75, color: 'var(--lnu-gold)', label: 'Good' }
})

function updatePolicyReadiness() {
  if (legalLoading.value) {
    policyReadyToConfirm.value = false
    return
  }

  const bodyEl = policyBodyRef.value
  if (!bodyEl) return

  const scrollBottom = bodyEl.scrollTop + bodyEl.clientHeight
  policyReadyToConfirm.value = scrollBottom >= bodyEl.scrollHeight - 8
}

async function openPolicyModal(type) {
  activePolicy.value = type
  policyReadyToConfirm.value = false

  await nextTick()
  if (!policyBodyRef.value) return

  policyBodyRef.value.scrollTop = 0
  updatePolicyReadiness()
}

function closePolicyModal() {
  activePolicy.value = ''
  policyReadyToConfirm.value = false
}

function handlePolicyScroll() {
  updatePolicyReadiness()
}

function markPolicyAsRead() {
  if (!policyReadyToConfirm.value) return

  if (activePolicy.value === 'terms') {
    termsRead.value = true
  }

  if (activePolicy.value === 'privacy') {
    privacyRead.value = true
  }

  closePolicyModal()
}

async function loadPublicLegalSettings() {
  legalLoading.value = true
  legalError.value = ''

  try {
    const { data } = await settingsApi.getPublicLegal()
    const settings = data?.settings ?? {}

    legalTexts.terms_of_use_text = String(settings.terms_of_use_text || defaultTermsOfUseText)
    legalTexts.privacy_policy_text = String(settings.privacy_policy_text || defaultPrivacyPolicyText)
    legalUpdatedAt.value = String(data?.last_updated_at ?? '')
  } catch (error) {
    legalTexts.terms_of_use_text = defaultTermsOfUseText
    legalTexts.privacy_policy_text = defaultPrivacyPolicyText
    legalUpdatedAt.value = ''
    legalError.value = 'Unable to load latest policy text. Showing default content.'
  } finally {
    legalLoading.value = false
    if (activePolicy.value) {
      await nextTick()
      updatePolicyReadiness()
    }
  }
}

onMounted(() => {
  loadPublicLegalSettings()
})

async function handleSubmit() {
  apiError.value = ''
  const firstName = form.first_name.trim()
  const middleName = form.middle_name.trim()
  const lastName = form.last_name.trim()
  const studentId = form.student_id.trim()
  const email = form.email.trim()

  if (!firstName || !lastName || !studentId || !email || !form.password || !form.password_confirmation) {
    apiError.value = 'Please complete all required fields.'
    return
  }

  if (!/^\d{7,20}$/.test(studentId)) {
    apiError.value = 'Student ID must be 7 to 20 digits.'
    return
  }

  if (pwMismatch.value) {
    apiError.value = 'Passwords do not match.'
    return
  }

  if (!canAgree.value) {
    apiError.value = 'Please open and read the Terms of Service and Privacy Policy first.'
    return
  }

  if (!form.agreed) {
    apiError.value = 'Please confirm your agreement to the Terms of Service and Privacy Policy.'
    return
  }

  isLoading.value = true
  try {
    await auth.register(
      firstName,
      middleName,
      lastName,
      studentId,
      email,
      form.password,
      form.password_confirmation,
      form.agreed,
    )
    await router.push('/dashboard')
  } catch (error) {
    apiError.value = error.response?.data?.message ?? 'Registration failed. Please try again.'
  } finally {
    isLoading.value = false
  }
}
</script>

<style scoped>
.auth-shell {
  min-height: 100vh;
  display: grid;
  grid-template-columns: minmax(320px, 460px) 1fr;
  background:
    radial-gradient(circle at 10% 12%, rgba(26, 35, 126, 0.12), transparent 32%),
    radial-gradient(circle at 90% 90%, rgba(201, 168, 76, 0.24), transparent 35%),
    var(--lnu-bg);
}

.auth-brand {
  background: linear-gradient(155deg, var(--lnu-navy-deep), var(--lnu-navy));
  padding: 42px 34px;
  color: var(--lnu-white);
  display: flex;
}

.brand-card {
  border: 1px solid rgba(240, 208, 128, 0.25);
  border-radius: var(--radius-lg);
  padding: 30px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0));
  box-shadow: var(--shadow-md);
  width: 100%;
  display: flex;
  flex-direction: column;
}

.brand-seal {
  width: 58px;
  height: 58px;
  border-radius: 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(240, 208, 128, 0.16);
  color: var(--lnu-gold-light);
  border: 1px solid rgba(240, 208, 128, 0.35);
}

.brand-label {
  margin-top: 22px;
  font-size: 14px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(240, 208, 128, 0.88);
}

.brand-card h1 {
  margin: 10px 0 0;
  font-size: 31px;
  line-height: 1.2;
  color: var(--lnu-gold-light);
}

.brand-copy {
  margin-top: 14px;
  color: rgba(255, 255, 255, 0.82);
  font-size: 15px;
}

.brand-list {
  margin-top: auto;
  display: grid;
  gap: 12px;
  padding-top: 28px;
}

.brand-item {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: var(--radius-md);
  padding: 11px 12px;
  font-size: 14px;
  color: rgba(255, 255, 255, 0.88);
}

.brand-item-icon {
  color: var(--lnu-gold-light);
  flex-shrink: 0;
}

.auth-main {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 42px 24px;
  background:
    radial-gradient(circle at top right, rgba(26, 35, 126, 0.12), transparent 40%),
    radial-gradient(circle at bottom left, rgba(201, 168, 76, 0.2), transparent 42%);
}

.auth-card {
  width: 100%;
  max-width: 540px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(253, 246, 227, 0.94));
  border: 1px solid rgba(26, 35, 126, 0.16);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: 32px;
}

.auth-header h2 {
  margin: 6px 0 4px;
  font-size: 28px;
  line-height: 1.2;
  color: var(--lnu-text);
}

.eyebrow {
  font-size: 14px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--lnu-navy-light);
  font-weight: 700;
}

.subtitle {
  font-size: 15px;
  color: var(--lnu-text-muted);
}

.alert {
  margin-top: 16px;
  border-radius: var(--radius-sm);
  padding: 10px 12px;
  font-size: 14px;
}

.alert-danger {
  background: rgba(198, 40, 40, 0.12);
  color: var(--lnu-danger);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

.auth-form {
  margin-top: 20px;
  display: grid;
  gap: 16px;
}

.field-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
  align-items: start;
}

.field-group {
  display: grid;
  gap: 8px;
}

.field-label {
  font-size: 14px;
  font-weight: 600;
  color: var(--lnu-text);
}

.field-wrap {
  position: relative;
}

.field-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lnu-navy-light);
  opacity: 0.8;
}

.field-input {
  width: 100%;
  height: 44px;
  border: 1px solid rgba(13, 21, 71, 0.2);
  border-radius: var(--radius-sm);
  padding: 0 12px 0 40px;
  color: var(--lnu-text);
  background: rgba(255, 255, 255, 0.9);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.field-input:focus {
  border-color: var(--lnu-navy);
  box-shadow: var(--focus-ring);
  outline: none;
}

.field-input::placeholder {
  color: var(--lnu-gray-dark);
}

.field-select {
  appearance: none;
  padding-right: 38px;
}

.field-select-icon {
  position: absolute;
  top: 50%;
  right: 12px;
  transform: translateY(-50%);
  color: var(--lnu-text-muted);
  pointer-events: none;
}

.field-input-with-toggle {
  padding-right: 44px;
}

.field-toggle {
  position: absolute;
  top: 50%;
  right: 8px;
  transform: translateY(-50%);
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--lnu-text-muted);
  background: transparent;
  transition: background 0.2s ease, color 0.2s ease;
}

.field-toggle:hover {
  background: rgba(13, 21, 71, 0.08);
  color: var(--lnu-text);
}

.field-input-error {
  border-color: var(--lnu-danger);
}

.field-error {
  font-size: 14px;
  color: var(--lnu-danger);
}

.pw-strength {
  display: flex;
  align-items: center;
  gap: 10px;
}

.pw-track {
  flex: 1;
  height: 5px;
  border-radius: 999px;
  background: var(--lnu-gray);
  overflow: hidden;
}

.pw-fill {
  height: 100%;
  border-radius: 999px;
  transition: width 0.25s ease;
}

.pw-label {
  font-size: 14px;
  font-weight: 600;
}

.terms-wrap {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  font-size: 14px;
  color: var(--lnu-text-muted);
}

.terms-wrap input {
  margin-top: 2px;
  accent-color: var(--lnu-navy);
}

.terms-wrap input:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.terms-section {
  display: grid;
  gap: 10px;
}

.terms-helper {
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.inline-link {
  color: var(--lnu-navy-light);
  text-decoration: none;
}

.inline-link:hover {
  text-decoration: underline;
}

.inline-link.strong {
  font-weight: 700;
}

.submit-btn {
  height: 44px;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--lnu-navy);
  color: var(--lnu-gold-light);
  font-weight: 700;
  letter-spacing: 0.01em;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.15s ease, background 0.2s ease, box-shadow 0.2s ease;
}

.submit-btn:hover:not(:disabled) {
  background: var(--lnu-navy-light);
  box-shadow: 0 10px 18px rgba(26, 35, 126, 0.25);
  transform: translateY(-1px);
}

.submit-btn:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.spinner {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 2px solid rgba(240, 208, 128, 0.35);
  border-top-color: var(--lnu-gold-light);
  animation: spin 0.8s linear infinite;
}

.auth-footer {
  margin-top: 18px;
  color: var(--lnu-text-muted);
  text-align: center;
  font-size: 15px;
}

.policy-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  justify-content: center;
  align-items: center;
  background: rgba(10, 18, 56, 0.58);
  padding: 20px;
}

.policy-modal {
  width: min(780px, 100%);
  max-height: min(640px, 90vh);
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  background: #fff;
  border-radius: 14px;
  border: 1px solid rgba(13, 21, 71, 0.22);
  overflow: hidden;
}

.policy-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 18px;
  border-bottom: 1px solid rgba(13, 21, 71, 0.12);
}

.policy-modal-header h3 {
  margin: 0;
  color: var(--lnu-text);
}

.policy-close-btn {
  border: 1px solid rgba(13, 21, 71, 0.2);
  background: #fff;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--lnu-navy);
  padding: 6px 10px;
  cursor: pointer;
}

.policy-close-btn:hover {
  border-color: var(--lnu-navy);
}

.policy-modal-body {
  overflow: auto;
  padding: 16px 18px;
  display: grid;
  gap: 14px;
}

.policy-updated {
  margin: 0;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.policy-loading {
  margin: 0;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.policy-warning {
  margin: 0;
  font-size: 13px;
  color: var(--lnu-danger);
}

.policy-article h4 {
  margin: 0 0 6px;
  color: var(--lnu-text);
  font-size: 15px;
}

.policy-body-text {
  margin: 0;
  color: var(--lnu-text-muted);
  line-height: 1.52;
  font-size: 14px;
  white-space: pre-line;
}

.policy-modal-footer {
  border-top: 1px solid rgba(13, 21, 71, 0.12);
  padding: 14px 18px;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
  align-items: center;
}

.policy-scroll-note {
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.policy-mark-btn {
  min-width: 132px;
  height: 38px;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@media (max-width: 980px) {
  .auth-shell {
    grid-template-columns: 1fr;
  }

  .auth-brand {
    display: none;
  }

  .auth-main {
    padding: 24px 16px;
  }

  .auth-card {
    padding: 24px;
  }

  .field-grid {
    grid-template-columns: 1fr;
  }

  .policy-modal-backdrop {
    padding: 12px;
  }

  .policy-modal-footer {
    align-items: stretch;
  }

  .policy-mark-btn {
    width: 100%;
  }
}
</style>
