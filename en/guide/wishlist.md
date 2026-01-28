# Wishlist

The wishlist allows users to save books of interest to request them in the future.

## Overview

The wishlist system allows you to:
- Add books to your wish list
- View the availability of each book
- See the next available date for books on loan
- Remove books from the list

## Adding to Wishlist

### From Book Card

1. Go to the book card
2. Click the **Add to wishlist** button (heart icon)
3. The book is added to your list

### Atomic Toggle

The system uses an **atomic toggle** pattern to handle race conditions:

```php
// UserWishlistController::toggle()
// First delete (if exists), then insert (if was absent)
DELETE FROM wishlist WHERE user_id = ? AND book_id = ?

// If the book wasn't in wishlist, add it
INSERT INTO wishlist (user_id, book_id) VALUES (?, ?)
```

This ensures that clicking rapidly multiple times doesn't create duplicates.

## Viewing the Wishlist

### Access

- **User menu → My wishlist**

### Displayed Information

For each book in the wishlist:

| Field | Description |
|-------|-------------|
| **Title** | Book title |
| **Author** | Book author(s) |
| **Availability** | Available / Not available |
| **Next date** | Date when it will be available (if on loan) |

### Availability Calculation

The system checks if loanable physical copies exist:

```php
// Check if the book has available copies
SELECT COUNT(*) > 0 as has_actual_copy
FROM copies
WHERE book_id = ?
  AND state NOT IN ('lost', 'damaged', 'in_transit')
```

### Next Date Calculation

If the book is not available, shows when it will be:

```php
// Find the nearest loan end date
SELECT MIN(end_date) as next_available
FROM loans
WHERE book_id = ?
  AND state IN ('in_progress', 'overdue')
  AND end_date IS NOT NULL
```

## Removing from Wishlist

### From Book Card

1. Go to the book card
2. Click the **Remove from wishlist** button (filled heart icon)

### From Wishlist Page

1. Go to your wishlist
2. Click **Remove** next to the book

## Check Wishlist Status

### Single Book Check

The API checks if a specific book is in the wishlist:

```
GET /api/wishlist/status/{book_id}
```

Response:
```json
{
  "in_wishlist": true
}
```

## Database Table

```sql
CREATE TABLE `wishlist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `book_id` int NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_book` (`user_id`, `book_id`),
  KEY `book_id` (`book_id`),
  CONSTRAINT `wishlist_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wishlist_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE
);
```

### Uniqueness Constraint

The combination `(user_id, book_id)` is unique: a user cannot have the same book twice in their wishlist.

## Security

### Protections

- **Authentication**: only logged-in users can use the wishlist
- **Authorization**: each user sees only their own wishlist
- **CSRF**: token required for modification operations
- **Validation**: book_id verified before insertion

### Access Control

```php
// Only authenticated users
$user = getLoggedUser();
if (!$user) {
    return redirect('/login');
}

// The wishlist always belongs to the current user
$wishlists = getWishlistByUser($user['id']);
```

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/wishlist` | GET | User's wishlist |
| `/wishlist/toggle/{book_id}` | POST | Add/remove book |
| `/api/wishlist/status/{book_id}` | GET | Check if book is in wishlist |

## Troubleshooting

### Book won't add

Check:
1. You are logged in
2. The book exists in the catalog
3. You don't already have the book in wishlist (in this case it will be removed)

### Availability not shown

Check:
1. The book has copies registered in the system
2. The copies are not all in `lost` or `damaged` state

### Available date doesn't appear

Possible causes:
1. All copies are available (no active loans)
2. Active loans don't have an end_date set

---

## Frequently Asked Questions (FAQ)

### 1. How many books can I add to my wishlist?

There's no technical limit. You can add as many books as you want:
- Each book can only be added once
- Clicking again removes it from the wishlist

---

### 2. Does the wishlist get deleted after a certain time?

No, the wishlist is permanent:
- Books stay until you manually remove them
- Even if the book is deleted from the catalog, it disappears automatically

---

### 3. Can I share my wishlist with others?

No, the wishlist is personal and private:
- Only you can see your list
- There is no public sharing link
- Operators don't see users' wishlists

---

### 4. If I add a book to my wishlist, will I be notified when it's available?

Currently no. The wishlist is a personal reminder list:
- You must manually check availability
- Automatic notifications are only associated with **reservations**

To be notified, use the **Reserve** function instead of the wishlist.

---

### 5. Can I request a loan directly from the wishlist?

Yes:
1. Go to your wishlist
2. Click on the book title
3. The book card opens
4. Click **Request loan**

The wishlist helps you keep track, but the request must be made from the book card.

---

### 6. Does the wishlist show real-time availability?

Yes:
- **Available**: at least one free copy
- **Not available**: all copies on loan
- **Next date**: when it will be available (if on loan)

Data is updated on each wishlist page load.

---

### 7. What happens if a book in my wishlist is deleted from the catalog?

The book automatically disappears from your wishlist:
- The foreign key with `ON DELETE CASCADE` removes the record
- You don't receive notification of deletion

---

### 8. Can I sort my wishlist?

Currently the order is chronological (latest added first). It's not possible to manually reorder books.

---

### 9. Does the wishlist work without login?

No, the wishlist requires login:
- If you're not logged in, the "Add to wishlist" button redirects you to login
- After login, you can complete the operation

---

### 10. How do I export my wishlist?

There is no built-in export function. You can:
- Take a screenshot of the page
- Manually copy the titles
- Ask the admin for a database export (for special cases)
