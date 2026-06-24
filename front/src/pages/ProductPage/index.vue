<template>
  <div class="product-page">
    <!-- Loading skeleton -->
    <div v-if="loading" class="product-page__skeleton">
      <Skeleton height="32px" class="mb-3" />
      <div class="row g-4">
        <div class="col-lg-9">
          <Skeleton height="120px" class="mb-3" />
          <Skeleton height="200px" />
        </div>
        <div class="col-lg-3">
          <Skeleton height="200px" />
        </div>
      </div>
    </div>

    <!-- Error / not found -->
    <div v-else-if="error && !loading" class="product-page__error">
      <Message severity="error">{{ t('catalog.product.page.errors.notFound') }}</Message>
      <Button
        icon="pi pi-arrow-left"
        :label="t('catalog.product.page.back')"
        severity="secondary"
        class="mt-3"
        @click="router.push('/admin/products')"
      />
    </div>

    <!-- Main content -->
    <template v-else-if="product">
      <PageHeader
        :title="product.name"
        icon="pi pi-box"
      >
        <template #subtitle>
          <span class="product-page__header-sub">
            <span class="product-page__header-code">{{ product.code }}</span>
            <span v-if="product.group" class="product-page__header-group">
              · {{ product.group.name }}
            </span>
          </span>
        </template>
        <template #actions>
          <Button
            icon="pi pi-arrow-left"
            :label="t('catalog.product.page.back')"
            severity="secondary"
            text
            @click="router.back()"
          />
          <ProductPricingTypeTag :pricing-type="product.pricing_type" />
          <Button
            v-if="canWrite"
            icon="pi pi-pencil"
            :label="t('catalog.product.page.edit')"
            severity="secondary"
            outlined
            @click="openEditDrawer"
          />
        </template>
      </PageHeader>

      <div class="product-page__content">
        <div class="row g-4">
          <!-- Left: Tabs -->
          <div class="col-lg-9">
            <div class="product-page__tabs-card">
              <Tabs v-model:value="activeTab">
                <TabList>
                  <Tab value="prices">{{ t('catalog.product.page.tabs.prices') }}</Tab>
                  <Tab value="deals">{{ t('catalog.product.page.tabs.deals') }}</Tab>
                </TabList>
                <TabPanels>
                  <TabPanel value="prices">
                    <ProductPricesTab
                      :product="product"
                      :saving="priceMutation.isPending.value"
                      @add-plan="openCreatePlanDialog"
                      @edit-plan="openEditPlanDialog"
                      @delete-plan="confirmDeletePlan"
                      @save-price="savePrice"
                      @delete-price="confirmDeletePrice"
                    />
                  </TabPanel>
                  <TabPanel value="deals">
                    <ProductDealsTab />
                  </TabPanel>
                </TabPanels>
              </Tabs>
            </div>
          </div>

          <!-- Right: Rail -->
          <div class="col-lg-3">
            <ProductRightRail
              :product="product"
              @toggle-active="toggleActive"
            />
          </div>
        </div>
      </div>
    </template>

    <!-- Edit Product Drawer (reuses ProductCreateDrawer) -->
    <ProductCreateDrawer
      v-if="product"
      v-model="editDrawerOpen"
      :edit-product="product"
      :groups="groups"
      @saved="onProductSaved"
    />

    <!-- Plan Create/Edit Dialog -->
    <ProductPlanCreateDialog
      v-model="planDialogOpen"
      :edit-plan="editingPlan"
      :saving="planMutation.isPending.value"
      @submit="onPlanSubmit"
    />

    <Toast position="top-right" />
    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Message from 'primevue/message'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import { useUserStore } from '@/stores/user'
import { catalogApi } from '@/api/catalog'
import { useProductPageData } from './composables/useProductPageData'
import { useProductPageActions } from './composables/useProductPageActions'
import ProductPricingTypeTag from '@/pages/ProductsPage/components/ProductPricingTypeTag.vue'
import ProductRightRail from './components/ProductRightRail.vue'
import ProductPricesTab from './components/ProductPricesTab.vue'
import ProductDealsTab from './components/ProductDealsTab.vue'
import ProductPlanCreateDialog from './components/ProductPlanCreateDialog.vue'
import ProductCreateDrawer from '@/pages/ProductsPage/components/ProductCreateDrawer.vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import type { ProductGroupDto } from '@/entities/catalog'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const userStore = useUserStore()

const productId = Number(route.params.id)

const canWrite = computed(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

const { loading, product, error, load } = useProductPageData(productId)

const {
  editDrawerOpen,
  planDialogOpen,
  editingPlan,
  planMutation,
  priceMutation,
  openEditDrawer,
  openCreatePlanDialog,
  openEditPlanDialog,
  toggleActive,
  savePrice,
  confirmDeletePrice,
  createPlan,
  updatePlan,
  confirmDeletePlan,
} = useProductPageActions({ product, reload: load })

// Groups for edit drawer
const groupsResource = useAsyncResource<ProductGroupDto[]>(() => [])
const groups = groupsResource.data

const activeTab = ref('prices')

async function onPlanSubmit(payload: {
  name: string
  code: string | null
  unit: string
  sort_order: number
  is_active: boolean
}) {
  if (editingPlan.value) {
    await updatePlan(editingPlan.value.id, payload)
  } else {
    await createPlan(payload)
  }
}

function onProductSaved() {
  void load()
}

onMounted(async () => {
  await load()
  try {
    await groupsResource.run(() => catalogApi.getProductGroups({ active_only: true }))
  } catch {
    // non-critical
  }
})
</script>

<style lang="scss" scoped>
.product-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.product-page__skeleton {
  padding: $space-4 $space-6;
}

.product-page__error {
  padding: $space-6;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: $space-3;
}

.product-page__content {
  padding: $space-4 $space-6;
  flex: 1;
}

.product-page__header-sub {
  font-size: $font-size-sm;
  color: $surface-500;
  display: flex;
  align-items: center;
  gap: $space-1;
}

.product-page__header-code {
  font-family: $font-family-mono;
  font-size: $font-size-xs;
}

.product-page__header-group {
  color: $surface-500;
}

.product-page__tabs-card {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;
}

.mt-3 {
  margin-top: $space-3;
}

.mb-3 {
  margin-bottom: $space-3;
}
</style>
