import { watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePrimeVue } from 'primevue/config'
import type { AvailableLocales } from '@/plugins/i18n'

const primeVueLocales: Record<AvailableLocales, Record<string, unknown>> = {
  ru: {
    accept: 'Принять',
    reject: 'Отклонить',
    choose: 'Выбрать',
    upload: 'Загрузить',
    cancel: 'Отмена',
    dayNames: ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'],
    dayNamesShort: ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
    dayNamesMin: ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
    monthNames: [
      'Январь',
      'Февраль',
      'Март',
      'Апрель',
      'Май',
      'Июнь',
      'Июль',
      'Август',
      'Сентябрь',
      'Октябрь',
      'Ноябрь',
      'Декабрь',
    ],
    monthNamesShort: [
      'Янв',
      'Фев',
      'Мар',
      'Апр',
      'Май',
      'Июн',
      'Июл',
      'Авг',
      'Сен',
      'Окт',
      'Ноя',
      'Дек',
    ],
    today: 'Сегодня',
    clear: 'Очистить',
    weak: 'Слабый',
    medium: 'Средний',
    strong: 'Сильный',
    passwordPrompt: 'Введите пароль',
    emptyFilterMessage: 'Ничего не найдено',
    emptyMessage: 'Нет доступных опций',
    searchMessage: '{0} результатов доступно',
    selectionMessage: '{0} элементов выбрано',
  },
  en: {
    accept: 'Accept',
    reject: 'Reject',
    choose: 'Choose',
    upload: 'Upload',
    cancel: 'Cancel',
    dayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    dayNamesShort: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
    dayNamesMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
    monthNames: [
      'January',
      'February',
      'March',
      'April',
      'May',
      'June',
      'July',
      'August',
      'September',
      'October',
      'November',
      'December',
    ],
    monthNamesShort: [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ],
    today: 'Today',
    clear: 'Clear',
    weak: 'Weak',
    medium: 'Medium',
    strong: 'Strong',
    passwordPrompt: 'Enter a password',
    emptyFilterMessage: 'No results found',
    emptyMessage: 'No available options',
    searchMessage: '{0} results are available',
    selectionMessage: '{0} items selected',
  },
}

export const usePrimeVueLocale = () => {
  const { locale } = useI18n()
  const primevue = usePrimeVue()

  watch(
    locale,
    (newLocale) => {
      const newLocaleKey = newLocale as AvailableLocales

      if (!primeVueLocales[newLocaleKey]) {
        if (import.meta.env.DEV) {
          console.error(`[PrimeVue] Missing locale mapping: ${newLocaleKey}. Add it to usePrimeVueLocale.ts`)
        }
        primevue.config.locale = primeVueLocales.en as never
        return
      }

      primevue.config.locale = primeVueLocales[newLocaleKey] as never
    },
    { immediate: true },
  )
}
