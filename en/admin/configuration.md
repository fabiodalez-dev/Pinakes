# Settings Center

Complete guide to the Pinakes Settings Center, divided into 9 sections (tabs).

**Path**: Administration → Settings

---

## Tab 1: Identity

Configure the visual identity of the application.

### Application Identity

| Field | Description |
|-------|-------------|
| **Application name** | Name displayed in header and emails |
| **Logo** | Logo image (PNG, SVG, JPG, WebP - max 2MB) |
| **Remove current logo** | Checkbox to delete existing logo |

**Logo Upload**:
- Drag-and-drop or file selection
- Formats: PNG, SVG, JPG, WebP
- Maximum size: 2 MB
- Recommended: PNG or SVG with transparent background

### Footer

| Field | Description |
|-------|-------------|
| **Footer description** | Text that appears in the public site footer |

### Social Media Links

| Field | Example |
|-------|---------|
| **Facebook** | `https://facebook.com/yourpage` |
| **Twitter** | `https://twitter.com/yourprofile` |
| **Instagram** | `https://instagram.com/yourprofile` |
| **LinkedIn** | `https://linkedin.com/company/yourcompany` |
| **Bluesky** | `https://bsky.app/profile/yourprofile` |

**Note**: Leave a field empty to hide that social from the footer.

---

## Tab 2: Email

Configure the email sending method from the system.

### Sending Method

| Option | Description |
|--------|-------------|
| **PHP mail()** | Native PHP function (simple, less reliable) |
| **PHPMailer** | PHPMailer library with code configuration |
| **Custom SMTP** | External SMTP server configurable from interface |

### Sender

| Field | Description | Example |
|-------|-------------|---------|
| **Sender (email)** | Sender email address | `noreply@library.local` |
| **Sender (name)** | Display name | `City Library` |

### SMTP Server

Available only with "Custom SMTP" driver:

| Field | Description | Example |
|-------|-------------|---------|
| **Host** | SMTP server | `smtp.gmail.com` |
| **Port** | SMTP port | `587` |
| **Username** | SMTP account | `user@gmail.com` |
| **Password** | SMTP password | `xxxx` |
| **Encryption** | TLS / SSL / None | `TLS` |

### Common Providers

#### Gmail
```
Host: smtp.gmail.com
Port: 587
Encryption: TLS
```
> Requires "App Password" if 2FA is active

#### Outlook/Office 365
```
Host: smtp.office365.com
Port: 587
Encryption: TLS
```

---

## Tab 3: Templates

Customize automatic email templates with the TinyMCE editor.

### Available Templates

| Template | Event | Recipient |
|----------|-------|-----------|
| `registration` | New user registration | New user |
| `registration_admin` | New registration notification | Administrators |
| `email_verification` | Email address verification | User |
| `password_reset` | Password reset request | User |
| `user_approved` | Account approval | User |
| `loan_approved` | Loan approved (future date) | User |
| `loan_ready` | Book ready for pickup | User |
| `loan_reminder` | Due date reminder | User |
| `loan_overdue` | Overdue loan | User |
| `pickup_expired` | Pickup expired | User |
| `reservation_available` | Reservation available | User |
| `contact_form` | Contact form message | Administrators |

### Template Structure

Each template has:
- **Subject**: email subject line
- **Body**: HTML content (TinyMCE editor)
- **Placeholders**: dynamic variables shown above editor

### Available Placeholders

#### Universal
| Placeholder | Description |
|-------------|-------------|
| `{{library_name}}` | Configured library name |
| `{{library_url}}` | Application base URL |
| `{{year}}` | Current year |

#### User
| Placeholder | Description |
|-------------|-------------|
| `{{first_name}}` | User's first name |
| `{{last_name}}` | User's last name |
| `{{email}}` | User's email |
| `{{card_number}}` | Library card number |

#### Loan
| Placeholder | Description |
|-------------|-------------|
| `{{book_title}}` | Book title |
| `{{author}}` | Book author(s) |
| `{{start_date}}` | Loan start date |
| `{{due_date}}` | Loan due date |
| `{{days_overdue}}` | Days overdue (if applicable) |

#### Reservation
| Placeholder | Description |
|-------------|-------------|
| `{{queue_position}}` | Position in reservation queue |
| `{{pickup_deadline}}` | Pickup deadline date |

#### Email Verification
| Placeholder | Description |
|-------------|-------------|
| `{{verification_section}}` | HTML block with verification button |
| `{{verification_link}}` | Direct verification URL |

#### Password Reset
| Placeholder | Description |
|-------------|-------------|
| `{{reset_link}}` | Password reset URL |
| `{{link_expiry}}` | Link validity (hours) |

---

## Tab 4: CMS

Static page content management.

### Available Pages

| Page | Description | Editor Link |
|------|-------------|-------------|
| **Homepage** | Hero, features, CTA, background image | `/admin/cms/home` |
| **About Us** | About Us page content | `/admin/cms/about-us` |
| **Events** | Library events management | `/admin/cms/events` |

### Homepage Editor

The homepage editor manages the following sections:

| Section Key | Description |
|-------------|-------------|
| `hero` | Main banner with title, subtitle, button, background, full SEO |
| `features_title` | Features section title |
| `feature_1` - `feature_4` | 4 feature cards with FontAwesome icon |
| `latest_books_title` | New arrivals section title |
| `genre_carousel` | Genre carousel |
| `text_content` | Free text block (TinyMCE) |
| `cta` | Call to Action |
| `events` | Events section |

For complete CMS editor details, see [CMS System](cms.md).

---

## Tab 5: Contact

Contact page and contact form configuration.

### Page Content

| Field | Description |
|-------|-------------|
| **Page title** | Displayed title (e.g., "Contact Us") |
| **Introductory text** | Introductory HTML content (TinyMCE) |

### Contact Information

| Field | Description | Visibility |
|-------|-------------|------------|
| **Contact email** | Public email | Contact page |
| **Phone** | Phone number | Contact page |
| **Notification email** | Where to receive form messages | Admin only |

### Interactive Map

| Field | Description |
|-------|-------------|
| **Complete embed code** | Google Maps or OpenStreetMap iframe |

**Privacy**: External maps are loaded only if user accepts Analytics cookies.

**How to get the code**:
- Google Maps: `https://www.google.com/maps/embed?pb=...`
- OpenStreetMap: `https://www.openstreetmap.org/export/embed.html?bbox=...`

### Google reCAPTCHA v3

Anti-spam protection for the contact form.

| Field | Description |
|-------|-------------|
| **Site Key** | reCAPTCHA public key |
| **Secret Key** | reCAPTCHA private key |

Get keys from: [Google reCAPTCHA Admin](https://www.google.com/recaptcha/admin)

### Privacy Text

| Field | Description |
|-------|-------------|
| **Checkbox text** | Text for mandatory privacy checkbox in form |

---

## Tab 6: Privacy

Privacy Policy, Cookie Policy, and GDPR Cookie Banner management.

### Privacy Policy Content

| Field | Description |
|-------|-------------|
| **Page title** | Title of the `/privacy-policy` page |
| **Page content** | Full privacy policy text (TinyMCE) |

### Cookie Policy Page

| Field | Description |
|-------|-------------|
| **Cookie Policy content** | Content of the `/cookies` page linked from banner |

### Cookie Banner

| Field | Description | Default |
|-------|-------------|---------|
| **Enable Cookie Banner** | Toggle on/off | On |
| **Language** | Banner language | English |
| **Country** | ISO 2-letter code | US |
| **Cookie Statement link** | Cookie policy page URL | - |
| **Cookie Technologies link** | Cookie technologies URL | - |

**Supported Languages**:
- Italian (IT), English (EN), Deutsch (DE), Español (ES)
- Français (FR), Nederlands (NL), Polski (PL), Dansk (DA)
- Български (BG), Català (CA), Slovenčina (SK), עברית (HE)

### Cookie Categories

| Category | Description | Toggle |
|----------|-------------|--------|
| **Essential Cookies** | Always active, necessary for operation | Fixed |
| **Show Analytics Cookies** | Hide if you don't use Google Analytics | On/Off |
| **Show Marketing Cookies** | Hide if you don't use advertising cookies | On/Off |

### Cookie Banner Text

Complete customization of banner text:

**Initial Banner**:
| Field | Description |
|-------|-------------|
| Banner description | Main banner text |
| "Accept all" text | Total acceptance button |
| "Reject non-essential" text | Rejection button |
| "Preferences" text | Modal open button |
| "Save selected" text | Preferences save button |

**Preferences Modal**:
| Field | Description |
|-------|-------------|
| Modal title | Preferences panel heading |
| Modal description | Explanatory text |

**Cookie Categories**:
| Field | Description |
|-------|-------------|
| Essential cookies name | Category label |
| Analytics cookies name | Category label |
| Marketing cookies name | Category label |
| Essential cookies description | Detailed explanation |
| Analytics cookies description | Detailed explanation |
| Marketing cookies description | Detailed explanation |

---

## Tab 7: Messages

Inbox for messages received via the contact form.

### Message Display

The table shows:
- **Checkbox** for multiple selection
- **Sender** (first name, last name, email) with "New" badge if unread
- **Message** (60 character preview)
- **Date** sent
- **Status**: Unread / Read / Archived
- **Actions**: View / Delete

### Available Actions

| Action | Description |
|--------|-------------|
| **View** (eye icon) | Opens modal with full details |
| **Delete** (trash icon) | Deletes the message (confirmation required) |
| **Mark all as read** | Marks all messages as read |

### Message Details

The modal shows:
- First and last name
- Email (clickable to reply)
- Phone (if provided)
- Address (if provided)
- Date and time
- Full message

**Actions in details**:
- **Reply**: Opens email client with pre-filled recipient
- **Archive**: Moves message to archive

---

## Tab 8: Labels

PDF label format configuration for books.

### Available Formats

| Format | Dimensions | Description |
|--------|------------|-------------|
| **25×38mm** | 25×38 mm | Standard book spine (most common) |
| **50×25mm** | 50×25 mm | Horizontal format for spine |
| **70×36mm** | 70×36 mm | Large internal labels (Herma 4630, Avery 3490) |
| **25×40mm** | 25×40 mm | Tirrenia cataloging standard |
| **34×48mm** | 34×48 mm | Tirrenia square format |
| **52×30mm** | 52×30 mm | School library format (A4 compatible) |

### Label Content

Generated PDF labels include:
- **Barcode** (EAN or ISBN)
- **Dewey code** (if present)
- **Location** (shelf-level-position)
- **Title** of the book
- **Main author**

**Note**: The selected format is applied to all generated labels. Make sure it matches the label paper type you use.

To print labels, go to **Catalog → [Book] → Print label**.

---

## Tab 9: Advanced

Advanced settings for developers and special configurations.

### Custom JavaScript

Insert custom JavaScript code loaded based on user cookie preferences.

| Field | When loaded |
|-------|-------------|
| **Essential JS** | Always (even without cookie consent) |
| **Analytics JS** | Only if user accepts Analytics cookies |
| **Marketing JS** | Only if user accepts Marketing cookies |

**Use cases**:
- **Essential**: Critical scripts for operation
- **Analytics**: Google Analytics, Matomo, Plausible
- **Marketing**: Facebook Pixel, Google Ads, remarketing

### Custom CSS

| Field | Description |
|-------|-------------|
| **Custom CSS** | Additional CSS styles loaded on all pages |

### Security

| Option | Description | Effect |
|--------|-------------|--------|
| **Force HTTPS** | Redirects all HTTP requests to HTTPS | 301 Redirect |
| **Enable HSTS** | Sends Strict-Transport-Security header | Browser cache |

**Warning**: Enable HTTPS only if you have a valid SSL certificate configured.

### Loan Notifications

| Field | Description | Range |
|-------|-------------|-------|
| **Days before due date** | When to send reminder | 1-30 days |

Default: 3 days before due date.

### Catalog Mode

| Option | Description |
|--------|-------------|
| **Enable catalog-only mode** | Disables loans and reservations |

When active:
- Users can only browse the catalog
- "Request loan" and "Reserve" buttons hidden
- Simplified dashboard (books, users, authors only)

### XML Sitemap

| Option | Description |
|--------|-------------|
| **Enable sitemap generation** | Generates `/sitemap.xml` automatically |

**Cron Configuration** (recommended):
```bash
# Regenerate sitemap every day at 3:00 AM
0 3 * * * php /path/to/public/index.php cron:sitemap
```

Without cron, the sitemap is regenerated on each request (slower).

### Public API

| Field | Description |
|-------|-------------|
| **Enable public API** | Activates API endpoints for external integrations |
| **API Key** | Authentication token for requests |

**Actions**:
- **Generate new key**: Creates a new token (invalidates previous)
- **Copy key**: Copies to clipboard

**Useful links** (when API active):
- API documentation: `/api/docs`
- Connection test: `GET /api/test`

---

## .env File

Some configurations are managed at server level in the `.env` file:

```ini
# Database
DB_HOST=localhost
DB_NAME=pinakes
DB_USER=root
DB_PASS=password

# Application
APP_URL=https://my-library.com
APP_DEBUG=false
APP_ENV=production

# Email (alternative to UI configuration)
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USER=user@example.com
MAIL_PASS=secret
MAIL_ENCRYPTION=tls
```

---

## File Permissions

Verify these folders are writable by the webserver:

```
storage/                # All content
├── backups/
├── cache/
├── logs/
├── plugins/
├── tmp/
└── uploads/

public/
└── uploads/
```

Recommended permissions: `755` for folders, `644` for files.

---

## Troubleshooting

### Emails not sent

1. **Verify SMTP configuration** in Email Tab
2. Check that templates are enabled in Template Tab
3. Check logs: `storage/logs/app.log`

### Cookie banner doesn't appear

1. Verify it's enabled in Privacy Tab
2. Check for JavaScript errors in console
3. Banner doesn't appear if user already gave consent

### Sitemap not updated

1. Verify it's enabled in Advanced Tab
2. Configure cron job for automatic updates
3. Regenerate manually: `php public/index.php cron:sitemap`

### API not responding

1. Verify API is enabled in Advanced Tab
2. Check that API key is valid
3. Test with: `curl -H "X-API-Key: YOUR_KEY" https://site/api/test`

---

## Frequently Asked Questions (FAQ)

### 1. How do I change the library logo?

1. Go to **Settings → Identity** (first tab)
2. In the **Logo** section, click the upload area
3. Select an image (formats: PNG, JPG, SVG)
4. Preview appears immediately
5. Click **Save settings**

**Recommended dimensions**: The logo is automatically resized. At least 200x60 pixels recommended for optimal quality.

---

### 2. Emails aren't reaching users, what do I check?

Verify in this order:

1. **Email Tab**: Check SMTP configuration
   - Host, port, username and password correct
   - Try "Send test email"

2. **Template Tab**: Verify templates are enabled (toggle active)

3. **Spam**: Check user's spam folder

4. **Log**: Look for errors in `storage/logs/app.log`

**Recommended providers**: For reliable delivery use services like SendGrid, Mailgun, or Amazon SES instead of generic SMTP.

---

### 3. How do I customize email text?

1. Go to **Settings → Templates**
2. Select the template to modify (e.g., "Loan approved")
3. Edit text in editor
4. Use **placeholders** in double braces: `{{first_name}}`, `{{book_title}}`, etc.
5. Click **Save template**

**Available placeholders**: Each template shows usable placeholders. Click "Show placeholders" for complete list.

---

### 4. How do I activate the GDPR cookie banner?

1. Go to **Settings → Privacy**
2. Enable **Cookie Banner** (toggle)
3. Select the banner **Language**
4. Configure cookie categories:
   - **Essential**: always active
   - **Analytics**: optional (Google Analytics, etc.)
   - **Marketing**: optional (advertising, etc.)
5. Enter Privacy Policy and Cookie Policy links
6. Click **Save**

The banner will appear to new visitors. Users who already accepted won't see it again.

---

### 5. How do I force HTTPS on the entire site?

1. Go to **Settings → Advanced**
2. Enable **Force HTTPS** (toggle)
3. Optional: Enable **HSTS** for maximum security
4. Save settings

**Prerequisite**: SSL certificate must already be installed on the server. If not, contact your hosting.

**Warning**: If you enable HTTPS without a valid certificate, the site will become inaccessible.

---

### 6. How do I add Google Analytics to the site?

1. Go to **Settings → Advanced**
2. In the **Custom JavaScript** field, **Analytics** section
3. Paste Google Analytics tracking code:

```javascript
<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXXXX');
</script>
```

4. Save settings

Code in the "Analytics" section is loaded only if user accepts Analytics cookies.

---

### 7. How do I regenerate the Sitemap?

The sitemap can be:

**Generated automatically:**
1. Configure cron job on server
2. Runs `php public/index.php cron:sitemap` daily

**Generated manually:**
1. Go to **Settings → Advanced**
2. Click **Regenerate Sitemap**
3. The `sitemap.xml` file is updated immediately

**Verify**: Access `https://yoursite.com/sitemap.xml` to see the result.

---

### 8. How do I change the book label format?

1. Go to **Settings → Labels**
2. Select desired format:
   - 25×38 mm (small)
   - 50×25 mm (rectangular)
   - 70×36 mm (large)
   - Other available formats
3. Save settings

To print labels:
1. Open book card
2. Click **Print label**
3. A PDF is generated in the selected format

---

### 9. How do I activate "Catalog Mode" (browse only)?

If you want to use Pinakes only as an online catalog, without loan management:

1. Go to **Settings → Advanced**
2. Enable **Catalog Mode** (toggle)
3. Save settings

In this mode:
- Users can search and view books
- "Request loan" buttons are hidden
- Dashboard shows catalog statistics only
- Loans/reservations sections are disabled

---

### 10. How do I add social network links?

1. Go to **Settings → Identity**
2. Scroll to **Social Media** section
3. Enter complete profile URLs:
   - Facebook: `https://facebook.com/pagename`
   - Instagram: `https://instagram.com/profilename`
   - Twitter/X: `https://twitter.com/username`
   - LinkedIn: `https://linkedin.com/company/name`
   - Bluesky: `https://bsky.app/profile/name`
4. Save settings

Links will appear in the site footer with respective icons.
