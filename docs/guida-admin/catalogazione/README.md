# Catalogazione: Dewey e Collocazione

Questa guida spiega i due sistemi principali utilizzati in Pinakes per organizzare i libri: la classificazione Dewey e la collocazione fisica.

## Indice
- [Classificazione Dewey](#classificazione-dewey)
  - [Come Funziona](#come-funziona)
  - [Integrazione in Pinakes](#integrazione-in-pinakes)
- [Collocazione Fisica](#collocazione-fisica)
  - [Struttura della Collocazione](#struttura-della-collocazione)
  - [Come Configurare Scaffali e Mensole](#come-configurare-scaffali-e-mensole)

---

## Classificazione Dewey

La Classificazione Decimale Dewey (CDD) è un sistema standardizzato a livello mondiale per organizzare i libri in base all'argomento.

### Come Funziona

Il sistema divide la conoscenza in 10 classi principali, ognuna rappresentata da un numero di tre cifre:

-   **000-099**: Generalità (informatica, informazione, opere generali)
-   **100-199**: Filosofia e psicologia
-   **200-299**: Religione
-   **300-399**: Scienze sociali
-   **400-499**: Linguaggio
-   **500-599**: Scienze pure (matematica, scienze naturali)
-   **600-699**: Tecnologia (scienze applicate)
-   **700-799**: Arti e ricreazione
-   **800-899**: Letteratura
-   **900-999**: Storia e geografia

Ogni classe è ulteriormente suddivisa per argomenti più specifici.

### Integrazione in Pinakes

Pinakes semplifica l'uso del sistema Dewey:

1.  **Suggerimenti Automatici**: Quando aggiungi o modifichi un libro, il sistema analizza il titolo e la descrizione per suggerirti la classificazione Dewey più appropriata.
2.  **Struttura a Cascata (JSON)**: Invece di dover memorizzare i codici, puoi navigare attraverso le categorie in un menu a discesa. Selezionando una classe principale (es. 800 - Letteratura), ti verranno mostrate le divisioni successive (es. 850 - Letteratura Italiana), e così via, fino a trovare la classificazione più precisa.
3.  **Ricerca per Dewey**: Gli utenti possono cercare libri nel catalogo filtrando per classe Dewey, facilitando la ricerca di opere su argomenti specifici.

---

## Collocazione Fisica

La collocazione è l'indirizzo fisico di un libro all'interno della biblioteca. Ti dice esattamente dove trovarlo.

### Struttura della Collocazione

La collocazione in Pinakes è formata da tre parti:

-   **Scaffale**: Un codice (solitamente una lettera o una sigla) che identifica un mobile o una sezione della biblioteca (es. `A`, `NAR` per Narrativa).
-   **Mensola**: Il numero del ripiano all'interno dello scaffale (es. `1`, `2`).
-   **Posizione**: Un numero progressivo che indica la posizione del libro sulla mensola, assegnato automaticamente dal sistema.

**Esempio**: `A.2.15` significa:
-   Scaffale `A`
-   Mensola `2`
-   Posizione `15`

### Come Configurare Scaffali e Mensole

Puoi definire la struttura fisica della tua biblioteca dal pannello di amministrazione, nella sezione "Collocazione".

1.  **Crea gli Scaffali**: Aggiungi tutti i tuoi scaffali, assegnando a ciascuno un codice unico e un nome descrittivo (es. Codice: `A`, Nome: `Narrativa Straniera`).
2.  **Aggiungi le Mensole**: Per ogni scaffale, definisci il numero di mensole (ripiani) che lo compongono.
3.  **Assegnazione Automatica**: Una volta configurata la struttura, quando aggiungi un nuovo libro e selezioni uno scaffale e una mensola, il sistema assegnerà automaticamente la prima posizione libera.

Puoi riordinare, aggiungere o eliminare scaffali e mensole in qualsiasi momento. Tuttavia, non puoi eliminare uno scaffale se contiene ancora dei libri.
