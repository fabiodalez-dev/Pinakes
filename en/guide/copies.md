# Copy Management

Each book in the catalog can have multiple physical copies. This guide explains how to manage them.

## Overview

The copy system allows you to:
- Register multiple physical copies for each book
- Track the state of each copy
- Manage inventory with unique numbers
- Link copies to loans

## Copy States

Copies can be in one of 8 states:

| State | Description | Loanable |
|-------|-------------|----------|
| `available` | Copy available for loan | Yes |
| `on_loan` | Currently on loan | No (automatic) |
| `reserved` | Reserved for an approved loan | No (automatic) |
| `maintenance` | Under ordinary maintenance | No |
| `under_restoration` | Being restored/repaired | No |
| `lost` | Lost copy | No |
| `damaged` | Damaged unusable copy | No |
| `in_transit` | Being transferred between locations | No |

### Automatic States

Two states are automatically managed by the system and **cannot be set manually**:

- **`on_loan`**: Set when the copy is handed out on loan
- **`reserved`**: Set when a loan is approved

Attempting to manually set these states generates an error.

### Loanable States

Only copies in `available` state can be assigned to new loans.

## Adding a Copy

### Access

1. Go to the book card
2. **Copies** section
3. Click **Add Copy**

### Copy Fields

| Field | Description | Required |
|-------|-------------|----------|
| **Inventory Number** | Unique identification code | Yes |
| **State** | Initial copy state | Yes (default: available) |
| **Notes** | Additional notes | No |
| **Position** | Physical location (shelf/level) | No |

### Inventory Number

The inventory number must be **unique** across the entire system. Recommended format:
- `INV-2024-001`
- `LIB-A-0042`
- Barcode

## Modifying a Copy

### State Change

1. Go to the book card
2. **Copies** section
3. Click on the copy to modify
4. Select new state
5. Save

### Manually Modifiable States

You can change the state to:
- `available`
- `maintenance`
- `under_restoration`
- `lost`
- `damaged`
- `in_transit`

### Inventory Change

The inventory number can be modified only if it doesn't create duplicates.

## Deleting a Copy

### Requirements

A copy can be deleted **only** if in one of these states:
- `lost`
- `damaged`
- `maintenance`

### Procedure

1. Go to the book card
2. **Copies** section
3. Click **Delete** on the copy
4. Confirm deletion

### Non-Deletable Copies

If the copy is in a state other than those listed, you'll receive an error. This prevents accidental deletion of copies in use.

## Validations

### State Check

```php
// CopyController - valid states
$validStates = [
    'available',
    'on_loan',
    'reserved',
    'maintenance',
    'under_restoration',
    'lost',
    'damaged',
    'in_transit'
];

// States not manually settable
$autoStates = ['on_loan', 'reserved'];
```

### Deletion Check

```php
// Only these states allow deletion
$deletableStates = ['lost', 'damaged', 'maintenance'];
```

## Book Availability

A book's availability depends on its copies:

```php
// Available copies calculation
SELECT COUNT(*) FROM copies
WHERE book_id = ?
  AND state = 'available'
```

The `available_copies` field in the `books` table is automatically recalculated when:
- A copy is added
- A copy's state is modified
- A copy is deleted
- A loan is approved/rejected
- A book is returned

## Database Table

```sql
CREATE TABLE `copies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `book_id` int NOT NULL,
  `inventory_number` varchar(100) NOT NULL,
  `state` enum('available','on_loan','reserved','maintenance','under_restoration','lost','damaged','in_transit') DEFAULT 'available',
  `notes` text,
  `position_id` int DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_number` (`inventory_number`),
  KEY `book_id` (`book_id`),
  KEY `position_id` (`position_id`),
  CONSTRAINT `copies_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `copies_position_fk` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE SET NULL
);
```

## Relationship with Loans

### Copy Assignment

When a loan is approved:
1. The system finds an `available` copy
2. Sets the copy to `reserved`
3. Links the copy to the loan (`copy_id`)

### Return

When a book is returned:
1. The copy returns to `available` state
2. The system checks the reservation queue
3. If there's a reservation, the copy is reassigned

## Security

### Access Control

Only users with `admin` or `staff` role can:
- Add copies
- Modify copy states
- Delete copies

### CSRF

All operations require a valid CSRF token.

### Input Validation

- Inventory number: max 100 characters, alphanumeric and hyphens
- State: must be one of the 8 valid states
- Notes: sanitized with `strip_tags()`

## Troubleshooting

### Can't delete a copy

Check:
1. The copy's state (must be `lost`, `damaged` or `maintenance`)
2. If it's `available`, change the state first

### "On loan" state not selectable

Correct behavior: the `on_loan` state is automatically set by the loan system. It cannot be set manually.

### Duplicate inventory number

The inventory number must be unique. Choose another code.

### Copy not assignable to loan

Check:
1. The copy's state (must be `available`)
2. If it's in `maintenance` or another state, change it first

---

## Label Printing

Pinakes generates PDF labels for book spines, complete with EAN/ISBN barcode.

### Generating a Label

1. Go to the book card
2. Click **Print Label** (barcode icon)
3. PDF opens in a new tab
4. Print on adhesive paper

### Label Content

Each label includes:
- **Library name** (from APP_NAME setting)
- **Title** (truncated if needed)
- **Author(s)**
- **EAN/ISBN barcode** (if available)
- **EAN number** in plain text
- **Dewey classification**
- **Location** (shelf/level)

### Available Formats

Configure the format in **Settings → Labels**:

| Format | Dimensions | Typical Use |
|--------|------------|-------------|
| **25×38mm** | Vertical | Standard book spine (most common) |
| **50×25mm** | Horizontal | Horizontal book spine |
| **70×36mm** | Large | Internal labels (Herma 4630, Avery 3490) |
| **25×40mm** | Vertical | Standard Tirrenia cataloging |
| **34×48mm** | Square | Tirrenia square format |
| **52×30mm** | Horizontal | School libraries (A4 compatible) |

### Automatic Layout

The system automatically adapts the layout:
- **Vertical formats** (height > width): Portrait layout optimized for narrow spines
- **Horizontal formats**: Landscape layout with more space for text

### Configuration

1. Go to **Settings**
2. Section **Book Label Configuration**
3. Select desired format
4. Click **Save label settings**

### Recommended Paper

Use adhesive paper specific to the chosen format:
- Herma, Avery, Tirrenia produce A4 sheets with pre-cut labels
- Verify compatibility with your printer (laser/inkjet)

### API Endpoint

For integrations:
```
GET /api/books/{id}/label-pdf
```

Returns the PDF label in configured format.

---

## Frequently Asked Questions (FAQ)

### 1. How many copies can I add for each book?

There's no technical limit. You can add all the physical copies you own:
- Each copy requires a **unique inventory number**
- Copies are tracked independently
- Book availability reflects the number of available copies

---

### 2. What happens if I delete the last copy of a book?

The book remains in the catalog but becomes "not available":
- **Availability**: 0 copies
- **Loans**: not possible until new copies are added
- **Reservations**: remain in queue but cannot be fulfilled

---

### 3. Can I change a copy's inventory number?

Yes, as long as the new number isn't already used by another copy:
1. Go to the book card
2. Edit the copy
3. Change the inventory number
4. Save

The system verifies uniqueness and blocks duplicates.

---

### 4. When do I use "maintenance" vs "under_restoration"?

| State | Typical Use | Duration |
|-------|-------------|----------|
| `maintenance` | Periodic checks, cleaning, inventory | Short |
| `under_restoration` | Repair, rebinding, conservation restoration | Long |

Both make the copy non-loanable.

---

### 5. How do I manage a lost copy?

1. Change state to **"lost"**
2. Add a note with loss date and circumstances
3. If the copy reappears, change state to **"available"**
4. To remove it permanently, use **Delete**

---

### 6. Why can't I manually set "on_loan" state?

The `on_loan` state is **automatic**: it's set by the system when the copy is actually handed out on loan.

**This prevents**:
- Inconsistencies between copy state and loan
- "On loan" copies without an associated loan
- Manual inventory errors

---

### 7. How do I find all copies under maintenance?

Two methods:

**From catalog**:
- Filter by copy state "maintenance"

**From database** (for technicians):
```sql
SELECT c.*, b.title FROM copies c
JOIN books b ON c.book_id = b.id
WHERE c.state = 'maintenance';
```

---

### 8. Can I associate a different physical position to each copy?

Yes, each copy has an independent **position** field:
- Copy 1: Shelf A, Level 2
- Copy 2: Shelf B, Level 1

Useful for libraries with multiple locations or sections.

---

### 9. What does "in_transit" mean?

The `in_transit` state indicates the copy is being moved:
- Between different shelves
- Between different locations
- To/from external storage

The copy is not loanable until it returns to "available".

---

### 10. How do I manage copies of donated books or on loan from other libraries?

Add the copy normally with an inventory number that identifies it:
- E.g., `LOAN-2024-001` for temporary copies
- Add notes with origin and expected return date
- Use "in_transit" state when returning it
