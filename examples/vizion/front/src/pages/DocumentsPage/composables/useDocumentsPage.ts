import { watch } from 'vue'
import { useDocumentGenerationModalStore } from '@/stores/documentGenerationModal'
import { useDocumentsPageActions } from './useDocumentsPageActions'
import { useDocumentsPageData } from './useDocumentsPageData'

export const useDocumentsPage = () => {
  const data = useDocumentsPageData()
  const actions = useDocumentsPageActions()
  const documentGenerationModal = useDocumentGenerationModalStore()

  // After the document-generation modal settles having created a template,
  // refetch the library so the new template surfaces (the user may click "Done"
  // and stay on the list instead of opening it).
  watch(
    () => documentGenerationModal.documentUpdatedTick,
    () => {
      void data.refresh()
    },
  )

  return {
    ...data,
    ...actions,
  }
}
