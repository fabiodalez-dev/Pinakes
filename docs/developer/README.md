# 💻 Documentazione Developer

Benvenuto nella documentazione tecnica per sviluppatori di Pinakes.

## Panoramica

Questa sezione contiene documentazione tecnica per sviluppatori che vogliono estendere, personalizzare o contribuire a Pinakes.

## 📖 Guide Disponibili

### [→ Architettura](./architettura.md)
Panoramica dell'architettura software di Pinakes.

**Argomenti:**
- Stack tecnologico (PHP 8+, Slim 4, MySQL)
- Pattern MVC
- Struttura directory
- Database schema
- Dependency injection

### [→ Sistema Plugin](./plugin-development.md)
Come creare plugin personalizzati per Pinakes.

**Argomenti:**
- Struttura plugin
- File plugin.json
- Classe principale
- Ciclo di vita (install, activate, deactivate, uninstall)
- Best practices

### [→ Hook System](./hooks.md)
Sistema di hooks per estendere funzionalità.

**Argomenti:**
- Filter hooks vs Action hooks
- Hook disponibili (vedi `PLUGIN_HOOKS.md`)
- Priorità hooks
- Registrazione hooks
- Esempi pratici

### [→ API REST](./api.md)
Documentazione API REST di Pinakes.

**Argomenti:**
- Endpoints disponibili
- Autenticazione (Bearer token)
- Rate limiting
- Formati richieste/risposte
- Esempi con curl/Postman

### [→ Frontend Development](./frontend.md)
Come personalizzare il frontend.

**Argomenti:**
- Webpack build system
- Tailwind CSS + Bootstrap
- JavaScript modules
- Asset compilation
- Temi personalizzati

### [→ Sistema i18n](./i18n.md)
Internazionalizzazione e traduzioni.

**Argomenti:**
- File JSON traduzioni
- Funzione i18n()
- Aggiungere nuove lingue
- Tradurre plugin
- Best practices

### [→ Database Migrations](./migrations.md)
Come creare e gestire migrazioni database.

**Argomenti:**
- Struttura migrations
- Creazione migration
- Esecuzione migrations
- Rollback
- Seed data

### [→ Testing](./testing.md)
Come testare Pinakes.

**Argomenti:**
- PHPUnit setup
- Unit tests
- Integration tests
- Test database
- Coverage

### [→ Deployment](./deployment.md)
Come deployare Pinakes in produzione.

**Argomenti:**
- Requisiti server
- Configurazione Apache/Nginx
- Ottimizzazioni performance
- Backup automatici
- Monitoring

### [→ Contributing](./contributing.md)
Come contribuire al progetto Pinakes.

**Argomenti:**
- Setup ambiente sviluppo
- Git workflow
- Code style
- Pull request guidelines
- Code review process

## 🛠️ Stack Tecnologico

### Backend
- **PHP 8.1+**: Linguaggio core
- **Slim 4.13**: Micro-framework
- **MySQL/MariaDB**: Database
- **Composer**: Dependency management
- **mysqli**: Database driver

### Frontend
- **Webpack 5**: Module bundler
- **Tailwind CSS 3.4**: Utility-first CSS
- **Bootstrap 5.3**: Component library
- **jQuery 3**: DOM manipulation (legacy, in dismissione)
- **DataTables**: Advanced tables
- **Chart.js**: Grafici statistiche
- **TinyMCE 8**: WYSIWYG editor
- **FullCalendar**: Calendar prestiti

### Development Tools
- **Composer**: PHP dependencies
- **npm**: JavaScript dependencies
- **Webpack**: Build automation
- **Git**: Version control
- **PHPUnit**: Testing framework

## 🏗️ Struttura Progetto

```
Pinakes/
├── api/                 # API endpoints
├── config/              # Configuration files
├── installer/           # Installation wizard
├── locale/              # i18n translations
├── plugins/             # Plugin system
│   ├── open-library/
│   ├── z39-server/
│   ├── digital-library/
│   └── dewey-editor/
├── public/              # Public assets
├── resources/           # Frontend source
│   ├── js/
│   ├── css/
│   └── views/
├── src/                 # PHP source code
│   ├── Controllers/
│   ├── Models/
│   ├── Middleware/
│   ├── Services/
│   └── Support/
├── storage/             # File storage
│   ├── covers/
│   ├── logs/
│   └── cache/
├── vendor/              # Composer dependencies
├── node_modules/        # npm dependencies
├── .env                 # Environment config
├── composer.json
├── package.json
├── webpack.config.js
└── README.md
```

## 🔧 Setup Ambiente Sviluppo

### Requisiti

- PHP 8.1 o superiore
- Composer 2.x
- MySQL 5.7+ / MariaDB 10.3+
- Node.js 16+ e npm
- Git

### Installazione

```bash
# Clone repository
git clone https://github.com/fabiodalez-dev/Pinakes.git
cd Pinakes

# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install

# Copy env file
cp .env.example .env

# Edit .env with your database credentials
nano .env

# Run installer
php -S localhost:8000 -t public
# Visit http://localhost:8000/installer

# Build frontend assets
npm run dev    # Development
npm run watch  # Watch mode
npm run build  # Production
```

## 📚 Risorse

- **Repository:** https://github.com/fabiodalez-dev/Pinakes
- **Issues:** https://github.com/fabiodalez-dev/Pinakes/issues
- **Discussions:** https://github.com/fabiodalez-dev/Pinakes/discussions
- **Email:** pinakes@fabiodalez.it

## 🤝 Come Contribuire

1. **Fork** il repository
2. **Crea branch** feature (`git checkout -b feature/AmazingFeature`)
3. **Commit** changes (`git commit -m 'Add some AmazingFeature'`)
4. **Push** to branch (`git push origin feature/AmazingFeature`)
5. **Apri Pull Request**

Consulta [CONTRIBUTING.md](../../CONTRIBUTING.md) per dettagli completi.

## 📝 Code Style

- **PSR-12** per PHP
- **ESLint** per JavaScript
- **EditorConfig** incluso nel repository
- **PHPStan** level 6 per static analysis

## 🧪 Testing

```bash
# Run PHP tests
./vendor/bin/phpunit

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/

# Run specific test
./vendor/bin/phpunit tests/Unit/BookRepositoryTest.php
```

## 📖 Documentazione Tecnica Completa

Per documentazione tecnica approfondita sui vari sistemi, consulta i file nella root `/docs`:

- `PLUGIN_SYSTEM.md` - Sistema plugin dettagliato
- `PLUGIN_HOOKS.md` - Lista completa hooks disponibili
- `COMPLETE_HOOKS_SYSTEM.md` - Hook system avanzato
- `CREATING_UPDATES.md` - Sistema aggiornamenti
- `SEO_IMPLEMENTATION_TESTING.md` - SEO e testing

---

**Ultimo aggiornamento:** Dicembre 2025
**Licenza:** GPL-3.0
**Versione:** 0.4.1
