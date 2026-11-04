# Historical schemas for upgrade tests

`schema-0.8.9.sql` is a verbatim copy of `installer/database/schema.sql` at the
`v0.8.9` tag, taken with:

```sh
git show v0.8.9:installer/database/schema.sql
```

The fixture keeps the preference-preservation upgrade test mandatory even in CI
checkouts without tags or full history. Do not adapt it to new columns: it
stands for the database before the 0.9.0 migration.
