// eslint.config.js
import tsParser from '@typescript-eslint/parser'
import vueParser from 'vue-eslint-parser'
import tsPlugin from '@typescript-eslint/eslint-plugin'
import vuePlugin from 'eslint-plugin-vue'

export default [
  {
    ignores: ['dist/**', 'src/types/**/*.d.ts'],
  },
  {
    files: ['src/**/*.{ts,vue}'],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: '@/appServices',
              message: 'Use "@/application" or "@/coordination" instead of the removed appServices layer.',
            },
            {
              name: '@/services/sessionCoordinator',
              message: 'Import session coordination from "@/coordination".',
            },
            {
              name: '@/services/userSessionService',
              message: 'Import session application services from "@/application".',
            },
            {
              name: '@/services/unauthorizedHandler',
              message: 'Import unauthorized handling from "@/coordination".',
            },
            {
              name: '@/services/userSessionState',
              message: 'Import session state helpers from "@/coordination".',
            },
            {
              name: '@/services/localeService',
              message: 'Import locale coordination from "@/coordination".',
            },
            {
              name: '@/services/notificationService',
              message: 'Import notifications from "@/coordination".',
            },
            {
              name: '@/services/iframeTokenService',
              message: 'Import storage adapters from "@/storage".',
            },
            {
              name: '@/services/session',
              message: 'Import session application services from "@/application" and session coordinators from "@/coordination".',
            },
            {
              name: '@/bootstrap/appBootstrap',
              message: 'Import bootstrap flow from "@/application".',
            },
          ],
        },
      ],
    },
  },
  {
    files: ['public/mockServiceWorker.js'],
    linterOptions: {
      reportUnusedDisableDirectives: 'off',
    },
  },
  {
    // Указываем все Vue + TS файлы
    files: ['*.ts', '*.vue', '**/*.ts', '**/*.vue'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        parser: tsParser,      // TypeScript внутри <script lang="ts">
        ecmaVersion: 2021,
        sourceType: 'module',
        extraFileExtensions: ['.vue'], // Важно для ESLint 10
      },
    },
    plugins: {
      vue: vuePlugin,
      '@typescript-eslint': tsPlugin,
    },
    rules: {
      // JS/TS правила
      'no-unused-vars': 'off',
      '@typescript-eslint/no-unused-vars': [
        'warn',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_',
        },
      ],
      '@typescript-eslint/explicit-function-return-type': 'off',

      // Vue правила
      'vue/no-unused-components': 'warn',
      'vue/multi-word-component-names': 'off',

      // Prettier-style правила
      quotes: ['warn', 'single', { avoidEscape: true }],
      semi: ['warn', 'never'],
      'comma-dangle': ['warn', 'always-multiline'],
    },
  },
]
