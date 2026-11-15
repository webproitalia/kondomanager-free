# Registri contabili — la banca, i fondi e i due numeri dello stesso conto

> **Che cosa è questa copia.** Il codice cita `docs/registri_contabili.md` una trentina di volte, ma il file non è nel
> repository: i documenti interni di KondoManager vivono in un repository privato (in `.gitignore` la regola invertita
> `docs/*`; il commit `b0934d0c` li ha tolti dall'indice). Questa copia l'ha scritta Web Pro Italia il 15/11/2026, leggendo il
> codice, e contiene **solo** ciò che serve a capire perché lo stato patrimoniale e il registro di contabilità danno due
> numeri diversi per lo stesso conto corrente quando si accantona in un fondo (le decisioni D11 e D15).
>
> Le altre parti citate dal codice — §2, §2-ter, §6, §7-quinquies, §10.1.2, §10.4, D5, D9, D10, D14, D16–D21 — **non sono
> ricostruite qui**: dove il codice le cita, il commento del punto citato resta la fonte.

## 1. Il fatto

Il condominio ha € 10.000,00 sul conto corrente e ne accantona € 2.000,00 nel «Fondo lavori» con un giroconto.

| Dove si guarda                                         | Il conto corrente |
|--------------------------------------------------------|------------------:|
| Stato patrimoniale, riga della banca                   |      € 8.000,00   |
| Mastrino del conto della banca                         |      € 8.000,00   |
| Stato patrimoniale, riga del fondo                     |      € 2.000,00   |
| Registro di contabilità (Prima nota), saldo della banca|     € 10.000,00   |
| Estratto conto della banca                             |     € 10.000,00   |

Chi confronta lo stato patrimoniale con il registro, o con l'estratto conto, vede € 2.000,00 di differenza sulla banca. Non è
un errore, e il calcolo non va cambiato: le due cifre rispondono a due domande diverse.

## 2. Perché tutte e due le cifre sono giuste

**Il fondo è una partizione dell'unico conto corrente (D15).** Un fondo non è un altro conto in banca: è una parte del conto
corrente messa da parte per una destinazione. Nel programma ogni cassa, fondo compreso, ha un conto contabile di liquidità
(`CreateCassaAccountAction`, `SaldoCassaService`), e il giroconto che accantona scrive

    DARE  conto del fondo   € 2.000,00
    AVERE conto della banca € 2.000,00

(`RegistraGirocontoAction`). La liquidità del condominio non cambia: si sposta da «libera» a «accantonata».

**Lo stato patrimoniale e il mastrino leggono i conti.** Sono la fotografia di che cosa c'è su ogni conto contabile a una data
(`StatoPatrimonialeService`, `RegistroContabilitaService::mastrino`). Dopo il giroconto il conto della banca vale € 8.000,00:
è la parte **libera** del conto corrente. I € 2.000,00 stanno nella riga del fondo, fra la liquidità, e il totale della
liquidità resta € 10.000,00.

**Il registro di contabilità conta il denaro che entra e che esce davvero** (art. 1130, comma 1, n. 7 c.c.). Una riga entra nel
registro solo se è su una cassa di liquidità reale (`banca`, `contanti`); `fondo` e `virtuale` sono partizioni, non denaro che
si muove. Per questo resta fuori anche la riga della banca di un giroconto verso un fondo: non è un'entrata né un'uscita, è la
banca che parla con sé stessa. Si riconosce guardando le righe sorelle della stessa scrittura, non il tipo di movimento
(`RegistroContabilitaService::queryBase`). Nel registro, come nell'estratto conto della banca, il conto resta **intero**:
€ 10.000,00.

Lo stesso vale al contrario: un giroconto dal fondo alla banca alza la banca dello stato patrimoniale e non è un'entrata del
registro.

## 3. Dove si vede la differenza (D11)

- **«Liquidità: libera e accantonata»**, nella pagina dello stato patrimoniale e nella sua stampa (`LiquiditaVincolataService`):
  la liquidità divisa in libera (banca e contanti), nei fondi e il resto. È la vista d'insieme.
- **Sotto la riga di ogni banca**, dal 15/11/2026 (`StatoPatrimonialePaginaService::accantonatoNeiFondi`), a schermo e in
  stampa:

  > Sul conto ci sono € 10.000,00, di cui € 2.000,00 accantonati nel fondo «Fondo lavori»: questa riga è la parte libera, e il
  > fondo ha la sua riga fra la liquidità. L'estratto conto della banca e il registro di contabilità, che non conta un
  > accantonamento come uscita, mostrano il conto intero.

  La cifra si calcola con la stessa regola del registro — le righe della banca nelle scritture che hanno una riga sorella su un
  fondo o su una cassa virtuale — e con il taglio della fotografia (la data dello stato patrimoniale), quindi la differenza che
  la riga dichiara è esattamente quella che il registro non conta. Con più fondi li nomina tutti, ciascuno con la sua cifra; se
  dai fondi è tornato sulla banca più di quanto ci sia andato, lo dice al contrario. I dati sono nella chiave `accantonato` di
  ogni voce di liquidità (`totale`, `fondi`, `sul_conto`, `nota`), null sulle altre.

## 4. Che cosa questa riga non spiega

- **Le altre differenze fra registro e stato patrimoniale.** Il registro taglia per esercizio, la fotografia per data; un saldo
  di apertura non ancora portato a giornale sta nella fotografia e non nel registro. Sono i controlli R2 e R6 della pagina a
  dirle, non questa riga.
- **Il mastrino del conto della banca** resta com'è, senza la riga: è il dettaglio della riga dello stato patrimoniale, che ora
  la spiega.
- **Un fondo alimentato da due banche.** Il fondo è una partizione «del» conto corrente, ma il programma non lega un fondo a una
  banca: la riga dice, per ogni banca, quanto è passato da quella banca ai fondi con i giroconti, e niente di più.

## 5. Per chi controlla con l'estratto conto

    banca nello stato patrimoniale  +  accantonato da quella banca nei fondi  =  saldo dell'estratto conto  =  saldo nel registro

(al netto delle differenze del §4). La seconda cifra è quella scritta sotto la riga della banca.
