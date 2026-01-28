# Complete Loan Guide

> **The heart of the library!** This guide documents every aspect of the loan system: states, transitions, emails, reservations, availability, and much more.

---

## Quick Start: Your First Loan

### Scenario: A user wants a book

1. **Dashboard** → **New Loan** (or `/loans/create`)
2. **Search user**: Type name, surname, email, or library card number
3. **Search book**: Type title, ISBN, or EAN
4. **Select dates**: The calendar shows availability
5. **Click "Create Loan"**
6. Done! The loan is pending approval

### "Immediate Delivery" Option

If the user is in front of you with the book in hand:
- Check **"Immediate Delivery"**
- The loan skips approval and goes directly to "In Progress"

---

## The Complete Loan Lifecycle

### Main Flow Diagram

```
┌─────────────┐    ┌──────────────┐    ┌──────────────┐    ┌────────────┐
│   PENDING   │ →  │   RESERVED   │ →  │ READY_PICKUP │ →  │IN_PROGRESS │
│   Request   │    │   Approved   │    │    Ready     │    │   Active   │
└─────────────┘    │   (future)   │    │  (pick up!)  │    └─────┬──────┘
                   └──────────────┘    └──────────────┘          │
                                                                 │
                   ┌─────────────────────────────────────────────┘
                   │
                   ▼
┌─────────────┐    ┌──────────────┐    ┌─────────────┐
│   OVERDUE   │ →  │   RETURNED   │    │ LOST/DAMAG. │
│   Expired!  │    │   Completed  │    │   Issues    │
└─────────────┘    └──────────────┘    └─────────────┘
```

### Alternative Paths

```
PENDING ──(reject)───→ REJECTED
PENDING ──(cancel)───→ CANCELLED
RESERVED ──(expires)──→ EXPIRED
READY_PICKUP ─(expires)→ EXPIRED
```

---

## The 10 Loan States

### Active States (book is committed)

| State | Meaning | `active` Flag | Copy State |
|-------|---------|:-------------:|------------|
| **pending** | Request awaiting approval | 0 | None |
| **reserved** | Approved, future date | 1 | `reserved` |
| **ready_for_pickup** | Ready, user must pick up | 1 | `reserved` |
| **in_progress** | User has the book | 1 | `on_loan` |
| **overdue** | Past due, not returned | 1 | `on_loan` |

### Final States (loan concluded)

| State | Meaning | `active` Flag | Copy State |
|-------|---------|:-------------:|------------|
| **returned** | Completed successfully | 0 | `available` |
| **lost** | Book lost | 0 | `lost` |
| **damaged** | Book damaged | 0 | `damaged` |
| **cancelled** | Manually cancelled | 0 | `available` |
| **expired** | Time expired (pickup/reservation) | 0 | `available` |
| **rejected** | Request not approved | 0 | None |

---

## Detailed State Transitions

### 1. Request Creation

```
[User requests book] → PENDING
```

**What happens:**
- A record is created in the `loans` table
- `active = 0` (not yet approved)
- No copy assigned yet
- Admin receives notification (if configured)

---

### 2. Approval

```
PENDING → RESERVED (if future date)
PENDING → READY_FOR_PICKUP (if date is today or past)
```

**What happens:**
- Admin clicks "Approve" in Dashboard
- System assigns an available copy (`copy_id`)
- Copy changes to `reserved` state
- `active = 1`
- **Email sent**: `loan_approved` to user

**If start date is future:**
- State = `reserved`
- Loan waits automatically

**If start date is today or past:**
- State = `ready_for_pickup`
- `pickup_deadline` is set (default: +3 days)
- **Email sent**: `loan_pickup_ready` to user

---

### 3. Automatic Activation (Reserved → Ready for Pickup)

```
RESERVED → READY_FOR_PICKUP
```

**Trigger**: MaintenanceService (cron at 6:00 AM or admin login)

**Condition**: `loan_date <= today` AND `state = 'reserved'`

**What happens:**
- State changes to `ready_for_pickup`
- `pickup_deadline` = today + configured days (default 3)
- **Email sent**: `loan_pickup_ready` to user

---

### 4. Pickup Confirmation

```
READY_FOR_PICKUP → IN_PROGRESS
```

**Trigger**: Admin clicks "Confirm Pickup"

**What happens:**
- User has physically taken the book
- Copy changes from `reserved` to `on_loan`
- `pickup_deadline` is cleared
- Book availability recalculated

---

### 5. Loan Expiration (Automatic)

```
IN_PROGRESS → OVERDUE
```

**Trigger**: MaintenanceService (automatic)

**Condition**: `due_date < today` AND `state = 'in_progress'`

**What happens:**
- State automatically changes to `overdue`
- **Email sent**: `loan_overdue_notification` to user
- **Email sent**: `loan_overdue_admin` to admins

---

### 6. Return

```
IN_PROGRESS → RETURNED
OVERDUE → RETURNED (or LOST/DAMAGED)
```

**Trigger**: Admin clicks "Return"

**Return options:**

| Option | When to use | Copy State |
|--------|-------------|------------|
| **Returned** | Book returned in good condition | `available` |
| **Lost** | Book lost | `lost` |
| **Damaged** | Book damaged | `damaged` |

**What happens:**
- `active = 0`
- `return_date` = today
- Copy updated
- If there were reservations in queue → they are notified
- If book was on wishlist → users notified

---

### 7. Pickup Expiration

```
READY_FOR_PICKUP → EXPIRED
```

**Trigger**: MaintenanceService (automatic)

**Condition**: `pickup_deadline < today` AND `state = 'ready_for_pickup'`

**What happens:**
- User didn't pick up in time
- Copy returns to `available`
- **Email sent**: `loan_pickup_expired` to user
- If there's a queue, copy is reassigned

---

### 8. Reservation Expiration

```
RESERVED → EXPIRED
```

**Trigger**: MaintenanceService (automatic)

**Condition**: `due_date < today` AND `state = 'reserved'`

**What happens:**
- Future reservation is no longer valid
- Copy returns to `available`
- Note added to loan: `[System] Expired on {date}`

---

### 9. Rejection

```
PENDING → REJECTED
```

**Trigger**: Admin clicks "Reject" with reason

**What happens:**
- `active = 0`
- `rejection_reason` saved
- **Email sent**: `loan_rejected` to user with reason

---

### 10. Cancellation

```
PENDING/RESERVED/READY_FOR_PICKUP → CANCELLED
```

**Trigger**: Admin clicks "Cancel"

**What happens:**
- `active = 0`
- If there was an assigned copy → returns to `available`
- **Email sent**: `loan_pickup_cancelled` (if was ready_for_pickup)

---

## Automatic Email System

### Complete Notification Table

| Email | When | Recipient | Template |
|-------|------|-----------|----------|
| **Loan Approved** | Admin approves | User | `loan_approved` |
| **Loan Rejected** | Admin rejects | User | `loan_rejected` |
| **Ready for Pickup** | State → ready_for_pickup | User | `loan_pickup_ready` |
| **Pickup Expired** | Pickup deadline passes | User | `loan_pickup_expired` |
| **Pickup Cancelled** | Admin cancels | User | `loan_pickup_cancelled` |
| **Expiry Reminder** | 3 days before due | User | `loan_expiring_warning` |
| **Loan Overdue** | State → overdue | User | `loan_overdue_notification` |
| **Admin Overdue Alert** | State → overdue | Admin | `loan_overdue_admin` |
| **Book Available** | Wishlist fulfilled | User | `wishlist_available` |

### Available Template Variables

```
{{user_name}}          - User's name
{{user_email}}         - User's email
{{book_title}}         - Book title
{{book_author}}        - Book author(s)
{{start_date}}         - Loan start date
{{end_date}}           - Loan due date
{{pickup_deadline}}    - Deadline to pick up
{{loan_days}}          - Total loan duration in days
{{days_remaining}}     - Days remaining before due date
{{days_overdue}}       - Days overdue
{{rejection_reason}}   - Reason for rejection
{{pickup_instructions}} - Pickup instructions
```

### Automatic Notification Timing

| Notification | When sent |
|--------------|-----------|
| Expiry reminder | X days before (configurable, default 3) |
| Overdue notice | First day overdue |
| Overdue reminder | Every day overdue (if configured) |

---

## ⏰ Pickup Deadline System

### How It Works

When a loan becomes "Ready for Pickup":

```
pickup_deadline = today + pickup_expiry_days (default: 3 days)
```

### Example Timeline

```
Day 1: Loan approved → state = ready_for_pickup
       pickup_deadline = Day 4

Day 2: User can pick up ✓
Day 3: User can pick up ✓
Day 4: Last day! ⚠️

Day 5: MaintenanceService runs
       → state = expired
       → copy released
       → "pickup expired" email sent
```

### Configuration

In **Settings → Loans**:
- `pickup_expiry_days` - Days to pick up (default: 3)

---

## Renewal System

### Renewal Rules

| Rule | Value |
|------|-------|
| **Required state** | Only `in_progress` (not overdue!) |
| **Max renewals** | 3 times |
| **Extension** | +14 days from current due date |
| **Conflicts** | Must not overlap with other reservations |

### Renewal Flow

```
1. User clicks "Renew" in their profile
2. System verifies:
   - State = in_progress? ✓
   - Renewals < 3? ✓
   - No conflicts with reservations? ✓
3. If all OK:
   - due_date += 14 days
   - renewals += 1
4. If conflict:
   - Renewal REFUSED
   - Message: "Book already reserved by another user"
```

### Conflict Check

```
new_due_date = due_date + 14 days

conflicts = SELECT COUNT(*) FROM loans
WHERE book_id = ?
AND id != ?  -- exclude current loan
AND active = 1
AND (
    (loan_date <= new_due_date AND due_date >= due_date)
)

IF conflicts > 0 AND available_copies = 0:
    → REFUSE renewal
ELSE:
    → APPROVE renewal
```

---

## Reservation System (Wishlist/Queue)

### What is a Reservation

When a book is **not available**, the user can **reserve it**:
- Enters a **queue** ordered by request date (FIFO)
- When the book becomes available, the **first in queue** is notified
- The reservation can be automatically converted to a loan

### Reservation States

| State | Meaning |
|-------|---------|
| **active** | Waiting for book to become available |
| **completed** | Converted to loan |
| **cancelled** | Cancelled by user or admin |

### Reservation → Loan Flow

```
1. User reserves unavailable book
   → Reservation state = "active"
   → queue_position assigned

2. Book is returned
   → MaintenanceService detects availability

3. First reservation in queue:
   → A loan is created (origin = 'reservation')
   → Reservation state = "completed"
   → User receives email

4. User picks up book
   → Loan proceeds normally
```

### Reservation Fields

| Field | Description |
|-------|-------------|
| `requested_start_date` | When they want to start (NULL = immediately) |
| `requested_end_date` | When they want to return |
| `reservation_expiry_date` | By when it must be fulfilled |
| `queue_position` | Position in queue |

---

## Availability Calculation

### Formula

```
available_copies = total_copies - occupied_copies - active_reservations
```

### What Counts as "Occupied"

A copy is occupied if associated with a loan in state:
- `in_progress` (user has the book)
- `overdue` (user still has the book)
- `ready_for_pickup` (reserved for pickup)
- `reserved` with `loan_date <= today`

### What Does NOT Count in Total

Copies excluded from `total_copies` count:
- State `lost`
- State `damaged`
- State `maintenance`
- State `under_restoration`

### Automatic Recalculation

Availability is recalculated after:
- Loan approval
- Pickup confirmation
- Return
- Renewal
- Reservation expiration
- Copy state change

---

## Availability Calendar

### In Loan Creation Form

The Flatpickr calendar visually shows availability:

| Color | Meaning |
|-------|---------|
| **Green** | All copies available |
| **Yellow** | Some copies available |
| **Red** | No copies available |
| ⬜ **Gray** | Past date (not selectable) |

### Copies Indicator

Above the calendar: **"Available copies: X/Y"**
- X = copies free today
- Y = total loanable copies

### ICS Calendar (Synchronization)

The system generates an ICS file for syncing with external calendars:

**Path**: `/storage/calendar/library-calendar.ics`
**URL**: `https://yoursite.com/admin/calendar/ics`

**Contains:**
- All active loans (in_progress, ready_for_pickup, reserved)
- Overdue loans
- Active reservations

**Compatible with**: Google Calendar, Apple Calendar, Outlook

---

## Administrative Management

### Dashboard → Loan Management

**URL**: `/admin/loans/pending`

**6 Operational Sections:**

#### 1. 🔴 Overdue Loans
- **Priority**: MAXIMUM
- **Shows**: User, book, days overdue
- **Actions**: Return, Contact user

#### 2. 📦 Ready for Pickup
- **Priority**: HIGH
- **Shows**: User, book, days remaining to pick up
- **Actions**: Confirm Pickup, Cancel

#### 3. ⏳ Pending Approval
- **Priority**: HIGH
- **Shows**: User, book, requested dates
- **Actions**: Approve, Reject (with reason)

#### 4. 📅 Scheduled
- **Priority**: LOW (informational)
- **Shows**: User, book, activation date
- **Info**: Will activate automatically

#### 5. 📚 In Progress
- **Priority**: NORMAL
- **Shows**: User, book, due date
- **Actions**: Return, Renew

#### 6. 🔖 Reservations
- **Priority**: LOW
- **Shows**: User, book, queue position
- **Actions**: Cancel

---

## Configuration

### Settings → Loans

| Setting | Default | Description |
|---------|---------|-------------|
| `loan_duration_days` | 30 | Standard loan duration |
| `loan_extension_days` | 14 | Days per renewal |
| `max_loans_per_user` | 5 | Max concurrent loans |
| `pickup_expiry_days` | 3 | Days to pick up |
| `days_before_expiry_warning` | 3 | Warning days before due |

### Hardcoded Limits

| Limit | Value | Note |
|-------|-------|------|
| Max renewals | 3 | Per loan |
| Renewal extension | 14 days | Fixed |

---

## Business Rules

### One User, One Book

A user cannot have multiple active loans for the same book:

```sql
-- Duplicate check
SELECT COUNT(*) FROM loans
WHERE book_id = ? AND user_id = ?
AND (active = 1 OR (active = 0 AND state = 'pending'))
```

### Penalties

The `penalty` field (DECIMAL) can be set for:
- Late returns
- Lost books
- Damaged books

### Loan Origin

The `origin` field tracks how the loan was created:
- `request` - Direct user request
- `reservation` - Conversion from reservation
- `direct` - Created directly by admin

### Concurrency

All critical operations use:
- Row-level locks (`FOR UPDATE`)
- Atomic transactions
- Consistent lock order: loans → books → copies

---

## Best Practices for Admins

### Daily Routine

1. **Morning**: Check "Overdue" section 🔴
2. **During the day**: Approve pending requests ⏳
3. **Evening**: Verify "Ready for Pickup" not expired 📦

### Managing Overdue

1. System sends automatic emails
2. After X days, contact by phone
3. Document everything in the `notes` field
4. If lost, record with `lost` state and penalty

### Managing Reservations

1. Reservations have FIFO priority
2. Don't manually skip the queue
3. If cancellation needed, notify the user

---

## 👨‍💻 Developer Section

### Controllers and Routes

| Method | URL | Controller | Action |
|--------|-----|------------|--------|
| `GET` | `/loans/create` | `LoanController::create` | Creation form |
| `POST` | `/loans` | `LoanController::store` | Save loan |
| `POST` | `/loans/{id}/renew` | `LoanController::renew` | Renew |
| `GET` | `/admin/loans/pending` | `AdminLoanController::pending` | Management |
| `POST` | `/admin/loans/{id}/approve` | `LoanApprovalController::approve` | Approve |
| `POST` | `/admin/loans/{id}/reject` | `LoanApprovalController::reject` | Reject |
| `POST` | `/admin/loans/{id}/pickup` | `LoanApprovalController::confirmPickup` | Confirm pickup |
| `POST` | `/admin/loans/{id}/cancel-pickup` | `LoanApprovalController::cancelPickup` | Cancel |
| `POST` | `/loans/{id}/return` | `LoanController::processReturn` | Return |

### API Endpoints

```javascript
// Search users (autocomplete)
GET /api/users/search?q={query}
→ [{id, first_name, last_name, email, phone, card_number}]

// Search books (autocomplete)
GET /api/books/search?q={query}
→ [{id, title, isbn, ean, authors}]

// Book availability
GET /api/books/{id}/availability
→ {available_copies, total_copies, dates: [{date, status}]}

// Check renewal conflicts
GET /api/loans/{id}/can-renew
→ {can_renew: bool, reason?: string}
```

### Database - `loans` Table

```sql
CREATE TABLE loans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    copy_id INT,

    -- States and flags
    state ENUM('pending','reserved','ready_for_pickup','in_progress',
               'overdue','returned','lost','damaged',
               'cancelled','expired','rejected'),
    active TINYINT(1) DEFAULT 0,

    -- Dates
    request_date DATETIME,
    loan_date DATE,            -- Loan start
    due_date DATE,             -- Expected end
    return_date DATETIME,      -- Actual end
    pickup_deadline DATE,      -- Pickup deadline

    -- Tracking
    renewals INT DEFAULT 0,
    origin ENUM('request','reservation','direct'),

    -- Notes and penalties
    notes TEXT,
    rejection_reason TEXT,
    penalty DECIMAL(10,2),

    -- Notification flags
    warning_sent TINYINT(1) DEFAULT 0,
    overdue_notification_sent TINYINT(1) DEFAULT 0,

    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (book_id) REFERENCES books(id),
    FOREIGN KEY (copy_id) REFERENCES copies(id)
);
```

### Database - `reservations` Table

```sql
CREATE TABLE reservations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    loan_id INT,              -- Link to loan when converted

    state ENUM('active','completed','cancelled'),
    queue_position INT,

    requested_start_date DATE,
    requested_end_date DATE,
    reservation_expiry_date DATE,

    created_at DATETIME,
    updated_at DATETIME,

    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (book_id) REFERENCES books(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id)
);
```

### Copy States (`copies` table)

```sql
state ENUM(
    'available',       -- Ready for loan
    'on_loan',         -- Physically with user
    'reserved',        -- Reserved (awaiting pickup)
    'maintenance',     -- Under repair
    'under_restoration', -- Being restored
    'lost',            -- Lost
    'damaged',         -- Damaged
    'in_transit'       -- Being transferred
)
```

### Related Services

| Service | Responsibility |
|---------|----------------|
| `MaintenanceService` | Automatic transitions, expirations |
| `NotificationService` | Email sending |
| `DataIntegrity` | Availability recalculation |
| `ReservationManager` | Reservation queue management |
| `IcsGenerator` | ICS calendar generation |

### Available Hooks

```php
// Approval
Hooks::do('before_loan_approve', $loan);
Hooks::do('after_loan_approve', $loan);

// Pickup
Hooks::do('before_loan_pickup', $loan);
Hooks::do('after_loan_pickup', $loan);

// Return
Hooks::do('before_loan_return', $loan);
Hooks::do('after_loan_return', $loan);

// Renewal
Hooks::do('before_loan_renew', $loan);
Hooks::do('after_loan_renew', $loan);

// Automatic expirations
Hooks::do('after_loan_expired', $loan);
Hooks::do('after_pickup_expired', $loan);
```

### Example: Availability Check

```php
// Check if a book is available for a period
$startDate = '2024-02-01';
$endDate = '2024-02-28';
$bookId = 123;

// Count loanable copies
$totalCopies = Copy::where('book_id', $bookId)
    ->whereNotIn('state', ['lost', 'damaged', 'maintenance'])
    ->count();

// Count overlapping loans
$overlappingLoans = Loan::where('book_id', $bookId)
    ->where('active', 1)
    ->where(function($q) use ($startDate, $endDate) {
        $q->whereBetween('loan_date', [$startDate, $endDate])
          ->orWhereBetween('due_date', [$startDate, $endDate])
          ->orWhere(function($q2) use ($startDate, $endDate) {
              $q2->where('loan_date', '<=', $startDate)
                 ->where('due_date', '>=', $endDate);
          });
    })
    ->count();

// Count overlapping reservations
$overlappingReservations = Reservation::where('book_id', $bookId)
    ->where('state', 'active')
    // ... similar logic
    ->count();

$availableCopies = $totalCopies - $overlappingLoans - $overlappingReservations;
$isAvailable = $availableCopies > 0;
```

### Example: Return Process

```php
public function processReturn(int $loanId, string $status, ?float $penalty = null): void
{
    $loan = Loan::findOrFail($loanId);

    DB::transaction(function() use ($loan, $status, $penalty) {
        // Update loan
        $loan->state = $status;
        $loan->active = 0;
        $loan->return_date = now();

        if ($penalty) {
            $loan->penalty = $penalty;
        }

        $loan->save();

        // Update copy
        $copy = $loan->copy;
        $copy->state = match($status) {
            'returned' => 'available',
            'lost' => 'lost',
            'damaged' => 'damaged',
            default => 'available'
        };
        $copy->save();

        // Recalculate availability
        DataIntegrity::recalculateBookAvailability($loan->book_id);

        // Process reservation queue
        ReservationManager::processQueue($loan->book_id);

        // Notify wishlist
        if ($copy->state === 'available') {
            NotificationService::notifyWishlist($loan->book_id);
        }
    });

    Hooks::do('after_loan_return', $loan);
}
```

---

## Operational Checklist

### New Loan
- [ ] User identified
- [ ] Book availability verified
- [ ] Dates selected
- [ ] Conflicts verified
- [ ] Loan created

### Approval
- [ ] Request evaluated
- [ ] Copy assigned
- [ ] Email sent to user
- [ ] Pickup deadline set (if immediate)

### Pickup
- [ ] User identified
- [ ] Book physically delivered
- [ ] Confirmation registered in system
- [ ] Receipt/reminder given

### Return
- [ ] Book received
- [ ] Condition verified
- [ ] Correct state selected
- [ ] Penalty applied (if needed)
- [ ] Next in queue notified (if present)

