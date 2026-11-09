<script setup lang="ts">
// Impostazioni → Aspetto (Web Pro Italia, 09/11/2026): colore principale e angoli dell'installazione.
// L'anteprima è immediata (stesse variabili CSS del layout); salvando, la pagina si ricarica e l'aspetto vale ovunque.
import { computed, watch, onBeforeUnmount } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'
import Alert from '@/components/Alert.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Check } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'

interface Scelta { valore: string; nome: string; campione?: string }
interface Props {
  aspetto: { colore: string; angoli: string }
  scelte: { colori: Scelta[]; angoli: Scelta[]; anteprime: Record<string, Record<string, string>> }
}
const props = defineProps<Props>()
const page = usePage()
const flashMessage = computed(() => (page.props as any).flash?.message)

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('impostazioni.label.settings'), href: '/impostazioni' },
  { title: trans('impostazioni.header.aspetto_title'), href: '/impostazioni/aspetto' },
])

const form = useForm({ colore: props.aspetto.colore, angoli: props.aspetto.angoli })

const anteprima = () => {
  const css = props.scelte.anteprime?.[form.colore]?.[form.angoli] ?? ''
  let el = document.getElementById('km-aspetto-anteprima') as HTMLStyleElement | null
  if (!el) { el = document.createElement('style'); el.id = 'km-aspetto-anteprima'; document.head.appendChild(el) }
  el.textContent = css
}
watch(() => [form.colore, form.angoli], anteprima, { immediate: true })
onBeforeUnmount(() => document.getElementById('km-aspetto-anteprima')?.remove())

const submit = () => form.post('/impostazioni/aspetto', {
  preserveScroll: true,
  onSuccess: () => window.location.reload(),
})
</script>

<template>
  <AppLayout :breadcrumbs="[]">
    <Head :title="trans('impostazioni.header.aspetto_title')" />

    <div class="px-4 py-6 space-y-6">
      <PageHeaderGuide
        :page-title="trans('impostazioni.header.aspetto_title')"
        :page-subtitle="trans('impostazioni.header.aspetto_description')"
        :guides="[]"
        :breadcrumbs="breadcrumbs"
        back-url="/impostazioni"
        :back-text="trans('impostazioni.label.settings')"
        :video-url="null"
      />

      <div v-if="flashMessage" class="py-2">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <form @submit.prevent="submit">
        <Card class="border shadow-none p-4 mt-6">
          <CardContent class="space-y-6 p-0">
            <div class="border rounded-lg p-4 space-y-4">
              <div>
                <label class="block text-sm font-medium leading-none mb-1">{{ trans('impostazioni.aspetto.colore_title') }}</label>
                <p class="text-sm text-muted-foreground">{{ trans('impostazioni.aspetto.colore_description') }}</p>
              </div>
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <button v-for="c in scelte.colori" :key="c.valore" type="button" @click="form.colore = c.valore"
                  class="flex items-center gap-3 rounded-lg border p-3 text-left transition hover:bg-accent"
                  :class="form.colore === c.valore ? 'ring-2 ring-ring border-transparent' : ''"
                  :aria-pressed="form.colore === c.valore">
                  <span class="size-8 shrink-0 rounded-full border flex items-center justify-center" :style="{ background: c.campione }">
                    <Check v-if="form.colore === c.valore" class="size-4 text-white" />
                  </span>
                  <span class="text-sm font-medium">{{ c.nome }}</span>
                </button>
              </div>
            </div>

            <div class="border rounded-lg p-4 space-y-4">
              <div>
                <label class="block text-sm font-medium leading-none mb-1">{{ trans('impostazioni.aspetto.angoli_title') }}</label>
                <p class="text-sm text-muted-foreground">{{ trans('impostazioni.aspetto.angoli_description') }}</p>
              </div>
              <div class="flex flex-wrap gap-3">
                <button v-for="a in scelte.angoli" :key="a.valore" type="button" @click="form.angoli = a.valore"
                  class="rounded-lg border px-4 py-2 text-sm transition hover:bg-accent"
                  :class="form.angoli === a.valore ? 'ring-2 ring-ring border-transparent font-medium' : ''"
                  :aria-pressed="form.angoli === a.valore">{{ a.nome }}</button>
              </div>
            </div>

            <div class="rounded-lg border p-4 flex flex-wrap items-center gap-3">
              <span class="text-sm text-muted-foreground">Anteprima:</span>
              <Button type="button">Pulsante principale</Button>
              <Button type="button" variant="outline">Secondario</Button>
              <span class="rounded-md bg-accent text-accent-foreground px-3 py-1 text-sm">Voce selezionata</span>
            </div>
          </CardContent>
          <CardFooter class="p-0 pt-6 flex justify-end">
            <Button type="submit" :disabled="form.processing">{{ trans('impostazioni.aspetto.salva') }}</Button>
          </CardFooter>
        </Card>
      </form>
    </div>
  </AppLayout>
</template>
