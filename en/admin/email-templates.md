# Email Templates

Pinakes includes a complete system for customizable email notifications.

## Available Templates

| Template | Event | Recipient |
|----------|-------|-----------|
| `registration` | New user registration | New user |
| `registration_admin` | New registration | Administrators |
| `email_verification` | Email address verification | User |
| `password_reset` | Password reset request | User |
| `user_approved` | Account approval | User |
| `loan_approved` | Loan approved | User |
| `loan_ready` | Book ready for pickup | User |
| `loan_reminder` | Due date reminder | User |
| `loan_overdue` | Overdue loan | User |
| `pickup_expired` | Pickup expired | User |
| `reservation_available` | Reservation available | User |
| `contact_form` | Contact form message | Administrators |

## Template Customization

### Accessing the Editor

1. Go to **Settings → Email**
2. Scroll to **Email Templates**
3. Click on a template to edit it

### Template Structure

Each template has:
- **Subject**: email subject line
- **Body**: HTML email content
- **Status**: enabled/disabled

### WYSIWYG Editor

The TinyMCE editor allows you to:
- Format text (bold, italic, lists)
- Insert links
- Modify colors
- Insert dynamic placeholders

## Available Placeholders

Placeholders are replaced with actual data at send time.

### Universal Placeholders

| Placeholder | Description |
|-------------|-------------|
| `{{nome_biblioteca}}` | Configured library name |
| `{{url_biblioteca}}` | Application base URL |
| `{{anno}}` | Current year |

### User Placeholders

| Placeholder | Description |
|-------------|-------------|
| `{{nome}}` | User's first name |
| `{{cognome}}` | User's last name |
| `{{email}}` | User's email |
| `{{tessera}}` | Library card number |

### Loan Placeholders

| Placeholder | Description |
|-------------|-------------|
| `{{titolo_libro}}` | Book title |
| `{{autore}}` | Book author(s) |
| `{{data_inizio}}` | Loan start date |
| `{{data_scadenza}}` | Loan due date |
| `{{giorni_ritardo}}` | Days overdue (if applicable) |

### Reservation Placeholders

| Placeholder | Description |
|-------------|-------------|
| `{{posizione_coda}}` | Position in reservation queue |
| `{{scadenza_ritiro}}` | Pickup deadline |

### Email Verification Placeholders

| Placeholder | Description |
|-------------|-------------|
| `{{sezione_verifica}}` | HTML block with verification button |
| `{{link_verifica}}` | Direct verification URL |

### Password Reset Placeholders

| Placeholder | Description |
|-------------|-------------|
| `{{link_reset}}` | URL to reset password |
| `{{scadenza_link}}` | Link validity (hours) |

## SMTP Configuration

### Basic Settings

In **Settings → Email → SMTP Configuration**:

| Field | Description | Example |
|-------|-------------|---------|
| SMTP Host | Email server | `smtp.gmail.com` |
| Port | SMTP port | `587` |
| Encryption | TLS/SSL/None | `TLS` |
| Username | Email account | `library@example.com` |
| Password | Password/App password | `xxxx` |
| Sender Email | FROM address | `library@example.com` |
| Sender Name | Display name | `Municipal Library` |

### Common Providers

#### Gmail
```
Host: smtp.gmail.com
Port: 587
Encryption: TLS
```
> Requires "App Password" if 2FA is enabled

#### Outlook/Office 365
```
Host: smtp.office365.com
Port: 587
Encryption: TLS
```

#### Local Server
```
Host: localhost
Port: 25
Encryption: None
```

## Test Email Sending

### Sending a Test Email

1. Go to **Settings → Email**
2. **Test Email** section
3. Enter a recipient address
4. Click **Send test email**

### Verifying Results

If sending fails, check:
1. Correct SMTP credentials
2. Firewall/port blocks
3. Log in `storage/logs/app.log`

## In-App Notifications

Besides emails, the system supports internal notifications:

### Notification Types

- **Info**: general information
- **Success**: completed operations
- **Warning**: warnings
- **Error**: errors

### Display

Notifications appear:
- In the user panel (bell icon)
- As a badge on the menu
- In the admin dashboard

### Managing Notifications

Users can:
- View unread notifications
- Mark as read
- Delete notifications

## Cron for Automatic Emails

For automatic emails (due reminders, overdue notices), configure a cron job:

```bash
# Every day at 8:00 AM
0 8 * * * php /path/to/public/index.php cron:send-reminders
```

### Available Jobs

| Command | Function |
|---------|----------|
| `cron:send-reminders` | Send due date reminders |
| `cron:send-overdue` | Send overdue notices |
| `cron:cleanup-expired` | Clean up expired reservations |

## Troubleshooting

### Emails not sent

1. **Check SMTP configuration**
   - Does test email work?
   - Correct credentials?

2. **Check the logs**
   ```bash
   tail -100 storage/logs/app.log | grep -i email
   ```

3. **Verify template is active**
   - Is the specific template enabled?

### Emails going to spam

- Configure SPF/DKIM on your domain
- Use a sender address from your domain
- Avoid spam words in subject

### Placeholders not replaced

- Check syntax: `{{placeholder}}` (double braces)
- Verify the placeholder is supported for that template
- Verify data is available in context

---

## Frequently Asked Questions (FAQ)

### 1. How do I configure Gmail to send emails from Pinakes?

To use Gmail as an SMTP server:

**Configuration:**
```
Host: smtp.gmail.com
Port: 587
Encryption: TLS
Username: your-email@gmail.com
Password: [App Password]
```

**Gmail Requirements:**
1. Enable **two-step verification** on your Google account
2. Generate an **App Password** (not your regular password):
   - Go to myaccount.google.com → Security → App passwords
   - Generate a password for "Mail" on "Other (custom name)"
3. Use this 16-character password in Pinakes

**Send limit:** Gmail allows ~500 emails/day for consumer accounts.

---

### 2. How can I customize the graphic layout of emails?

Email templates support full HTML:

1. Go to **Settings → Email → Templates**
2. Select the template to edit
3. In the WYSIWYG editor you can:
   - Modify formatting (bold, colors)
   - Insert images (as external URLs)
   - Add links

**Inline CSS required:** Many email clients (Gmail, Outlook) ignore `<style>`. Always use inline styles:
```html
<p style="color: #333; font-size: 16px;">Text</p>
```

---

### 3. Why do some emails end up in spam?

**Common causes and solutions:**

| Problem | Solution |
|---------|----------|
| No SPF/DKIM | Configure your domain's DNS |
| Generic sender | Use email@yourdomain.com, not gmail |
| Spam words in subject | Avoid "FREE", "URGENT", all caps |
| Too many links | Limit links in the body |
| New domain | Build reputation gradually |

**Recommended DNS records:**
```
SPF:  v=spf1 include:_spf.google.com ~all
DKIM: Configure through SMTP provider
DMARC: v=DMARC1; p=none; rua=mailto:admin@yourdomain.com
```

---

### 4. How do automatic due date reminder emails work?

Reminder emails require a configured **cron job**:

```bash
# Every day at 8:00 AM
0 8 * * * php /path/to/public/index.php cron:send-reminders
```

**Behavior:**
- Checks loans due within the next X days
- Sends email with `loan_reminder` template
- Records the send to avoid duplicates

**Configuration:**
- Advance days are configured in **Settings → Loans**

---

### 5. Can I disable specific email templates?

Yes, each template has an enabled/disabled status:

1. Go to **Settings → Email → Templates**
2. Find the template
3. Turn off the "Active" toggle

**Caution:**
- `email_verification` - Disabling prevents users from verifying email
- `password_reset` - Disabling prevents password recovery
- `loan_approved` - Users won't know when to pick up

---

### 6. Which placeholders can I use in each template?

Placeholders depend on context:

| Template | Available Placeholders |
|----------|------------------------|
| `registration` | Universal + User |
| `loan_approved` | Universal + User + Loan |
| `reservation_available` | Universal + User + Reservation |
| `password_reset` | Universal + User + Reset |
| `email_verification` | Universal + User + Verification |

**Test placeholders:**
Insert `{{placeholder}}` in the body and verify in the test email.

---

### 7. How do I test emails without sending to real users?

**Method 1 - Test email:**
1. Go to **Settings → Email → Test Email**
2. Enter YOUR email
3. Click **Send test email**

**Method 2 - Mailtrap (development):**
```
Host: sandbox.smtp.mailtrap.io
Port: 587
Username: [from Mailtrap]
Password: [from Mailtrap]
```
All emails go to a virtual inbox.

**Method 3 - Local log:**
In `.env` set `MAIL_LOG_ONLY=true` to save emails to file instead of sending.

---

### 8. How do I add a new custom email template?

Currently templates are predefined by the system. For custom templates:

**Option 1 - Modify existing template:**
- Use a rarely-used template (e.g., `contact_form`)
- Customize subject and body

**Option 2 - Plugin:**
```php
HookManager::addAction('notification.send', function($type, $data) {
    if ($type === 'custom_event') {
        $mailer->send($data['email'], 'Subject', $body);
    }
});
```

---

### 9. Do in-app notifications replace emails?

No, they are **complementary**:

| Channel | Use |
|---------|-----|
| **Email** | Important communications, arrive even without login |
| **In-app** | Quick reminders, visible on next access |

**Behavior:**
- Loan approved → Email + In-app notification
- Book available → Email + In-app notification
- Due date approaching → Email only (if cron configured)

In-app notifications are visible by clicking the bell icon in the header.

---

### 10. How do I resolve "Connection timed out" or "Authentication failed" errors?

**Connection timed out:**
1. Verify host and port are correct
2. Check that firewall allows outbound connections on SMTP port
3. Try alternative port (465 instead of 587, or vice versa)

**Authentication failed:**
1. Verify username and password
2. For Gmail: use App Password, not regular password
3. For Office 365: may require OAuth2 (not supported, use direct SMTP)

**Advanced debug:**
```bash
# Test SMTP connection
telnet smtp.gmail.com 587

# Check logs
grep -i "email\|smtp" storage/logs/app.log
```
