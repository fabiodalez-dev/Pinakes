# Librarian Operations FAQ

Quick answers to the most common daily operations for librarians.

## Catalog Management

### How do I add a new book to the catalog?

**Quick method (with ISBN):**
1. Go to **Catalog → New Book**
2. Enter the **ISBN** in the dedicated field
3. Click **Search** - data is retrieved automatically
4. Verify title, author, publisher
5. Select the **genre** and **Dewey classification**
6. Click **Save**

**Manual method (without ISBN):**
1. Go to **Catalog → New Book**
2. Fill in all fields manually
3. Upload cover (optional)
4. Save

**Available ISBN providers:**
- Google Books (default)
- Open Library
- SBN Italia (requires Z39.50 plugin)

---

### How do I modify an existing book's data?

1. Search for the book in the catalog
2. Click on the title to open the card
3. Click **Edit** (pencil icon)
4. Modify the desired fields
5. Click **Save changes**

**Editable fields:**
- Title, subtitle
- Author(s) (you can add multiple)
- Publisher, year, pages
- Description, genre, Dewey
- Cover

---

### How do I delete a book from the catalog?

⚠️ **Warning:** Deletion is permanent!

1. Open the book card
2. Click **Delete** (trash icon)
3. Confirm the operation

**Prerequisites:**
- The book must not have active loans
- Associated copies are deleted
- Reservations are cancelled

**Recommended alternative:** Instead of deleting, set all copies to "Retired" to maintain history.

---

### How do I manage physical copies of a book?

A book can have multiple physical copies:

**Adding a copy:**
1. Open the book card
2. **Copies** section → click **Add Copy**
3. Enter:
   - Inventory number (optional, auto-generated)
   - Location (shelf/level)
   - Condition (Good, Fair, etc.)
4. Save

**Modifying a copy:**
1. In the Copies section, click on the copy
2. Modify the fields
3. Save

**Available states:**
| State | Meaning |
|-------|---------|
| Available | Ready for loan |
| On loan | Currently loaned |
| Under repair | Temporarily unavailable |
| Reserved | Booked by a user |
| Retired | No longer in circulation |
| Lost | Lost, not to be counted |

---

### How do I assign Dewey classification to a book?

**Method 1 - Direct entry:**
1. In the Dewey field, type the code (e.g., `823.914`)
2. Click **Add**
3. The system shows the category name if recognized

**Method 2 - Hierarchical navigation:**
1. Click on **Or browse by categories**
2. Select the main class (e.g., `800 Literature`)
3. Select the division (e.g., `820 English literature`)
4. Continue to the desired level
5. Click **Select**

**Common codes:**
| Code | Category |
|------|----------|
| 800 | Literature |
| 823 | English fiction |
| 853 | Italian fiction |
| 500 | Sciences |
| 900 | History and geography |

---

## Loan Management

### How do I register a new loan?

**From the book card:**
1. Open the book card
2. Click **Loan** on an available copy
3. Search for the user by name or card
4. Select the user
5. Confirm the loan

**From the Loans page:**
1. Go to **Loans → New Loan**
2. Search for the user
3. Search for the book
4. Select the copy
5. Confirm

**Immediate delivery:**
If the setting is active, the loan goes directly to "In progress" without approval.

---

### How do I register a book return?

1. Go to **Loans → Loan Management**
2. Find the loan (search by user or book)
3. Click **Return** (check icon)
4. Confirm the return

**Or from the user card:**
1. Open the user card
2. **Active Loans** section
3. Click **Return** on the loan

**What happens:**
- The copy becomes available again
- The loan history is updated
- If there's a reservation, the user in queue is notified

---

### How do I manage an overdue loan?

1. Go to **Loans → Loan Management**
2. Filter by **Status: Overdue**
3. For each overdue loan:

**Options:**
- **Send reminder**: Click the email icon to send a reminder
- **Extend**: Grant additional days
- **Register return**: If the book was returned

**Automatic emails:**
The system can send automatic reminders if configured (see Settings → Email).

---

### How do I approve or reject a loan request?

If immediate delivery is disabled:

1. Go to **Loans → Pending Requests**
2. For each request:
   - **Approve**: The loan becomes active
   - **Reject**: The loan is cancelled (enter reason)

**Notifications:**
The user receives an email for both approval and rejection.

---

### How do I renew a loan?

1. Go to **Loans → Loan Management**
2. Find the active loan
3. Click **Renew** (circular arrow icon)

**Conditions:**
- The book must not have reservations in queue
- Must not have exceeded the maximum number of renewals
- Must not be overdue

**Renewal settings:**
In **Settings → Loans** you can configure:
- Maximum allowed renewals
- Extension duration (days)

---

## User Management

### How do I register a new user?

1. Go to **Users → New User**
2. Fill in the fields:
   - First and last name
   - Email (required)
   - Password (generated or manual)
   - Role (Standard, Premium, Staff, Admin)
3. Click **Create User**

**Approval:**
If enabled, new self-registered users require approval before they can use the system.

---

### How do I search for a user?

**From the search bar:**
1. Go to **Users**
2. Use the search bar for:
   - First or last name
   - Email
   - Card number

**Advanced filters:**
- By role (Admin, Staff, Premium, Standard)
- By status (Active, Suspended, Pending)
- By registration date

---

### How do I suspend a user?

1. Go to **Users → [user] → Edit**
2. Change the status from "Active" to "Suspended"
3. Enter a reason (optional)
4. Save

**Effects:**
- The user cannot make loans
- Cannot make new reservations
- Active loans remain as they are (must be managed separately)

---

### How do I print a library card?

1. Open the user card
2. Click **Print Card** (or card icon)
3. The print preview opens
4. Print

**Card content:**
- First and last name
- Card number
- Barcode (scannable)
- Expiration date (if configured)

---

## Reservation Management

### How do I manage the reservation queue?

1. Go to **Reservations**
2. View all reservations ordered by date

**When a book is returned:**
1. The system automatically notifies the first in queue
2. The user has N days to pick up (configurable)
3. If they don't pick up, it goes to the next person

**Cancel a reservation:**
1. Click on the reservation
2. Click **Cancel**
3. Enter reason

---

### How do I check if a book has reservations?

1. Open the book card
2. **Reservations** section: shows the queue

**Or:**
1. Go to **Reservations**
2. Filter by book

**Indicators:**
- Badge with reservation count in the book list
- Reservation icon on the book card

---

## Daily Operations

### How do I do the morning check-in?

**Recommended daily checklist:**

1. **Check loans due today:**
   - Loans → Filter: "Due today"
   - Send reminders if necessary

2. **Check overdue returns:**
   - Loans → Filter: "Overdue"
   - Send reminders

3. **Manage ready reservations:**
   - Reservations → "Ready for pickup"
   - Contact users who haven't picked up yet

4. **Approve pending requests:**
   - Loans → Pending requests
   - Users → Pending approval

---

### How do I export data for reports?

**Export Loans:**
1. Go to **Loans → Loan Management**
2. Apply desired filters
3. Click **Export CSV**

**Export Users:**
1. Go to **Users**
2. Filter if necessary
3. Click **Export CSV**

**Export Catalog:**
1. Go to **Catalog**
2. Click **Export**
3. Choose format (CSV)

---

### How do I quickly search for a book for a user?

**From the catalog homepage:**
1. Use the global search bar
2. Search by title, author, ISBN
3. Results show real-time availability

**Advanced search:**
1. Catalog → Advanced Search
2. Combine filters:
   - Genre
   - Author
   - Publisher
   - Publication year
   - Availability

---

### How do I manage a lost or damaged book?

**Lost book:**
1. Open the book card → Copies
2. Find the affected copy
3. Change state from "On loan" to "Lost"
4. (Optional) Record in the loan note "Lost"
5. Close the loan as "Returned with notes"

**Damaged book:**
1. Change copy state to "Under repair" or "Retired"
2. If repairable, return to "Available" after repair
3. If irreparable, leave as "Retired"

---

### How do I view a user's history?

1. Open the user card
2. Available sections:
   - **Active Loans**: current loans
   - **Loan History**: all past loans
   - **Reservations**: active and past reservations
   - **Wishlist**: desired books

**History filters:**
- By period (last month, year, all time)
- By status (completed, overdue, cancelled)

---

## Common Problem Resolution

### The user says they returned but the system still shows the loan

1. Physically verify that the book is on the shelf
2. If present:
   - Go to Loans → find the loan
   - Click **Return**
   - Add note: "Return registered manually"
3. If not present, the book might be lost

---

### I can't loan an "available" book

**Possible causes:**

| Problem | Solution |
|---------|----------|
| User suspended | Reactivate the user |
| User has too many loans | Check limit in Settings |
| Book reserved | Check reservation queue |
| Copy in wrong state | Correct the copy's state |

---

### The ISBN doesn't find results

1. Verify the ISBN is correct (10 or 13 digits)
2. Try without hyphens
3. Try a different provider (Open Library, SBN)
4. If the book is very old or rare, enter manually

---

### How do I cancel a loan registered by mistake?

1. Go to **Loans → Loan Management**
2. Find the loan
3. Click **Cancel** (if available)
4. Or: **Return** immediately

**Note:** The history will keep a record of the operation for transparency.
