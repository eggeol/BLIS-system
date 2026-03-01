<template>
  <main class="forgot-shell">
    <section class="forgot-card">
      <div class="forgot-icon-wrap">
        <ShieldCheck :size="22" />
      </div>

      <h1>Forgot your password?</h1>
      <p>
        Enter your account email and we will send a reset link once password recovery is enabled.
      </p>

      <form class="forgot-form" @submit.prevent="handleSubmit">
        <label class="field-group">
          <span>Email address</span>
          <div class="field-wrap">
            <Mail :size="16" class="field-icon" />
            <input
              v-model="email"
              type="email"
              class="field-input"
              placeholder="you@lnu.edu.ph"
              autocomplete="email"
            />
          </div>
        </label>

        <button type="submit" class="submit-btn">Request reset link</button>
      </form>

      <p v-if="message" class="status-msg">{{ message }}</p>

      <router-link to="/login" class="back-link">
        <ArrowLeft :size="14" />
        Back to login
      </router-link>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue'
import { ArrowLeft, Mail, ShieldCheck } from 'lucide-vue-next'

const email = ref('')
const message = ref('')

function handleSubmit() {
  if (!email.value.trim()) {
    message.value = 'Please enter your email address first.'
    return
  }

  message.value = 'Reset request captured. This feature will be connected to backend email soon.'
}
</script>

<style scoped>
.forgot-shell {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px 16px;
  background:
    radial-gradient(circle at 15% 8%, rgba(26, 35, 126, 0.12), transparent 30%),
    radial-gradient(circle at 85% 92%, rgba(201, 168, 76, 0.24), transparent 35%),
    var(--lnu-bg);
}

.forgot-card {
  width: 100%;
  max-width: 480px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(253, 246, 227, 0.95));
  border: 1px solid rgba(26, 35, 126, 0.16);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: 28px;
}

.forgot-icon-wrap {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--lnu-navy-light);
  background: rgba(26, 35, 126, 0.1);
}

.forgot-card h1 {
  margin: 14px 0 8px;
  font-size: 29px;
  line-height: 1.2;
  color: var(--lnu-text);
}

.forgot-card p {
  margin: 0;
  font-size: 15px;
  color: var(--lnu-text-muted);
}

.forgot-form {
  margin-top: 18px;
  display: grid;
  gap: 14px;
}

.field-group {
  display: grid;
  gap: 8px;
}

.field-group span {
  font-size: 14px;
  font-weight: 600;
  color: var(--lnu-text);
}

.field-wrap {
  position: relative;
}

.field-icon {
  position: absolute;
  top: 50%;
  left: 12px;
  transform: translateY(-50%);
  color: var(--lnu-navy-light);
}

.field-input {
  width: 100%;
  height: 44px;
  border: 1px solid rgba(13, 21, 71, 0.2);
  border-radius: var(--radius-sm);
  padding: 0 12px 0 38px;
  color: var(--lnu-text);
  background: rgba(255, 255, 255, 0.9);
}

.field-input:focus {
  outline: none;
  border-color: var(--lnu-navy);
  box-shadow: var(--focus-ring);
}

.submit-btn {
  height: 44px;
  border: none;
  border-radius: var(--radius-sm);
  background: var(--lnu-navy);
  color: var(--lnu-gold-light);
  font-weight: 700;
  transition: background 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
}

.submit-btn:hover {
  background: var(--lnu-navy-light);
  box-shadow: 0 8px 16px rgba(26, 35, 126, 0.24);
  transform: translateY(-1px);
}

.status-msg {
  margin-top: 12px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(46, 125, 50, 0.24);
  background: rgba(46, 125, 50, 0.1);
  color: var(--lnu-success);
  font-size: 14px;
}

.back-link {
  margin-top: 16px;
  font-size: 14px;
  color: var(--lnu-navy-light);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.back-link:hover {
  text-decoration: underline;
}
</style>
