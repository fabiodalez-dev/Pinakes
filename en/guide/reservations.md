# Reservation System

The reservation system manages the waiting queue for books not immediately available.

## Overview

The system allows you to:
- Request a book specifying desired dates
- Check future availability
- Automatically manage the FIFO queue
- Notify users when the book becomes available

## Two Types of Requests

The system distinguishes between two origins for loan requests:

| Origin | Description |
|--------|-------------|
| `request` | Manual user request through catalog |
| `reservation` | Automatic conversion from reservation queue |

## Reservation Flow

### 1. User Request

When a user requests a book:

1. Selects desired dates (start and end)
2. System checks availability
3. If available: creates a `pending` loan with `origin='request'`
4. Operator approves/rejects

### 2. Availability Check

The system calculates availability considering:

```php
// States that block slots
'in_progress', 'overdue', 'ready_for_pickup', 'reserved'
```

**Important note**: The `pending` state does NOT block slots - it's just an unconfirmed request.

### 3. Available Copies Calculation

For each requested day:

```php
available = total_copies - on_loan - reserved
```

Where:
- **total_copies**: Loanable copies (excludes `lost`, `damaged`, `maintenance`)
- **on_loan**: Active loans in the period
- **reserved**: Already approved reservations in the period

## Reservation Queue (`reservations` table)

### Queue Structure

The `reservations` table manages the waiting queue:

| Field | Description |
|-------|-------------|
| `book_id` | Requested book |
| `user_id` | User in queue |
| `state` | `active` / `completed` / `cancelled` |
| `queue_position` | Position in queue |
| `requested_start_date` | Desired start date |
| `requested_end_date` | Desired end date |

### FIFO Order

The queue follows First In, First Out order:
- Sorted by `queue_position ASC`
- First to request has priority
- Position is calculated in real-time

## Automatic Reassignment

The `ReservationReassignmentService` automatically handles reassignments.

### When a Copy Becomes Available

Method: `reassignOnNewCopy()`

1. Searches for "blocked" reservations (no copy or unavailable copy)
2. Sorts by `created_at ASC` (FIFO)
3. Assigns the new copy to first reservation in queue
4. Sets copy to `reserved` state
5. Notifies the user

```php
// Search for waiting reservations
SELECT p.id FROM loans p
LEFT JOIN copies c ON p.copy_id = c.id
WHERE p.book_id = ?
AND p.state = 'reserved'
AND (p.copy_id IS NULL OR c.state != 'available')
ORDER BY p.created_at ASC
LIMIT 1
```

### When a Copy is Lost/Damaged

Method: `reassignOnCopyLost()`

1. Finds the reservation assigned to that copy
2. Searches for another available copy
3. If found: reassigns
4. If not found: puts reservation "on hold" (`copy_id = NULL`)
5. Notifies user of status change

### When a Book is Returned

Method: `reassignOnReturn()`

1. Identifies the book from the returned copy
2. Calls `reassignOnNewCopy()` to assign to whoever is in queue

## Availability Calculation

### API Endpoint

```
GET /api/books/{id}/availability
```

### Response

```json
{
  "success": true,
  "availability": {
    "total_copies": 3,
    "unavailable_dates": ["2024-01-15", "2024-01-16"],
    "earliest_available": "2024-01-17",
    "days": [
      {
        "date": "2024-01-15",
        "available": 0,
        "loaned": 2,
        "reserved": 1,
        "state": "borrowed"
      }
    ]
  }
}
```

### Daily States

| State | Meaning |
|-------|---------|
| `free` | At least one copy available |
| `borrowed` | All copies on loan |
| `reserved` | All copies reserved |

## Creating a Loan Request

### Endpoint

```
POST /api/books/{id}/reservations
```

### Parameters

```json
{
  "start_date": "2024-02-01",
  "end_date": "2024-02-28"
}
```

### Validations

1. User authenticated
2. Dates available (no conflicts)
3. Book exists and not deleted
4. User doesn't already have an active/pending loan for that book

### Duplicate Check

```php
// Check existing loans
SELECT id FROM loans
WHERE book_id = ? AND user_id = ?
AND (
  (active = 0 AND state = 'pending')
  OR (active = 1 AND state IN ('reserved', 'ready_for_pickup', 'in_progress', 'overdue'))
)
```

## Notifications

### Notification Types

| Event | Notification |
|-------|--------------|
| Copy available | Email + in-app to user |
| Copy no longer available | Admin notification |
| Request created | Notification to operators |

### Deferred Notifications

When operations happen inside a transaction, notifications are:
1. Accumulated in `deferredNotifications`
2. Sent after commit with `flushDeferredNotifications()`

This prevents sending notifications for operations that are later rolled back.

## Concurrency

### Race Condition Protection

The system uses explicit locks to prevent duplicate assignments:

```php
// Lock book before creating request
SELECT id FROM books WHERE id = ? FOR UPDATE

// Lock copy before assigning it
SELECT id, state FROM copies WHERE id = ? FOR UPDATE
```

### Retry with Exclusion

If a copy is no longer available after lock:
1. Added to `excludeCopyIds` list
2. Another copy is searched for
3. Maximum 5 attempts

## Configuration

### Default Loan Duration

If user doesn't specify an end date, system adds 1 month from start date.

### Calculation Window

Availability is calculated for 730 days (2 years) from current day.

## Security

### Access Control

- Only authenticated users can create requests
- CSRF token required for all operations
- Book and user ID validation

### Logging

All operations are logged with `SecureLogger`:
- Reassignments
- Assignment errors
- Notifications sent/failed

## Troubleshooting

### Reservation not assigned

Check:
1. Available copies exist for the book
2. Copies are not all in `lost/damaged/maintenance` state
3. MaintenanceService cron is active

### Notification not received

Check:
1. Valid user email
2. SMTP configuration
3. Logs in `storage/logs/app.log`

### Date conflict

If error indicates conflict:
1. Requested dates overlap with existing loans
2. All copies are occupied in the period
3. Try different dates or wait for availability

---

## Frequently Asked Questions (FAQ)

### 1. What's the difference between "request" and "reservation"?

| Type | Origin | When to use |
|------|--------|-------------|
| **Request** | User requests an available book | Book has free copies in chosen dates |
| **Reservation** | User joins the queue | All copies are occupied |

Both go through operator approval.

---

### 2. How does the FIFO queue work?

The queue follows chronological order of insertion (First In, First Out):
- First to request has priority
- When a copy becomes available, it's assigned to first in queue
- Queue position is calculated in real-time

---

### 3. Can a user reserve multiple books simultaneously?

Yes, but with configurable limits:
- Active reservation limit per user (e.g., max 5)
- Cannot have two reservations for the same book
- Limits are configurable in **Settings → Loans**

---

### 4. What happens if user doesn't pick up after approval?

The system automatically handles missed pickups:
1. Loan goes to "ready_for_pickup" state
2. After N days (configurable), automatically expires
3. Copy becomes available again
4. Next user in queue is notified

---

### 5. Can I modify a user's queue position?

No, the queue strictly follows FIFO order to ensure fairness. However:
- An admin can **cancel** a reservation
- An admin can **create a direct loan** bypassing the queue (not recommended)

---

### 6. How do I see all reservations in queue for a book?

1. Go to the **book card**
2. **Reservations** section
3. See list ordered by queue position

Or from **Dashboard** in the "Active Reservations" section.

---

### 7. What happens if all copies are lost/damaged?

Reservations stay in queue but cannot be fulfilled:
- Status: "waiting for copy"
- When you add a new copy, first reservation is reassigned
- Users can cancel their reservation if desired

---

### 8. How do I calculate the next available date for a book?

The API calculates automatically:
```
GET /api/books/{id}/availability
```

Response includes `earliest_available` indicating the first free date considering all active loans.

---

### 9. Can I allow reservations for specific dates?

Yes, the user can specify desired dates:
- **Start date**: when they want to start the loan
- **End date**: when they plan to return

The system checks if copies are available in the requested period.

---

### 10. Do email notifications go out automatically?

Yes, the system sends automatic notifications for:
- **Copy available**: when a reserved book becomes free
- **Reservation confirmed**: when operator approves
- **Pickup expiration**: reminder before pickup time expires

Configurable in **Settings → Email → Templates**.

