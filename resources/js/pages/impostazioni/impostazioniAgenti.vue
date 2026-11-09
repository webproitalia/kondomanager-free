<script setup lang="ts">
// Impostazioni → Agenti AI (Web Pro Italia, 09/11/2026): le chiavi con cui un assistente usa il gestionale.
// La chiave nuova arriva come prop nella risposta alla creazione e si mostra una volta sola.
import { computed, ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import PageHeaderGuide from '@/components/PageHeaderGuide.vue'
import Alert from '@/components/Alert.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Copy, Check, KeyRound } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n'
import type { BreadcrumbItem } from '@/types'

interface Chiave {
  id: number; nome: string; inizio: string; utente: { nome: string; email: string } | null
  sola_lettura: boolean; creata: string | null; ultimo_uso: string | null; scade: string | null
  revocata: string | null; attiva: boolean
}
interface Azione { quando: string; chiave: string | null; metodo: string; rotta: string | null; percorso: string; stato: number; prova: boolean }
interface Props {
  chiavi: Chiave[]
  azioni: Azione[]
  utenti: { id: number; nome: string; email: string }[]
  mcp: { indirizzo: string | null; kondo: string }
  chiaveNuova: string | null
}
const props = defineProps<Props>()
const page = usePage()
const flashMessage = computed(() => (page.props as any).flash?.message)

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('impostazioni.label.settings'), href: '/impostazioni' },
  { title: trans('agenti.title'), href: '/impostazioni/agenti' },
])

const form = useForm({
  nome: '',
  user_id: props.utenti[0]?.id ?? null as number | null,
  sola_lettura: true,
  scade_giorni: null as number | null,
})

const submit = () => form.post('/impostazioni/agenti', { preserveScroll: true, onSuccess: () => form.reset('nome') })

const copiato = ref('')
const copia = async (testo: string, chi: string) => {
  try { await navigator.clipboard.writeText(testo); copiato.value = chi; setTimeout(() => (copiato.value = ''), 2000) } catch { /* il testo resta selezionabile */ }
}

// Il link con la chiave dentro, da incollare nei Connettori di Claude (come il link MCP di Maeve).
const linkClaude = computed(() => props.mcp.indirizzo && props.chiaveNuova ? `${props.mcp.indirizzo}/${props.chiaveNuova}` : '')

const comando = computed(() => {
  const indirizzo = props.mcp.indirizzo ?? `${props.mcp.kondo}/mcp`
  return `claude mcp add --transport http kondo ${indirizzo} --header "Authorization: Bearer ${props.chiaveNuova ?? 'kag_…'}"`
})

const revoca = (c: Chiave) => {
  if (!confirm(trans('agenti.revoca_conferma', { nome: c.nome }))) return
  router.delete(`/impostazioni/agenti/${c.id}`, { preserveScroll: true })
}

const lingua = computed(() => String((page.props as any).locale || 'it'))
const data = (iso: string | null) => iso ? new Date(iso).toLocaleString(lingua.value, { dateStyle: 'short', timeStyle: 'short' }) : '—'
const stato = (c: Chiave) => c.revocata ? trans('agenti.revocata_stato') : (c.attiva ? trans('agenti.attiva') : trans('agenti.scaduta'))
const campo = 'border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] dark:bg-input/30'
</script>

<template>
  <AppLayout :breadcrumbs="[]">
    <Head :title="trans('agenti.title')" />

    <div class="px-4 py-6 space-y-6">
      <PageHeaderGuide
        :page-title="trans('agenti.title')"
        :page-subtitle="trans('agenti.description')"
        :guides="[]"
        :breadcrumbs="breadcrumbs"
        back-url="/impostazioni"
        :back-text="trans('impostazioni.label.settings')"
        :video-url="null"
      />

      <div v-if="flashMessage" class="py-2">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div v-if="chiaveNuova" class="rounded-lg border-2 border-primary p-4 space-y-3">
        <div class="flex items-center gap-2 font-medium"><KeyRound class="size-4" /> {{ trans('agenti.creata_titolo') }}</div>
        <p class="text-sm text-muted-foreground">{{ trans('agenti.creata_testo') }}</p>
        <template v-if="linkClaude">
          <div class="text-sm font-medium pt-1">{{ trans('agenti.link_titolo') }}</div>
          <p class="text-sm text-muted-foreground">{{ trans('agenti.link_testo') }}</p>
          <div class="flex gap-2">
            <code class="flex-1 rounded-md bg-muted px-3 py-2 text-sm break-all select-all">{{ linkClaude }}</code>
            <Button type="button" @click="copia(linkClaude, 'link')">
              <Check v-if="copiato === 'link'" class="size-4" /><Copy v-else class="size-4" />
              {{ copiato === 'link' ? trans('agenti.copiata') : trans('agenti.copia') }}
            </Button>
          </div>
          <p class="text-xs text-muted-foreground">{{ trans('agenti.link_attenzione') }}</p>
          <div class="text-sm font-medium pt-2">{{ trans('agenti.chiave_sola') }}</div>
        </template>
        <div class="flex gap-2">
          <code class="flex-1 rounded-md bg-muted px-3 py-2 text-sm break-all select-all">{{ chiaveNuova }}</code>
          <Button type="button" variant="outline" @click="copia(chiaveNuova!, 'chiave')">
            <Check v-if="copiato === 'chiave'" class="size-4" /><Copy v-else class="size-4" />
            {{ copiato === 'chiave' ? trans('agenti.copiata') : trans('agenti.copia') }}
          </Button>
        </div>
      </div>

      <form @submit.prevent="submit">
        <Card class="border shadow-none p-4">
          <CardContent class="p-0 space-y-4">
            <div class="font-medium">{{ trans('agenti.nuova') }}</div>
            <div class="grid gap-4 md:grid-cols-2">
              <div class="space-y-1">
                <label class="block text-sm font-medium">{{ trans('agenti.nome') }}</label>
                <Input v-model="form.nome" maxlength="100" required />
                <p class="text-xs text-muted-foreground">{{ trans('agenti.nome_aiuto') }}</p>
                <p v-if="form.errors.nome" class="text-xs text-destructive">{{ form.errors.nome }}</p>
              </div>
              <div class="space-y-1">
                <label class="block text-sm font-medium">{{ trans('agenti.utente') }}</label>
                <select v-model="form.user_id" :class="campo" required>
                  <option v-for="u in utenti" :key="u.id" :value="u.id">{{ u.nome }} · {{ u.email }}</option>
                </select>
                <p class="text-xs text-muted-foreground">{{ trans('agenti.utente_aiuto') }}</p>
                <p v-if="form.errors.user_id" class="text-xs text-destructive">{{ form.errors.user_id }}</p>
              </div>
              <div class="space-y-1">
                <label class="block text-sm font-medium">{{ trans('agenti.permesso') }}</label>
                <div class="flex flex-wrap gap-2">
                  <button type="button" class="rounded-lg border px-3 py-2 text-sm transition hover:bg-accent"
                    :class="form.sola_lettura ? 'ring-2 ring-ring border-transparent font-medium' : ''"
                    :aria-pressed="form.sola_lettura" @click="form.sola_lettura = true">{{ trans('agenti.sola_lettura') }}</button>
                  <button type="button" class="rounded-lg border px-3 py-2 text-sm transition hover:bg-accent"
                    :class="!form.sola_lettura ? 'ring-2 ring-ring border-transparent font-medium' : ''"
                    :aria-pressed="!form.sola_lettura" @click="form.sola_lettura = false">{{ trans('agenti.scrittura') }}</button>
                </div>
                <p v-if="!form.sola_lettura" class="text-xs text-muted-foreground">{{ trans('agenti.scrittura_aiuto') }}</p>
              </div>
              <div class="space-y-1">
                <label class="block text-sm font-medium">{{ trans('agenti.scadenza') }}</label>
                <select v-model="form.scade_giorni" :class="campo">
                  <option :value="null">{{ trans('agenti.mai') }}</option>
                  <option v-for="g in [30, 90, 365]" :key="g" :value="g">{{ trans('agenti.giorni', { n: String(g) }) }}</option>
                </select>
              </div>
            </div>
          </CardContent>
          <CardFooter class="p-0 pt-4 flex justify-end">
            <Button type="submit" :disabled="form.processing || !form.nome || !form.user_id">{{ trans('agenti.crea') }}</Button>
          </CardFooter>
        </Card>
      </form>

      <Card class="border shadow-none p-4">
        <CardContent class="p-0 space-y-2">
          <div class="font-medium">{{ trans('agenti.collega_titolo') }}</div>
          <template v-if="mcp.indirizzo">
            <p class="text-sm text-muted-foreground">{{ trans('agenti.collega_testo') }}</p>
            <div class="flex gap-2">
              <code class="flex-1 rounded-md bg-muted px-3 py-2 text-xs break-all select-all">{{ comando }}</code>
              <Button type="button" variant="outline" @click="copia(comando, 'comando')">
                <Check v-if="copiato === 'comando'" class="size-4" /><Copy v-else class="size-4" />
              </Button>
            </div>
          </template>
          <p v-else class="text-sm text-muted-foreground">{{ trans('agenti.mcp_assente') }}</p>
        </CardContent>
      </Card>

      <Card class="border shadow-none p-4">
        <CardContent class="p-0 space-y-3">
          <div class="font-medium">{{ trans('agenti.chiavi') }}</div>
          <p v-if="!chiavi.length" class="text-sm text-muted-foreground">{{ trans('agenti.nessuna') }}</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-muted-foreground">
                <tr class="border-b">
                  <th class="py-2 pr-3 font-medium">{{ trans('agenti.colonna_nome') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ trans('agenti.colonna_utente') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ trans('agenti.colonna_permesso') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ trans('agenti.colonna_uso') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ trans('agenti.colonna_scade') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ trans('agenti.colonna_stato') }}</th>
                  <th class="py-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in chiavi" :key="c.id" class="border-b last:border-0" :class="!c.attiva ? 'opacity-60' : ''">
                  <td class="py-2 pr-3"><div class="font-medium">{{ c.nome }}</div><code class="text-xs text-muted-foreground">{{ c.inizio }}…</code></td>
                  <td class="py-2 pr-3">{{ c.utente?.nome ?? '—' }}</td>
                  <td class="py-2 pr-3">{{ c.sola_lettura ? trans('agenti.sola_lettura') : trans('agenti.scrittura') }}</td>
                  <td class="py-2 pr-3">{{ c.ultimo_uso ? data(c.ultimo_uso) : trans('agenti.mai_usata') }}</td>
                  <td class="py-2 pr-3">{{ c.scade ? data(c.scade) : trans('agenti.mai') }}</td>
                  <td class="py-2 pr-3"><Badge :variant="c.attiva ? 'default' : 'secondary'">{{ stato(c) }}</Badge></td>
                  <td class="py-2 text-right"><Button v-if="c.attiva" type="button" size="sm" variant="outline" @click="revoca(c)">{{ trans('agenti.revoca') }}</Button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card class="border shadow-none p-4">
        <CardContent class="p-0 space-y-3">
          <div class="font-medium">{{ trans('agenti.registro') }}</div>
          <p v-if="!azioni.length" class="text-sm text-muted-foreground">{{ trans('agenti.registro_vuoto') }}</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-muted-foreground">
                <tr class="border-b">
                  <th class="py-2 pr-3 font-medium">{{ trans('agenti.colonna_quando') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ trans('agenti.colonna_chiave') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ trans('agenti.colonna_funzione') }}</th>
                  <th class="py-2 font-medium">{{ trans('agenti.colonna_esito') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(a, i) in azioni" :key="i" class="border-b last:border-0">
                  <td class="py-2 pr-3 whitespace-nowrap">{{ data(a.quando) }}</td>
                  <td class="py-2 pr-3">{{ a.chiave ?? '—' }}</td>
                  <td class="py-2 pr-3"><code class="text-xs">{{ a.metodo }} {{ a.rotta ?? a.percorso }}</code></td>
                  <td class="py-2">
                    <Badge :variant="a.stato < 400 ? 'outline' : 'destructive'">{{ a.stato }}</Badge>
                    <Badge v-if="a.prova" variant="secondary" class="ml-1">{{ trans('agenti.prova') }}</Badge>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
