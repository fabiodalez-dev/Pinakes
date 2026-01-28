# Dashboard and Statistics

The Pinakes dashboard provides an immediate overview of the library status with statistics, alerts, and quick actions.

## Accessing the Dashboard

The dashboard is the default page after login for administrators and staff.

**Path**: `/dashboard` or `/admin`

## Statistics Cards

The top section shows 6 key indicators:

| Indicator | Description |
|-----------|-------------|
| **Books** | Total books in catalog (excluding deleted) |
| **Users** | Total registered users |
| **Active Loans** | Loans in progress or overdue |
| **Ready for Pickup** | Approved loans awaiting delivery |
| **Pending Requests** | Requests awaiting approval |
| **Authors** | Total authors in archive |

### Interactive Cards

The "Ready for Pickup" and "Pending Requests" cards become clickable when the counter is > 0:
- Colored background (orange or blue)
- Pulsing icon to attract attention
- Click navigates to management page

## Loans Calendar

An interactive calendar shows loans and reservations for the next 6 months.

### Color Legend

| Color | Meaning |
|-------|---------|
| Green | Loans in progress |
| Blue | Scheduled loans (future) |
| Orange | Ready for pickup |
| Red | Overdue loans / End of period |
| Amber | Pending requests |
| Purple | Reservations |

### Calendar Features

- **Month/week/list view**: toggle in top right
- **Navigation**: arrows for previous/next month
- **Click on event**: shows details (title, user, dates, status)
- **Responsive**: on mobile automatically switches to list view

### ICS Synchronization

The **"Sync (ICS)"** button allows you to:
1. Download the calendar file
2. Import into Google Calendar, Apple Calendar, Outlook
3. Automatic updates when calendar is subscribed

**Copy Link**: copies the ICS feed URL to clipboard.

## Operational Sections

Sections are ordered by urgency, from most critical to most informational.

### 1. Ready for Pickup (Orange)

Shows approved loans waiting for user to pick up the book.

**Information shown**:
- Book cover
- Title
- User name and email
- Loan start date
- Pickup deadline (if set)

**Available actions**:
- **Confirm Pickup**: marks book as delivered
- **Cancel**: cancels the loan (if expired)

### 2. Loan Requests (Blue)

Shows requests awaiting approval.

**Request types** (colored badge):
- **From reservation**: converted from a reservation
- **Direct loan**: created by staff/admin
- **Manual request**: submitted by user

**Available actions**:
- **Approve**: confirms the loan
- **Reject**: denies the request

### 3. Scheduled Loans (Cyan)

Approved loans with a future start date.

These are already confirmed and will start automatically on the scheduled date.

### 4. Overdue Loans (Red)

Loans past their due date that require attention.

**Recommended actions**:
- Contact the user
- Record a reminder
- Consider extension or formal notice

### 5. Active Reservations (Purple)

Reservations waiting for the book to become available.

When the book is returned, the reservation automatically converts to a loan request.

### 6. Loans in Progress (Green)

Table with all active loans (not overdue):
- Book title (clickable)
- User name
- Loan date
- Due date
- Status

### 7. Recently Added Books (Gray)

Grid with the last 4 books added to catalog:
- Cover
- Title
- Author
- Publication year

Click on card navigates to book detail.

## "Everything Under Control" Status

If there are no:
- Pickups to confirm
- Pending requests
- Overdue loans

A green confirmation message appears indicating no urgent actions needed.

## Catalog Mode

If the installation is configured in **catalog mode** (browse only, no loans), the dashboard shows only:
- Cards: Books, Users, Authors
- Section: Recently Added Books

All loan and reservation sections are hidden.

## Responsive Design

The dashboard adapts to mobile devices:
- Statistics cards stacked vertically
- Calendar in list view
- Loan sections in single column
- Touch-friendly actions

## Data Updates

Statistics are calculated on each page load. For updated data:
- Reload the page (F5 or pull-to-refresh on mobile)
- After each action (approve/reject/confirm) the page refreshes automatically

---

## Frequently Asked Questions (FAQ)

### 1. Does the dashboard update automatically?

No, data is calculated on page load. For updated data:

- **Desktop**: press F5 or reload the page
- **Mobile**: pull-to-refresh (drag down)
- **After actions**: approve/reject/confirm update automatically

**Note**: Statistics cards always show the current count at load time.

---

### 2. How do I sync the calendar with Google Calendar?

Use the **Sync ICS** feature:

1. Click **"Sync (ICS)"** in the calendar section
2. Choose **"Copy Link"** to copy the feed URL
3. In Google Calendar: Settings → Add calendar → From URL
4. Paste the copied link
5. Google will automatically update events

**Also works with**: Apple Calendar, Outlook, Thunderbird.

---

### 3. What do the colors in the calendar mean?

| Color | Meaning | Action Required |
|-------|---------|-----------------|
| Green | Loan in progress | None, all good |
| Blue | Future loan (scheduled) | None |
| Orange | Ready for pickup | Wait for user pickup |
| Red | Overdue / End of period | Prompt for return |
| Amber | Pending request | Approve or reject |
| Purple | Reservation | Wait for availability |

---

### 4. The orange/blue cards are pulsing, what does that mean?

The **"Ready for Pickup"** and **"Pending Requests"** cards have animation when counter is > 0:

- **Colored background**: indicates urgent actions
- **Pulsing icon**: attracts visual attention
- **Clickable**: navigates directly to management

This indicates there are operations awaiting intervention.

---

### 5. How do I quickly see overdue loans?

Two ways:

**From dashboard:**
- The red **"Overdue Loans"** section lists all delays
- Shows: book, user, email, days overdue

**From Loans menu:**
- **Loans → Overdue** (if available as filter)
- Or filter by "Overdue" status in the complete list

---

### 6. Can I export statistics?

Currently dashboard statistics are visual only. For export:

**Loans:**
- Go to **Loans** → use **Export CSV**
- Includes all filterable data

**Users:**
- Go to **Users** → use **Export CSV**

**Custom reports**: Contact system administrator or use direct database queries.

---

### 7. Is the dashboard different for staff and admin?

Yes, with some differences:

**Staff sees:**
- All 6 statistics cards
- All operational sections (pickups, requests, overdue)
- Actions on loans

**Admin sees additionally:**
- Quick links to configuration
- System alerts (updates, backups)

The main structure is identical, available menu links differ.

---

### 8. In catalog mode what do I see on the dashboard?

If the library is configured in **catalog-only mode**:

**Visible:**
- Cards: Books, Users, Authors
- Section: Recently Added Books

**Hidden:**
- Cards: Active Loans, Ready for Pickup, Pending Requests
- Loans calendar
- All loan sections

The dashboard becomes a catalog overview without circulation functions.

---

### 9. How do I manage pending requests from the dashboard?

In the **"Loan Requests"** section (blue):

1. See the list of pending requests
2. For each request you have:
   - **Approve**: confirms the loan
   - **Reject**: denies the request
3. Clicking shows any confirmation
4. The page refreshes automatically

**Colored badges** indicate origin: from reservation, direct loan, or manual request.

---

### 10. Can I change the order of dashboard sections?

No, the order is fixed and based on urgency:

1. **Ready for Pickup** - Requires physical action
2. **Pending Requests** - Requires decision
3. **Scheduled Loans** - Informational (future)
4. **Overdue Loans** - Requires follow-up
5. **Active Reservations** - Automatic
6. **Loans in Progress** - Informational
7. **Recent Books** - Informational

This sequence ensures urgent actions are always at the top.
