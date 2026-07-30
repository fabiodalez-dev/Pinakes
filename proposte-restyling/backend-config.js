/*
 * Fixture fedele alle chiavi già esistenti nel backend Pinakes.
 * In integrazione i valori arrivano da ConfigStore, ThemeManager e home_content.
 * Il design non dipende dai testi di esempio: ogni nodo è identificato con la
 * relativa chiave persistente tramite data-backend-key.
 */
window.PINAKES_BACKEND = {
  app: {
    name: 'Pinakes',
    logo_path: '../public/assets/brand/logo_small.png',
    footer_description: 'Il tuo sistema Pinakes per catalogare, gestire e condividere la tua collezione libraria.',
    locale: 'it_IT'
  },
  theme: {
    name: 'Pinakes Classic',
    colors: { primary: '#d70161', secondary: '#111827', button: '#d70262', button_text: '#ffffff' },
    typography: { font_family: 'Instrument, system-ui, sans-serif', font_size_base: '16px' }
  },
  system: { catalogue_mode: false },
  sharing: { enabled_providers: ['facebook', 'twitter', 'linkedin', 'telegram'] },
  cms: {
    events_page_enabled: true,
    hero: {
      title: 'La Tua Biblioteca Digitale',
      subtitle: 'Esplora, prenota e gestisci la tua collezione di libri',
      button_text: 'Esplora il Catalogo',
      button_link: '/catalogo',
      background_image: '../public/assets/books.jpg',
      is_active: true,
      display_order: -2
    },
    features_title: { title: 'Perché Scegliere Pinakes', subtitle: 'Un’esperienza di gestione biblioteca moderna, intuitiva e sempre a portata di mano', is_active: true, display_order: 0 },
    features: [
      { key: 'feature_1', title: 'Gestione Semplificata', subtitle: 'Cataloga i tuoi libri, gestisci prestiti e restituzioni con un’interfaccia intuitiva e veloce', icon: '✓', is_active: true },
      { key: 'feature_2', title: 'Catalogazione Completa', subtitle: 'Importa dati da ISBN, organizza per categorie, genera etichette e traccia ogni copia fisica', icon: '⌘', is_active: true },
      { key: 'feature_3', title: 'Sistema di Prestiti', subtitle: 'Gestisci prestiti, scadenze, rinnovi e notifiche automatiche via email per utenti e staff', icon: '↔', is_active: true },
      { key: 'feature_4', title: 'Open Source & Gratuito', subtitle: 'Software libero al 100%, senza costi di licenza. Personalizza e contribuisci al progetto Pinakes', icon: '◇', is_active: true }
    ],
    text_content: {
      title: 'Πίνακες (Pinakes) - “Le Tavole”',
      content: 'Il nome Pinakes deriva dal greco antico πίνακες, “tavole”. Onora Callimaco di Cirene, autore del primo catalogo bibliotecario sistematico della storia, e porta quella tradizione nell’era digitale.',
      is_active: true,
      display_order: 4
    },
    latest_books_title: { title: 'Ultimi Arrivi', subtitle: 'Scopri le ultime novità aggiunte al catalogo', is_active: true, display_order: 5 },
    genre_carousel: { title: 'Esplora i generi principali', subtitle: 'Scopri le nostre radici tematiche e lasciati ispirare dai titoli disponibili.', is_active: true, display_order: 6 },
    events: { title: 'Eventi in Programma', subtitle: 'Partecipa ai nostri prossimi incontri', is_active: true, display_order: 7 },
    cta: { title: 'Pronto a iniziare?', subtitle: 'Registrati ora e inizia a esplorare il nostro catalogo', button_text: 'Registrati Ora', button_link: '/register', is_active: true, display_order: 8 }
  }
};

(function applyBackendConfiguration() {
  const data = window.PINAKES_BACKEND;
  const root = document.documentElement;
  root.style.setProperty('--accent', data.theme.colors.primary);
  root.style.setProperty('--accent-ink', data.theme.colors.button_text);
  root.style.setProperty('--theme-secondary', data.theme.colors.secondary);
  root.style.setProperty('--theme-button', data.theme.colors.button);
  root.style.setProperty('--backend-font', data.theme.typography.font_family);

  const covers = [
    ['copertina_6a536b2d661d26.31869692.jpg', 'Il Signore degli Anelli'],
    ['libro_1162_1779890198.jpg', 'Gli anni in bianco e nero'],
    ['copertina_6a01b291d821d9.06330056.jpg', 'Fantastic Mr Fox'],
    ['copertina_6a19dbf9ed5016.13934887.jpg', '10 miti su Israele']
  ];

  function renderHome(target) {
    const c = data.cms;
    target.innerHTML = `
      <section class="cms-hero" data-backend-key="home_content.hero.background_image" style="--hero-image:url('${c.hero.background_image}')">
        <div class="cms-hero__inner">
          <p class="eyebrow">${data.app.name} · ${data.app.locale}</p>
          <h1 data-backend-key="home_content.hero.title">${c.hero.title}</h1>
          <p data-backend-key="home_content.hero.subtitle">${c.hero.subtitle}</p>
          <a class="primary" href="#" data-backend-key="home_content.hero.button_text">${c.hero.button_text}</a>
        </div>
      </section>
      <section class="cms-section cms-features" data-backend-key="home_content.features_title">
        <header><p class="eyebrow">Configurabile dal CMS</p><h2>${c.features_title.title}</h2><p>${c.features_title.subtitle}</p></header>
        <div class="cms-feature-list">${c.features.filter(x => x.is_active).map(x => `<article data-backend-key="home_content.${x.key}"><span>${x.icon}</span><div><h3>${x.title}</h3><p>${x.subtitle}</p></div></article>`).join('')}</div>
      </section>
      <section class="cms-section cms-story" data-backend-key="home_content.text_content"><p class="eyebrow">Sezione editoriale</p><h2>${c.text_content.title}</h2><p>${c.text_content.content}</p></section>
      <section class="cms-section" data-backend-key="home_content.latest_books_title"><header><p class="eyebrow">Catalogo dinamico</p><h2>${c.latest_books_title.title}</h2><p>${c.latest_books_title.subtitle}</p></header><div class="cms-cover-row">${covers.map(x => `<figure><img src="../public/uploads/copertine/${x[0]}" alt=""><figcaption>${x[1]}</figcaption></figure>`).join('')}</div></section>
      <section class="cms-section cms-genres" data-backend-key="home_content.genre_carousel"><header><h2>${c.genre_carousel.title}</h2><p>${c.genre_carousel.subtitle}</p></header><div><span>Narrativa</span><span>Storia</span><span>Ragazzi</span><span>Arte</span><span>Musica</span></div></section>
      ${data.cms.events_page_enabled && c.events.is_active ? `<section class="cms-section cms-events" data-backend-key="home_content.events"><header><p class="eyebrow">cms.events_page_enabled</p><h2>${c.events.title}</h2><p>${c.events.subtitle}</p></header><div class="event-line"><time>18 SET</time><strong>Gruppo di lettura</strong><span>18:30 · Sala grande</span></div></section>` : ''}
      <section class="cms-cta" data-backend-key="home_content.cta"><div><h2>${c.cta.title}</h2><p>${c.cta.subtitle}</p></div><a class="primary" href="#">${c.cta.button_text}</a></section>`;
  }

  function renderFilters(target) {
    target.innerHTML = `<div class="real-filters__grid">
      <label><span>Ricerca</span><input placeholder="Titolo, autore, ISBN"></label>
      <label><span>Autori</span><select><option>Tutti gli autori</option><option>J.R.R. Tolkien (12)</option></select></label>
      <label><span>Generi</span><select><option>Tutti i generi</option><option>Narrativa (428)</option></select></label>
      <label><span>Editori</span><select><option>Tutti gli editori</option><option>Bompiani (83)</option></select></label>
      <label><span>Disponibilità</span><select><option>Tutti</option><option>Disponibili</option><option>Prenotati</option><option>In prestito</option></select></label>
      <label><span>Tipo media</span><select><option>Tutti i media</option><option>Libro</option><option>eBook</option><option>Audio</option><option>Disco</option></select></label>
      <label class="year-field"><span>Anno di pubblicazione · 1900–2026</span><input type="range" min="1900" max="2026" value="2026"></label>
      <label><span>Ordinamento</span><select><option>Più recenti</option><option>Titolo A–Z</option><option>Autore A–Z</option></select></label>
    </div><div class="real-filters__footer"><span>842 disponibili · 91 prenotati · 351 in prestito</span><button>Pulisci tutti i filtri</button></div>`;
  }

  function renderDetailSections(target) {
    target.insertAdjacentHTML('beforeend', `<div class="backend-detail-sections">
      <section><p class="eyebrow">Descrizione</p><h2>Descrizione</h2><p>La descrizione completa del record rimane nel corpo pagina, con contenuto sanificato dal backend.</p></section>
      <section><p class="eyebrow">Dettagli libro</p><div class="metadata-table"><div><span>ISBN-13</span><strong>9788830102154</strong></div><div><span>ISBN-10</span><strong>8830102155</strong></div><div><span>EAN</span><strong>9788830102154</strong></div><div><span>Lingua</span><strong>Italiano</strong></div><div><span>Anno di pubblicazione</span><strong>2020</strong></div><div><span>Data di pubblicazione</span><strong>01/10/2020</strong></div><div><span>Numero di pagine</span><strong>1.424</strong></div><div><span>Formato</span><strong>Rilegato</strong></div></div></section>
      <section><p class="eyebrow">Parole chiave</p><div class="capability-row"><span>Fantasy</span><span>Terra di Mezzo</span><span>Avventura</span><span>Letteratura inglese</span></div></section>
      <section><p class="eyebrow">Informazioni libro e condivisione</p><div class="metadata-table"><div><span>Editore</span><strong>Bompiani</strong></div><div><span>Stato</span><strong>Disponibile</strong></div><div><span>Copie disponibili</span><strong>2 / 3</strong></div><div><span>Aggiunto il</span><strong>14/07/2026</strong></div></div><div class="capability-row"><span>${data.system.catalogue_mode ? 'Modalità solo catalogo' : 'Richiesta prestito'}</span><span>Wishlist</span><span>${data.sharing.enabled_providers.join(' · ')}</span><span>Condivisione nativa</span></div></section>
      <section><p class="eyebrow">Potrebbero interessarti</p><div class="cms-cover-row">${covers.slice(1).map(x => `<figure><img src="../public/uploads/copertine/${x[0]}" alt=""><figcaption>${x[1]}</figcaption></figure>`).join('')}</div></section>
    </div>`);
  }

  function renderPageFrame() {
    document.querySelectorAll('[data-view="home"]').forEach(button => {
      if (!document.getElementById('home')) {
        const catalog = document.getElementById('catalogo');
        if (catalog) {
          const home = document.createElement('section');
          home.id = 'home';
          home.className = 'view backend-home';
          home.innerHTML = '<div data-backend-home></div>';
          catalog.before(home);
          renderHome(home.firstElementChild);
        }
      }
    });
    document.querySelectorAll('#catalogo').forEach(catalog => {
      catalog.insertAdjacentHTML('afterbegin', `<section class="real-page-banner"><p>Home / Catalogo</p><h1>Catalogo Libri</h1><span>Scopri migliaia di titoli nella nostra collezione digitale</span></section>`);
      catalog.querySelectorAll(':scope > .catalog-head,:scope > .soft-hero,:scope > .workspace-title,:scope > .filter-row,:scope > .catalog-meta,:scope > .quick-filters,:scope > .soft-filter,:scope > .filter-bar').forEach(node => node.remove());
      const anchor = catalog.querySelector('.book-grid,.compact-list,.feature-shelf,.soft-grid');
      if (anchor && !catalog.querySelector('[data-backend-filters]')) {
        const panel = document.createElement('div');
        panel.className = 'real-filters';
        panel.setAttribute('data-backend-filters', '');
        anchor.before(panel);
        renderFilters(panel);
      }
    });
    document.querySelectorAll('.settings-panel,.scrim').forEach(node => node.remove());
    if (!document.querySelector('.proposal-real-footer')) {
      document.body.insertAdjacentHTML('beforeend', `<footer class="proposal-real-footer"><div><strong>${data.app.name}</strong><p>${data.app.footer_description}</p></div><div><strong>Menu</strong><span>Chi siamo · Contatti · Privacy Policy · Cookies</span></div><div><strong>Account</strong><span>Dashboard · Profilo · Wishlist · Prenotazioni</span></div><div><strong>Seguici</strong><span>Canali configurati nel backend</span></div><small>2026 · ${data.app.name} · Powered by Pinakes</small></footer>`);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-backend-home]').forEach(renderHome);
    document.querySelectorAll('[data-backend-filters]').forEach(renderFilters);
    document.querySelectorAll('#scheda').forEach(renderDetailSections);
    document.querySelectorAll('[data-app-name]').forEach(x => { x.textContent = data.app.name; });
    renderPageFrame();
  });
})();
