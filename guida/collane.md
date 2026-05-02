# Collane, Cicli e Stagioni

Le **collane** in Pinakes non sono semplici etichette: supportano una
gerarchia completa per rappresentare cicli, stagioni, spin-off e qualsiasi
struttura multi-livello presente nel mondo editoriale, fumettistico e
audiovisivo.

> Gerarchia collane introdotta in v0.5.9.6 — [PR #114](https://github.com/fabiodalez-dev/Pinakes/pull/114) / [PR #115](https://github.com/fabiodalez-dev/Pinakes/pull/115).

## Struttura gerarchica

Le collane sono organizzate in un albero self-referencing: ogni collana
può avere una **collana padre** e un numero illimitato di **collane
figlie**. Il tipo di relazione è specificato dal campo **Tipo**:

| Tipo | Quando usarlo | Esempio |
|------|---------------|---------|
| `serie` | Sequenza principale di volumi | "Il Signore degli Anelli" |
| `ciclo` | Arco narrativo all'interno di una serie | "Ciclo della Fondazione" |
| `stagione` | Stagione di una serie TV o raccolta periodica | "Stagione 1 — Breaking Bad" |
| `spin_off` | Opera derivata da una serie principale | "Better Call Saul" da "Breaking Bad" |

Un libro appartiene a una sola collana foglia (quella più specifica);
la gerarchia verso la radice si ricostruisce automaticamente tramite
i link padre-figlio.

## Gestione collane

### Creare una collana padre (serie principale)

1. Vai in **Amministrazione → Collane → Nuova collana**.
2. Compila il **Nome** (es. "Harry Potter").
3. Lascia **Collana padre** vuoto — questa è la radice.
4. Scegli **Tipo** → `serie`.
5. Aggiungi una **Descrizione** opzionale.
6. Salva.

### Aggiungere un ciclo o una stagione

1. Vai in **Amministrazione → Collane → Nuova collane**.
2. Compila il **Nome** (es. "Harry Potter – Anni di Hogwarts").
3. Seleziona la **Collana padre** → "Harry Potter" (autocomplete).
4. Scegli **Tipo** → `ciclo`.
5. Salva.

### Creare uno spin-off

Stessa procedura: crea una nuova collana, seleziona la serie di
provenienza come padre, scegli **Tipo** → `spin_off`.

## Assegnazione di un libro a una collana

Nel form libro (sezione **Collana / Discografia**):

1. Inizia a digitare il nome della collana nel campo autocomplete.
2. Scegli la collana più specifica (es. la stagione o il ciclo, non la
   serie principale).
3. Compila il campo **Numero nella serie** (es. `3` per il terzo volume).
4. Salva.

> **Nota** — La breadcrumb gerarchica (serie → ciclo → stagione) viene
> generata automaticamente nella pagina pubblica e nelle schede admin.
> Non è necessario assegnare manualmente tutti i livelli.

## Pagina pubblica di una collana

Ogni collana ha una pagina pubblica raggiungibile da
`/collana/{id}-{slug}`. La pagina mostra:

- Il titolo e la descrizione della collana.
- L'albero delle collane figlie (cicli, stagioni, spin-off).
- La lista dei libri ordinati per `numero_serie`.
- Il link alla collana padre (se presente).

## Unione e ridenominazione

Dalla lista collane (`/admin/collane`) sono disponibili le azioni:

- **Rinomina** — modifica il nome senza perdere i libri associati.
- **Unisci** — sposta tutti i libri di una collana in un'altra e
  cancella quella vuota. Utile per normalizzare duplicati
  (es. "Star Wars" e "Star wars").
- **Elimina** — disponibile solo se la collana non ha libri né
  collane figlie.

## Prevenzione dei cicli

Il sistema impedisce la creazione di cicli nella gerarchia (es. A →
B → C → A). Prima di salvare una relazione padre-figlio, viene eseguita
una verifica sulla catena degli antenati (`ancestor-chain walk`).
Se viene rilevato un ciclo, l'operazione viene rifiutata con un
messaggio di errore esplicito.

## Export e import

- Il campo `collana` è incluso nell'export CSV/TSV come nome della
  collana foglia.
- Il campo `numero_serie` è incluso come colonna separata.
- Nell'import CSV/TSV, il valore di `collana` viene abbinato per nome
  esatto (case-insensitive); se non esiste viene creata automaticamente
  come collana autonoma senza padre.
- La gerarchia non viene importata tramite CSV — usa l'interfaccia admin
  per strutturare l'albero.

## Collane e dischi (Discografia)

Quando il tipo media di un libro è `disco`, il campo **Collana** viene
rinominato **Discografia**. Le funzionalità sono identiche: puoi
strutturare discografie complesse con cicli e stagioni esattamente come
fai con le collane letterarie.
