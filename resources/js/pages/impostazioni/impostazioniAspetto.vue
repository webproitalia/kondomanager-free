<script setup lang="ts">
// Impostazioni → Aspetto (Web Pro Italia, 09/11/2026): colore principale e angoli dell'installazione.
// L'anteprima è immediata (stesse variabili CSS del layout); salvando, la pagina si ricarica e l'aspetto vale ovunque.
// Dal 18/11/2026 anche dove sta il menu: laterale (predefinito) o in alto, com'era. Il menu cambia al salvataggio.
// E dove si aprono le schede e i moduli: di lato (predefinito), senza lasciare la pagina, o a pagina intera. Solo con il menu laterale.
// Dal 21/11/2026 lo stile dell'area di lavoro: professionale (predefinito: superfici piane, righe sottili, il carattere del
// sistema) o morbido (quello di prima). Si vede subito, mentre si sceglie; resta dopo il salvataggio. Solo con il menu laterale.
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
  aspetto: { colore: string; angoli: string; menu: string; schede: string; stile: string }
  scelte: { colori: Scelta[]; angoli: Scelta[]; menu: Scelta[]; schede: Scelta[]; stile: Scelta[]; anteprime: Record<string, Record<string, string>> }
}
const props = defineProps<Props>()
const page = usePage()
const flashMessage = computed(() => (page.props as any).flash?.message)

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('impostazioni.label.settings'), href: '/impostazioni' },
  { title: trans('impostazioni.header.aspetto_title'), href: '/impostazioni/aspetto' },
])

const form = useForm({ colore: props.aspetto.colore, angoli: props.aspetto.angoli, menu: props.aspetto.menu, schede: props.aspetto.schede, stile: props.aspetto.stile })

// Lo stile si prova subito: la pagina prende quello scelto, e uscendo senza salvare torna quello salvato.
const stileSalvato = typeof document === 'undefined' ? undefined : document.documentElement.dataset.kmStile
const professionale = computed(() => form.stile === 'professionale' && form.menu === 'laterale')
watch(professionale, (si) => {
  // Con il menu in alto (che cambia solo al salvataggio) lo stile resta quello che c'è.
  if (document.documentElement.dataset.kmMenu === 'laterale') {
    document.documentElement.dataset.kmStile = si ? 'professionale' : 'morbido'
  }
})
onBeforeUnmount(() => {
  if (stileSalvato !== undefined) {
    document.documentElement.dataset.kmStile = stileSalvato
  }
})

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
                  class="rounded-lg border px-4 py-2 text-sm transition hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
                  :class="form.angoli === a.valore && ! professionale ? 'ring-2 ring-ring border-transparent font-medium' : ''"
                  :disabled="professionale"
                  :aria-pressed="form.angoli === a.valore">{{ a.nome }}</button>
              </div>
              <p v-if="professionale" class="text-xs text-muted-foreground" data-angoli-con-stile>{{ trans('impostazioni.aspetto.angoli_con_stile') }}</p>
            </div>

            <div class="border rounded-lg p-4 space-y-4">
              <div>
                <label class="block text-sm font-medium leading-none mb-1">{{ trans('impostazioni.aspetto.menu_title') }}</label>
                <p class="text-sm text-muted-foreground">{{ trans('impostazioni.aspetto.menu_description') }}</p>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <button v-for="m in scelte.menu" :key="m.valore" type="button" @click="form.menu = m.valore"
                  class="flex items-center gap-4 rounded-lg border p-3 text-left transition hover:bg-accent"
                  :class="form.menu === m.valore ? 'ring-2 ring-ring border-transparent' : ''"
                  :aria-pressed="form.menu === m.valore" :data-menu-scelta="m.valore">
                  <!-- Un disegnino dell'impalcatura: il menu scuro a sinistra, oppure la barra in alto -->
                  <span v-if="m.valore === 'laterale'" class="flex h-14 w-24 shrink-0 overflow-hidden rounded-md border bg-muted" aria-hidden="true">
                    <span class="flex w-6 flex-col gap-1 bg-[var(--km-menu-fondo)] p-1">
                      <span class="h-1.5 rounded-sm bg-[var(--km-menu-attiva)]" />
                      <span class="h-1.5 rounded-sm bg-white/25" />
                      <span class="h-1.5 rounded-sm bg-white/25" />
                      <span class="h-1.5 rounded-sm bg-white/25" />
                    </span>
                    <span class="flex flex-1 flex-col">
                      <span class="h-2.5 border-b bg-background" />
                      <span class="m-1.5 flex-1 rounded-sm border bg-background" />
                    </span>
                  </span>
                  <span v-else class="flex h-14 w-24 shrink-0 flex-col overflow-hidden rounded-md border bg-background" aria-hidden="true">
                    <span class="flex h-3.5 items-center gap-1 border-b px-1.5">
                      <span class="size-1.5 rounded-sm bg-primary" />
                      <span class="h-1 w-3 rounded-sm bg-foreground/25" />
                      <span class="h-1 w-3 rounded-sm bg-foreground/25" />
                      <span class="h-1 w-3 rounded-sm bg-foreground/25" />
                    </span>
                    <span class="m-1.5 flex-1 rounded-sm border" />
                  </span>
                  <span class="min-w-0">
                    <span class="flex items-center gap-1.5 text-sm font-medium">
                      {{ m.nome }}
                      <Check v-if="form.menu === m.valore" class="size-4 text-primary" />
                    </span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">{{ trans(`impostazioni.aspetto.menu_${m.valore}_description`) }}</span>
                  </span>
                </button>
              </div>
            </div>

            <div class="border rounded-lg p-4 space-y-4">
              <div>
                <label class="block text-sm font-medium leading-none mb-1">{{ trans('impostazioni.aspetto.schede_title') }}</label>
                <p class="text-sm text-muted-foreground">{{ trans('impostazioni.aspetto.schede_description') }}</p>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <button v-for="sc in scelte.schede" :key="sc.valore" type="button" @click="form.schede = sc.valore"
                  class="flex items-center gap-4 rounded-lg border p-3 text-left transition hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
                  :class="form.schede === sc.valore && form.menu === 'laterale' ? 'ring-2 ring-ring border-transparent' : ''"
                  :disabled="form.menu !== 'laterale'"
                  :aria-pressed="form.schede === sc.valore" :data-schede-scelta="sc.valore">
                  <!-- Un disegnino: la scheda che entra da destra sopra la pagina, oppure la pagina intera -->
                  <span v-if="sc.valore === 'lato'" class="relative flex h-14 w-24 shrink-0 overflow-hidden rounded-md border bg-muted" aria-hidden="true">
                    <span class="w-4 bg-[var(--km-menu-fondo)]" />
                    <span class="m-1.5 flex-1 rounded-sm border bg-background opacity-60" />
                    <span class="absolute inset-y-0 right-0 flex w-[58%] flex-col gap-1 border-l bg-background p-1.5 shadow-[-6px_0_10px_-4px_rgb(0_0_0/0.25)]">
                      <span class="h-1.5 w-2/3 rounded-sm bg-primary" />
                      <span class="h-1 rounded-sm bg-foreground/20" />
                      <span class="h-1 w-4/5 rounded-sm bg-foreground/20" />
                    </span>
                  </span>
                  <span v-else class="flex h-14 w-24 shrink-0 overflow-hidden rounded-md border bg-muted" aria-hidden="true">
                    <span class="w-4 bg-[var(--km-menu-fondo)]" />
                    <span class="m-1.5 flex flex-1 flex-col gap-1 rounded-sm border bg-background p-1.5">
                      <span class="h-1.5 w-1/2 rounded-sm bg-primary" />
                      <span class="h-1 rounded-sm bg-foreground/20" />
                      <span class="h-1 w-4/5 rounded-sm bg-foreground/20" />
                    </span>
                  </span>
                  <span class="min-w-0">
                    <span class="flex items-center gap-1.5 text-sm font-medium">
                      {{ sc.nome }}
                      <Check v-if="form.schede === sc.valore && form.menu === 'laterale'" class="size-4 text-primary" />
                    </span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">{{ trans(`impostazioni.aspetto.schede_${sc.valore}_description`) }}</span>
                  </span>
                </button>
              </div>
              <p v-if="form.menu !== 'laterale'" class="text-xs text-muted-foreground">{{ trans('impostazioni.aspetto.schede_solo_laterale') }}</p>
            </div>

            <div class="border rounded-lg p-4 space-y-4">
              <div>
                <label class="block text-sm font-medium leading-none mb-1">{{ trans('impostazioni.aspetto.stile_title') }}</label>
                <p class="text-sm text-muted-foreground">{{ trans('impostazioni.aspetto.stile_description') }}</p>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <button v-for="st in scelte.stile" :key="st.valore" type="button" @click="form.stile = st.valore"
                  class="flex items-center gap-4 rounded-lg border p-3 text-left transition hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
                  :class="form.stile === st.valore && form.menu === 'laterale' ? 'ring-2 ring-ring border-transparent' : ''"
                  :disabled="form.menu !== 'laterale'"
                  :aria-pressed="form.stile === st.valore" :data-stile-scelta="st.valore">
                  <!-- Un disegnino: un foglio piano con le righe sottili, oppure riquadri arrotondati con l'ombra. I suoi tratti non seguono lo stile della pagina. -->
                  <span v-if="st.valore === 'professionale'" class="flex h-14 w-24 shrink-0 flex-col overflow-hidden border border-[#dee2e6] bg-white" style="border-radius: 2px" aria-hidden="true">
                    <span class="h-3 bg-[#e9ecef]" />
                    <span class="h-px bg-[#dee2e6]" />
                    <span class="flex flex-1 flex-col justify-around px-1.5">
                      <span class="h-px bg-[#dee2e6]" />
                      <span class="h-px bg-[#dee2e6]" />
                      <span class="h-px bg-[#dee2e6]" />
                    </span>
                  </span>
                  <span v-else class="flex h-14 w-24 shrink-0 items-center justify-center bg-[#eef1f5] p-1.5" style="border-radius: 10px" aria-hidden="true">
                    <span class="flex h-full w-full flex-col gap-1 border border-[#e2e8f0] bg-white p-1.5" style="border-radius: 8px; box-shadow: 0 3px 8px rgb(15 23 42 / 0.18)">
                      <span class="h-1.5 w-2/3 bg-[#cbd5e1]" style="border-radius: 999px" />
                      <span class="flex-1 border border-[#e2e8f0]" style="border-radius: 5px" />
                    </span>
                  </span>
                  <span class="min-w-0">
                    <span class="flex items-center gap-2 text-sm font-medium">
                      {{ st.nome }}
                      <Check v-if="form.stile === st.valore && form.menu === 'laterale'" class="size-4 text-primary" />
                    </span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">{{ trans(`impostazioni.aspetto.stile_${st.valore}_description`) }}</span>
                  </span>
                </button>
              </div>
              <p v-if="form.menu !== 'laterale'" class="text-xs text-muted-foreground">{{ trans('impostazioni.aspetto.stile_solo_laterale') }}</p>
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
