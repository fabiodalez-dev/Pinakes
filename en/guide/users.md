# User Management

Guide to managing users and roles.

## Roles

The system supports 4 roles with progressive permissions:

| Role | DB Field | Description | Permissions |
|------|----------|-------------|-------------|
| **Standard** | `standard` | Basic reader | Search books, request loans, manage profile |
| **Premium** | `premium` | Privileged reader | Same as standard + possible increased limits |
| **Staff** | `staff` | Library staff | + Approve loans, add books, manage users |
| **Admin** | `admin` | Administrator | + Configuration, plugins, backup, statistics |

## User Registration

### Self-Service

1. User accesses registration page
2. Fills in required data
3. Receives verification email
4. Clicks link to activate account

### Manual Registration

Operators and admins can create users:

1. Go to **Users → New User**
2. Fill in the data
3. Choose whether to:
   - Send email with credentials
   - Set password manually
4. Assign appropriate role
5. Save

## Profile Management

### Edit User

1. Go to **Users**
2. Search for user
3. Click on name
4. Modify necessary fields
5. Save

### Available Fields

| Field | Description |
|-------|-------------|
| Name | First and last name |
| Email | Email address (login) |
| Phone | Phone contact |
| Role | Access level |
| Notes | Internal notes (not visible to user) |
| Active | Enable/disable access |

## Email Verification

### Process

1. User receives email with link
2. Clicks link within 24 hours
3. Account is activated

### Resend Verification

If email doesn't arrive:
1. User clicks "Resend verification email"
2. Or operator can verify manually

### Manual Verification

1. Open user profile
2. Click **Verify manually**
3. Account is activated immediately

## Blocking Users

### When to Block

- Repeatedly unreturned loans
- Library policy violation
- User request

### How to Block

1. Open user profile
2. Disable the "Active" option
3. Optionally add note with reason
4. Save

Blocked user:
- Cannot log in
- Keeps loan history
- Can be reactivated anytime

## User History

For each user you can view:
- Active loans
- Past loans
- Reservations in queue

### Accessing History

1. Open user profile
2. Select "History" tab
3. Filter by date or state

## User Import

For bulk imports:

1. Go to **Users → Import**
2. Upload CSV file with columns:
   - `first_name`, `last_name`, `email`, `phone`
3. Map columns
4. Choose whether to send welcome email
5. Confirm import

---

## Frequently Asked Questions (FAQ)

### 1. What's the difference between Standard and Premium roles?

Both are reader roles, but **Premium** can have increased limits:
- More concurrent loans
- Longer loan duration
- Priority in reservations (configurable)

**When to use Premium**:
- Members with paid subscription
- Loyal cardholders
- Students/researchers with special needs

The exact differences depend on configuration in **Settings → Loans**.

---

### 2. A user isn't receiving verification email, what do I do?

Possible causes and solutions:

1. **Check user's spam folder**
2. **Resend email**: From user list, click "Resend verification"
3. **Manual verification**: Open user profile → "Verify manually"
4. **Check email configuration**: Email tab in settings

If no emails are going out, check logs: `storage/logs/app.log`

---

### 3. How do I reset a user's password?

Two options:

**Self-service (recommended)**:
- User uses "Forgot password" on login page
- Receives email with reset link

**Manual (operator)**:
1. Open user profile
2. Click "Reset password"
3. Enter new password
4. Optional: send notification to user

---

### 4. How do I delete a user from the system?

For data integrity reasons, users with loan history cannot be completely deleted:

1. **Deactivation** (recommended): Deactivate user → keeps history
2. **Anonymization**: Modify personal data with placeholder (e.g., "Removed User")
3. **Deletion** (if no loans): Open profile → Delete

**GDPR**: For deletion requests, use anonymization which preserves statistics.

---

### 5. Can I import users from another system?

Yes, via CSV import:

1. Export from old system in CSV format
2. Ensure it contains at least: `email`, `first_name`, `last_name`
3. Go to **Users → Import**
4. Map columns to Pinakes fields
5. Choose whether to send welcome email

**Supported formats**:
- Separator: comma, semicolon, or tab
- Encoding: UTF-8 (recommended)

---

### 6. How do I assign staff permissions to an existing user?

1. Go to **Users** and search for user
2. Open profile
3. In **Role** field, select "Staff"
4. Save changes

User will immediately have access to staff functions (loan management, catalog, etc.) on next login.

---

### 7. A user wants to change their email, how do I proceed?

Email is the login identifier, so:

1. Open user profile
2. Modify **Email** field with new address
3. Save
4. Optional: resend verification email

**Note**: User will need to use new email to log in.

---

### 8. How do I quickly see all users with overdue loans?

Two methods:

**From dashboard**:
- "Overdue loans" section also lists involved users

**From user list**:
1. Go to **Users**
2. Use "With overdue loans" filter
3. Shows only users with at least one active overdue

---

### 9. Can I create custom roles beyond the 4 predefined ones?

No, the system uses 4 fixed roles (Standard, Premium, Staff, Admin). However:

- **Limits** for Standard/Premium are configurable
- You can use **notes** to further categorize (e.g., "Student", "Senior")
- For complex needs, consider a custom plugin

---

### 10. How do I manage physical library cards?

Pinakes supports card numbers to link digital users to physical cards:

1. Edit user profile
2. Enter **Card Number** (custom field)
3. Use search to find users by card number

**Tip**: Use a barcode reader to scan cards and quickly search for users at the desk.

