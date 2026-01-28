# Security

Pinakes implements various security measures to protect the application and user data.

## CSRF Protection

The system automatically protects all data-modifying operations (POST, PUT, DELETE, PATCH) via CSRF tokens.

### How It Works

1. Each session receives a unique token generated with `random_bytes(32)`
2. The token is valid for **2 hours** (with random variation ±10 minutes)
3. The token must be included in every data-modifying request
4. Validation uses `hash_equals` to prevent timing attacks

### Including in Forms

Every form must include the hidden field with the token:

```html
<input type="hidden" name="csrf_token" value="<?= Csrf::ensureToken() ?>">
```

### Including in AJAX Requests

For JavaScript calls, include the `X-CSRF-Token` header:

```javascript
fetch('/api/endpoint', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify(data)
});
```

### CSRF Error Handling

When the token is invalid:

| Request Type | Response |
|--------------|----------|
| **AJAX** | JSON with error code `CSRF_INVALID` or `SESSION_EXPIRED` |
| **Traditional form** | HTML "Session expired" page with login link |

### Token Regeneration

The token is automatically regenerated:
- After every login
- After every logout
- When it expires (after ~2 hours)

## Cookie Consent (GDPR)

Pinakes includes a banner for cookie consent management compliant with GDPR.

### Cookie Categories

| Category | Type | Description |
|----------|------|-------------|
| **Essential** | Required | Session, CSRF, preferences. No consent required. |
| **Analytics** | Optional | Anonymous usage statistics |
| **Marketing** | Optional | Tracking for targeted advertising |

### Banner Configuration

Go to **Settings → Privacy** to configure:

| Setting | Description |
|---------|-------------|
| **Title** | Banner header text |
| **Message** | Descriptive text shown to users |
| **Accept button text** | "Accept all" button label |
| **Reject button text** | "Essential only" button label |
| **Settings button text** | Label to open preferences |
| **Privacy Policy link** | Privacy policy page URL |

### Appearance

The banner appears as a centered modal or bottom bar (depending on theme) and persists until the user makes a choice.

## Privacy Policy

### Privacy Policy Editor

1. Go to **Settings → Privacy**
2. **Privacy Policy** section
3. Edit text with WYSIWYG editor
4. Save

The privacy policy is displayed at `/privacy-policy`.

### Recommended Content

The policy should include:
- Data controller
- Types of data collected
- Processing purposes
- Legal basis
- Retention period
- Data subject rights
- DPO contacts (if applicable)

## Security Logs

Pinakes logs relevant security events for audit and debugging.

### Accessing Logs

1. Go to **Settings → Security Log**
2. View the last 100 events (most recent first)

### Event Types

| Event | Description |
|-------|-------------|
| `csrf_failed` | Invalid or missing CSRF token |
| `invalid_credentials` | Login attempt with wrong credentials |
| `success` | Successful login |
| `account_suspended` | Access attempt with suspended account |
| `password_reset` | Password reset request |
| `logout` | User logout |

### Log Format

Each log line contains:
- **Timestamp** in ISO format
- **Event type** in square brackets
- **JSON details** with IP, user_id, email, reason

Example:
```
2025-01-24T10:30:45 [SECURITY:invalid_credentials] {"ip":"192.168.1.100","email":"test@example.com","reason":"password_mismatch"}
```

### Log Files

- **Location**: `storage/security.log`
- **Rotation**: Manual or via external cron job

## Secure Logger

The application uses a logging system that automatically sanitizes sensitive data.

### Automatically Redacted Data

The following keys are obscured in logs:
- `password`, `passwd`, `pwd`
- `token`, `api_key`, `apikey`
- `secret`, `key`
- `auth`, `authorization`
- `credit_card`, `card_number`
- `cvv`, `ssn`

### Application Log Files

- **Location**: `storage/logs/app.log`
- **Format**: JSON lines for easy parsing

Example:
```json
{"timestamp":"2025-01-24 10:30:45","level":"INFO","message":"User logged in","context":{"user_id":42,"ip":"192.168.1.1"}}
```

## Best Practices for Administrators

### Monitoring

- Regularly check security logs
- Investigate repeated `csrf_failed` events (possible attack)
- Monitor `invalid_credentials` to detect brute force

### Maintenance

- Keep the application updated
- Use HTTPS in production
- Configure regular database backups
- Limit admin access to minimum necessary

### Credentials

- Use complex passwords for all admin accounts
- Don't share credentials between multiple people
- Change passwords periodically
- Disable accounts no longer in use

## Troubleshooting

### Frequent "Session expired" messages

1. Verify server has synchronized time (NTP)
2. Check PHP `session.gc_maxlifetime` configuration
3. Ensure cookies are being sent correctly

### CSRF token always invalid

1. Verify PHP sessions are working (`session_start()`)
2. Check that sessions folder is writable
3. Ensure form includes `csrf_token` field

### Cookie banner doesn't appear

1. Verify banner is enabled in settings
2. Check browser console for JavaScript errors
3. Clear browser cache (may already have saved consent)

---

## Frequently Asked Questions (FAQ)

### 1. Why do I frequently get "Session expired" error?

The CSRF token is valid for **2 hours** (with random variation ±10 minutes). The most common causes:

| Cause | Solution |
|-------|----------|
| Prolonged inactivity | Normal, login again |
| Server time not synchronized | Configure NTP on server |
| PHP sessions not working | Verify `session.save_path` is writable |
| Multiple tabs open | One tab can invalidate others' tokens |

**Recommended php.ini configuration:**
```ini
session.gc_maxlifetime = 7200
session.cookie_lifetime = 0
```

---

### 2. How do I check if someone is attempting a brute force attack?

Check security logs in **Settings → Security Log**:

**Warning signs:**
- Many `invalid_credentials` events from the same IP
- Attempts on multiple accounts in rapid succession
- Unusual times (night, weekends)

**Example brute force log:**
```
[SECURITY:invalid_credentials] {"ip":"1.2.3.4","email":"admin@...","reason":"password_mismatch"}
[SECURITY:invalid_credentials] {"ip":"1.2.3.4","email":"admin@...","reason":"password_mismatch"}
[SECURITY:invalid_credentials] {"ip":"1.2.3.4","email":"user@...","reason":"password_mismatch"}
```

**Recommended actions:**
- Block IP at firewall level
- Consider using fail2ban
- Enable rate limiting on web server

---

### 3. How do I correctly configure the cookie banner for GDPR?

1. Go to **Settings → Privacy**
2. Configure all fields:

| Field | Example |
|-------|---------|
| Title | "We use cookies" |
| Message | "This site uses cookies to improve experience..." |
| Accept button | "Accept all" |
| Reject button | "Essential only" |
| Privacy link | "/privacy-policy" |

3. Save and verify in an incognito window (Ctrl+Shift+N)

**IMPORTANT:** Essential cookies (session, CSRF) don't require consent and always work.

---

### 4. What are "essential" cookies and why don't they require consent?

Essential cookies are **necessary** for basic site functionality:

| Cookie | Purpose | Duration |
|--------|---------|----------|
| `PHPSESSID` | User session | End of session |
| `csrf_token` | Security protection | 2 hours |
| `cookie_consent` | Remembers choice | 1 year |
| `locale` | Preferred language | 1 year |

**Legal basis:** Art. 6(1)(f) GDPR - legitimate interest of the controller for essential functionality.

They don't require consent because without them the site couldn't function.

---

### 5. How do I write a GDPR-compliant privacy policy?

The privacy policy must contain at least:

**Required sections:**
1. **Data controller** - Name, address, contacts
2. **Data collected** - Email, name, loan history, IP
3. **Purposes** - Loan management, communications, statistics
4. **Legal basis** - Contract, consent, legitimate interest
5. **Retention** - How long data is kept
6. **Rights** - Access, rectification, erasure, portability
7. **Contacts** - How to exercise rights

**Edit in:** **Settings → Privacy → Privacy Policy**

---

### 6. Where do I find security logs and how long are they kept?

**File location:**
- Security log: `storage/security.log`
- Application log: `storage/logs/app.log`

**Retention:** Logs grow indefinitely. Configure rotation:

```bash
# Cron job for weekly rotation (Linux)
0 0 * * 0 mv /path/to/storage/security.log /path/to/storage/security.log.old
```

**Viewing from admin:**
1. Go to **Settings → Security Log**
2. Shows the last 100 events

---

### 7. Which sensitive data is automatically redacted in logs?

The Secure Logger automatically redacts these keys:

| Key | Value in logs |
|-----|---------------|
| `password`, `passwd`, `pwd` | `[REDACTED]` |
| `token`, `api_key`, `apikey` | `[REDACTED]` |
| `secret`, `key` | `[REDACTED]` |
| `authorization`, `auth` | `[REDACTED]` |
| `credit_card`, `card_number` | `[REDACTED]` |
| `cvv`, `ssn` | `[REDACTED]` |

**Example:**
```php
SecureLogger::info('Login', ['email' => 'user@test.com', 'password' => 'secret123']);
// Resulting log: {"email":"user@test.com","password":"[REDACTED]"}
```

---

### 8. How do I protect the admin area from unauthorized access?

**Already implemented measures:**
- CSRF tokens on all operations
- Sessions with expiration
- Logging of all access attempts
- Secure password hashing (password_hash)

**Additional recommended measures:**

1. **Restrict access by IP** (in `.htaccess`):
```apache
<Location /admin>
    Require ip 192.168.1.0/24
</Location>
```

2. **Mandatory HTTPS**:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

3. **Strong passwords**: Minimum 12 characters, mix of upper/lowercase/numbers/symbols

---

### 9. How do I handle a compromised account?

If you suspect an account has been compromised:

**Immediate actions:**
1. Go to **Users → [user] → Edit**
2. Change password immediately
3. Select **Force logout** to invalidate all sessions
4. If admin, temporarily demote to lower role

**Investigation:**
1. Check **Security Log** for suspicious activity
2. Verify recent operations (loans, catalog changes)
3. Check IPs of recent logins

**Future prevention:**
- Enable email notifications for login
- Consider two-factor authentication (via plugin)

---

### 10. Does Pinakes support two-factor authentication (2FA)?

**Natively:** No, Pinakes doesn't include 2FA by default.

**Via plugin:** It's possible to develop a plugin that adds 2FA using available hooks:

```php
// Example hook for 2FA
$hooks->register('auth.login.after', function($user) {
    if ($user->has_2fa_enabled) {
        // Request TOTP code
        redirect('/2fa/verify');
    }
});
```

**Alternatives:**
- Web server-level protection (Apache/Nginx basic auth as second factor)
- VPN for admin access
- IP restriction as described in FAQ 8
