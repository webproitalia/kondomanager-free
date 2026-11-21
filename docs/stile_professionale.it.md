# Lo stile «professionale» dell'area di lavoro

*Web Pro Italia, 21/11/2026.*

Lo stile «professionale» è una pelle: colori, spessore delle linee, carattere. Le pagine restano quelle che sono — stessi
elementi negli stessi posti — ma al posto dei riquadri dentro riquadri con bordo, ombra soffice, sfumatura e angoli grandi ci
sono superfici piane, una riga sola e sottile dove serve, angoli piccoli e nessuna ombra decorativa.

Si sceglie in **Impostazioni → Aspetto → Stile**: «Professionale» (predefinito) o «Morbido» (quello di prima). C'è solo con il
menu laterale; con il menu in alto l'aspetto resta quello di prima. Con lo stile professionale gli angoli sono quelli dello
stile, e la scelta «Angoli» è spenta.

## Com'è fatto

- `resources/css/stile-professionale.css`: tutte le regole, e valgono solo sotto `<html data-km-stile="professionale">`.
  Quasi tutto passa da variabili (i grigi di Tailwind, i colori del kit, i raggi, le grandezze e i pesi del carattere); poche
  regole dentro `.km-lavoro` fanno il resto (ombre, sfumature, tabelle, campi, etichette di stato, linguette, cornici doppie).
- `App\Support\Tema::stile()` scrive l'attributo sulla pagina (`resources/views/app.blade.php`); l'impostazione è
  `aspetto.stile` (`App\Settings\AspettoSettings`, migrazione `2026_11_21_100000_add_stile_to_aspetto_settings`).
- Il colore principale resta quello scelto in Aspetto. «Glicine» (`#71639e`) è il viola del riferimento.

## Da dove vengono i valori

Da **Odoo 16 Community**, che usiamo per la contabilità: letti nei suoi fogli di stile e misurati nel browser sull'elenco e
sul modulo delle fatture. Odoo Community è distribuito con licenza LGPL-3; questo programma con licenza AGPL-3. Nel nostro
foglio non c'è codice di Odoo copiato: i valori sono riespressi nelle nostre variabili.

I file di Odoo stanno in `addons/web/static/src/`: `scss/primary_variables.scss` (PV), `scss/bootstrap_overridden.scss` (BO),
`views/list/list_renderer.scss` (LR). «Misura» è lo stile calcolato dal browser (Chromium, 1440×900).

| Che cosa | Valore | Da dove |
|---|---|---|
| Carattere | quello del sistema: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Ubuntu, "Liberation Sans", Arial, sans-serif` | PV `$o-system-fonts` |
| Grandezza del testo | 13 px, interlinea 1,5 | PV `$o-font-size-base`, `$o-line-height-base`; misura `body` |
| Testo piccolo (etichette di stato) | 12 px | misura `.badge` |
| Titoli | ×1,1 ×1,2 ×1,3 ×1,5 ×2 della base (h5…h1); titolo di un modulo 26 px | BO `$h1…$h5-font-size`; misura `h1` |
| Pesi | 400 il testo, 500 il «grassetto» (etichette dei campi, intestazioni, pulsanti), 700 i totali | PV `$o-font-weight-*`, BO `$font-weight-bold`; misura |
| Grigi | 100 `#f8f9fa` · 200 `#e9ecef` · 300 `#dee2e6` · 400 `#ced4da` · 500 `#adb5bd` · 600 `#6c757d` · 700 `#495057` · 800 `#343a40` · 900 `#212529` | PV `$o-gray-100…900` |
| Colore del testo | `#495057` (grigio 700) | PV `$o-main-text-color`; misura `body` |
| Titoli, etichette dei campi, intestazioni di tabella | `#212529` (grigio 900) | PV `$o-main-headings-color`; misura `th`, `.o_form_label` |
| Testo secondario | `#6c757d` (grigio 600) | misura briciola del pannello; PV `$o-main-color-muted` |
| Fondo della pagina | `#f8f9fa`; i fogli sono bianchi | misura `body`, `.o_form_sheet_bg`, `.o_form_sheet` |
| Cornici | 1 px `#dee2e6` (grigio 300) | PV `$o-border-color`; misura pannello di controllo, foglio, menu |
| Angoli | 3 px (`.25rem` con la radice a 12 px); pastiglia per le etichette | PV `$o-border-radius`; misura pulsanti, menu, finestra |
| Ombre | nessuna sulle superfici; `0 1rem 1.1rem rgba(0,0,0,.1)` sui menu a tendina | PV `$o-dropdown-box-shadow`; misura `.dropdown-menu` |
| Tabella: intestazione | fondo `#e9ecef`, testo `#212529` peso 500, alta 44,5 px | LR `--ListRenderer-thead-bg-color`; misura `thead th` |
| Tabella: righe | separate da 1 px `#e9ecef`; una riga è alta 34 px (6 px sopra e sotto) | BO `$table-border-color`; misura `tr.o_data_row`, `td` |
| Tabella: passaggio del mouse | velo `rgba(0,0,0,.055)` sopra la cella | BO `$table-hover-bg-factor`; misura al passaggio |
| Tabella: piede | fondo `#eee`, peso 700 | PV `$o-list-footer-bg-color`, `$o-list-footer-font-weight` |
| Colore principale | `#71639e` | PV `$o-community-color`; misura `.btn-primary` |
| Collegamenti e testo dei pulsanti secondari | il colore principale scurito del 5% (`#66598f`) | PV `$o-main-link-color`; misura `a`, `.btn-secondary` |
| Pulsante principale | fondo del colore principale, testo bianco, peso 500, angoli 3 px | misura `.btn-primary` |
| Pulsante secondario | fondo bianco, testo del colore dei collegamenti; al passaggio `#f8f9fa` | PV `$o-btn-secondary-*`; misura `.btn-secondary` |
| Etichette di stato | pastiglia piena, testo bianco, 12 px peso 500: verde `#28a745`, rosso `#dc3545`, azzurro `#17a2b8`, giallo `#ffac00` (testo scuro) | PV `$o-success`, `$o-danger`, `$o-info`, `$o-warning`; misura `.badge.rounded-pill` |
| Campi | nessuna cornice, una riga sotto di 1 px; mentre si scrive la riga ha il colore principale | misura `.o_input`, anche a fuoco |
| Casella di spunta | 13 px, cornice 1 px `rgba(0,0,0,.25)`, angoli 3 px | misura `.form-check-input` |
| Linguette | una riga sotto `#dee2e6`; la linguetta accesa ha cornice `#dee2e6` su tre lati e fondo del foglio; le altre hanno il colore dei collegamenti | misura `.nav-tabs`, `.nav-link` |
| Menu a tendina | fondo bianco, cornice `#dee2e6`, angoli 3 px; voce al passaggio `rgba(0,0,0,.08)` | BO `$dropdown-*`; misura `.dropdown-menu`, `.dropdown-item` |
| Finestre | cornice 1 px `rgba(0,0,0,.2)`, angoli 3 px, nessuna ombra, velo `rgba(0,0,0,.5)` | misura `.modal-content`, `.modal` |

## Dove lo stile si allontana dal riferimento

- **I pulsanti non sono in maiuscolo.** Nel riferimento lo sono quelli del pannello di controllo; i nostri hanno testi più
  lunghi e un'icona.
- **Le righe degli elenchi sono più alte.** Le nostre righe hanno spesso due linee di testo e un'icona; quelle di una linea
  sola sono alte 36 px (34 nel riferimento).
- **I testi di più righe hanno la cornice intera**, sottile: con la sola riga sotto non si capirebbe dove si scrive.
- **Le intestazioni delle tabelle non hanno le righe verticali** fra le colonne.
- **Restano tratteggiati** i riquadri «non c'è ancora niente» e le aree dove si trascina un file.

## Le cornici

Come nel riferimento, la pagina è un foglio bianco solo: attorno al titolo, alle spiegazioni, all'elenco con i suoi filtri e
ai gruppi di un modulo non c'è nessun riquadro. I riquadri di contenuto sono sezioni del foglio, separate da una riga
sottile sopra e dal loro titoletto; la tabella sta direttamente sulla pagina, con l'intestazione grigia e le righe da 1 px;
l'area di lavoro arriva a 16 px dai bordi.

Di cornici ne resta al più una per contenuto, e solo dove separa cose diverse: le tessere con un numero (affiancate a tre o
più), gli avvisi tinti di un colore, le aree dove si trascina un file, gli stati vuoti, le voci che si cliccano (le tessere
delle impostazioni), ciò che resta agganciato in cima mentre si scorre.

Nel tema scuro alcune pagine hanno il fondo bianco scritto a mano, senza la variante scura: lì il bianco resta, com'era.
