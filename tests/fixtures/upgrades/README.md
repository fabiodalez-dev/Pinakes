# Schemi storici per i test di aggiornamento

`schema-0.8.9.sql` è la copia integrale di `installer/database/schema.sql` del tag
`v0.8.9`, ottenuta con:

```sh
git show v0.8.9:installer/database/schema.sql
```

La fixture mantiene obbligatorio il test di conservazione delle preferenze
anche nei checkout CI senza tag o cronologia completa. Non adattarla alle nuove
colonne: rappresenta il database precedente alla migrazione 0.9.0.
