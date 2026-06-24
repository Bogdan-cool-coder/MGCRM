<template>
  <div class="public-form">
    <!-- Brand bar (navy, brand-invariant) -->
    <header class="public-form__brand" aria-hidden="true">
      <img src="/logo-light.svg" alt="MACRO Global" class="public-form__logo" />
    </header>

    <main class="public-form__main">
      <div class="public-form__card">
        <!-- Loading -->
        <div v-if="isLoading" class="public-form__state">
          <ProgressSpinner style="width: 40px; height: 40px" stroke-width="4" />
        </div>

        <!-- Missing / inactive form -->
        <div v-else-if="isMissing" class="public-form__state">
          <i class="pi pi-inbox public-form__state-icon" aria-hidden="true" />
          <h1 class="public-form__title">{{ t('inbox.publicForm.notFoundTitle') }}</h1>
          <p class="public-form__subtitle">{{ t('inbox.publicForm.notFoundBody') }}</p>
        </div>

        <!-- Thank you -->
        <div v-else-if="submitted" class="public-form__state">
          <i class="pi pi-check-circle public-form__state-icon public-form__state-icon--ok" aria-hidden="true" />
          <h1 class="public-form__title">{{ t('inbox.publicForm.thankYouTitle') }}</h1>
          <p class="public-form__subtitle">
            {{ thankYouText || t('inbox.publicForm.thankYouBody') }}
          </p>
        </div>

        <!-- Form -->
        <template v-else>
          <h1 class="public-form__title">{{ formName }}</h1>

          <form class="public-form__form" novalidate @submit.prevent="handleSubmit">
            <div v-for="field in fields" :key="field.name" class="public-form__field">
              <label class="public-form__label" :for="`pf-${field.name}`">
                {{ field.label }}
                <span v-if="field.required" class="public-form__req" aria-hidden="true">*</span>
              </label>
              <InputText
                :id="`pf-${field.name}`"
                v-model="values[field.name]"
                :type="inputType(field.type)"
                :invalid="!!fieldErrors[field.name]"
                :disabled="isSubmitting"
                class="w-100"
              />
              <small v-if="fieldErrors[field.name]" class="public-form__error">
                {{ fieldErrors[field.name] }}
              </small>
            </div>

            <!-- Honeypot: visually hidden, never seen/filled by a real user. -->
            <div class="public-form__honeypot" aria-hidden="true">
              <label :for="`pf-${honeypotField}`">{{ honeypotField }}</label>
              <input
                :id="`pf-${honeypotField}`"
                v-model="honeypot"
                type="text"
                tabindex="-1"
                autocomplete="off"
              />
            </div>

            <Message v-if="generalError" severity="error" :closable="false">
              {{ generalError }}
            </Message>

            <Button
              type="submit"
              :label="isSubmitting ? t('inbox.publicForm.submitting') : t('inbox.publicForm.submit')"
              :loading="isSubmitting"
              :disabled="isSubmitting"
              class="w-100 public-form__submit"
            />
          </form>
        </template>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import { usePublicLeadFormPage } from './composables/usePublicLeadFormPage'

const { t } = useI18n()
const route = useRoute()

const slug = String(route.params['slug'] ?? '')

const {
  formName,
  fields,
  values,
  honeypot,
  honeypotField,
  fieldErrors,
  generalError,
  isLoading,
  isSubmitting,
  isMissing,
  submitted,
  thankYouText,
  loadMeta,
  handleSubmit,
} = usePublicLeadFormPage(slug)

/** Map a declared field type to a native input type. */
function inputType(type: string): string {
  if (type === 'email') return 'email'
  if (type === 'phone') return 'tel'
  return 'text'
}

onMounted(() => {
  void loadMeta()
})
</script>

<style lang="scss" scoped>
.public-form {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  width: 100%;
  background-color: $surface-100;

  :global(.app-dark) & {
    background-color: var(--p-surface-900);
  }
}

.public-form__brand {
  background-color: $brand-header-bg;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: $space-5 $space-4;
}

.public-form__logo {
  height: 40px;
  width: auto;
  object-fit: contain;
}

.public-form__main {
  flex: 1 1 auto;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: $space-8 $space-4;
}

.public-form__card {
  width: 100%;
  max-width: 480px;
  background-color: $surface-card;
  border-radius: $radius-lg;
  box-shadow: var(--app-shadow-lg);
  padding: $space-8;
  display: flex;
  flex-direction: column;
  gap: $space-5;

  :global(.app-dark) & {
    background-color: var(--p-surface-800);
    border: 1px solid var(--p-surface-700);
    box-shadow: $shadow-elevated;
  }
}

.public-form__state {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: $space-3;
  padding: $space-6 0;
}

.public-form__state-icon {
  font-size: $font-size-3xl;
  color: $surface-500;

  &--ok {
    color: $primary-color;
  }
}

.public-form__title {
  font-size: $font-size-2xl;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  line-height: $line-height-tight;
}

.public-form__subtitle {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
}

.public-form__form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.public-form__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.public-form__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-900;
}

.public-form__req {
  color: $red-700;
}

.public-form__error {
  font-size: $font-size-xs;
  color: $red-700;
}

.public-form__submit {
  margin-top: $space-2;
}

// Honeypot: removed from layout & a11y tree, but still in the DOM so bots fill it.
.public-form__honeypot {
  position: absolute;
  left: -9999px;
  width: 1px;
  height: 1px;
  overflow: hidden;
}

@media (max-width: 479.98px) {
  .public-form__card {
    box-shadow: none;
    border-radius: $radius-md;
    padding: $space-6 $space-5;
  }
}
</style>
