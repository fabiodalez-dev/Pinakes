# Review System

Users can leave reviews and ratings on books they have borrowed.

## Overview

The review system allows you to:
- Rate books with stars (1-5)
- Write text reviews with a title
- Admin/staff moderation before publication

## Requirements for Reviewing

A user can leave a review only if:

1. **They have borrowed** the book with status `returned` or `in_progress`
2. **They haven't already reviewed** that book

The system automatically verifies these requirements before showing the review form.

### Eligibility Check

```sql
SELECT COUNT(*) FROM loans
WHERE user_id = ?
  AND book_id = ?
  AND state IN ('returned', 'in_progress')
```

If the user has never borrowed the book, they cannot review it.

## Leaving a Review

### For Users

1. Go to the book card
2. **Reviews** section (if you meet the requirements)
3. Fill in the form:
   - **Stars**: rating 1-5 (required)
   - **Title**: max 255 characters (optional)
   - **Description**: max 2000 characters (optional)
4. Click **Publish**

### Validations

| Field | Rule |
|-------|------|
| `stars` | Required, integer 1-5 |
| `title` | Optional, max 255 characters |
| `description` | Optional, max 2000 characters |

### Initial State

All reviews are created with `pending` status and require approval from admin or staff.

## Review States

| State | Description | Visible |
|-------|-------------|---------|
| `pending` | Awaiting moderation | No |
| `approved` | Approved and published | Yes |
| `rejected` | Rejected by moderator | No |

## Editing and Deleting

### Editing

The user can edit their own review:
1. Find your review on the book card
2. Click **Edit**
3. Update stars/title/description
4. Save

> **Note**: The edited review maintains its current state.

### Deleting

The user can delete their own review:
1. Click **Delete** on the review
2. Confirm deletion

## Admin Moderation

### Access

Only users with `admin` or `staff` role can access moderation:

- **Admin → Reviews**

### Review List

The list shows:
- Book (title)
- User (name)
- Stars
- Review title
- Creation date
- Status

### Available Actions

| Action | Effect |
|--------|--------|
| **Approve** | State → `approved`, publicly visible |
| **Reject** | State → `rejected`, not visible |
| **Delete** | Permanently delete |

### Approval

When a review is approved:
- `state` → `approved`
- `approved_by` → admin/staff ID
- `approved_at` → approval timestamp

### Rejection

When a review is rejected:
- `state` → `rejected`
- The review is not visible but remains in the database

## Ratings

### Star System

| Stars | Value |
|-------|-------|
| ⭐ | 1 |
| ⭐⭐ | 2 |
| ⭐⭐⭐ | 3 |
| ⭐⭐⭐⭐ | 4 |
| ⭐⭐⭐⭐⭐ | 5 |

### Average Rating

The book card shows:
- Average rating (e.g., 4.2/5)
- Total number of approved reviews
- Star distribution

### Statistics Calculation

```php
// ReviewsRepository::getReviewStats()
SELECT
    COUNT(*) as total,
    AVG(stars) as average,
    SUM(CASE WHEN stars = 1 THEN 1 ELSE 0 END) as stars_1,
    SUM(CASE WHEN stars = 2 THEN 1 ELSE 0 END) as stars_2,
    ...
FROM reviews
WHERE book_id = ? AND state = 'approved'
```

## Database Table

```sql
CREATE TABLE `reviews` (
  `id` int NOT NULL AUTO_INCREMENT,
  `book_id` int NOT NULL,
  `user_id` int NOT NULL,
  `stars` tinyint NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text,
  `state` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `book_id` (`book_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `reviews_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);
```

## Frontend Display

### Book Card

Approved reviews show:
- Username
- Review date
- Star rating
- Title (if present)
- Description (if present)

### Sorting

Reviews are sorted by creation date (most recent first).

## Security

### Protections

- **Authentication**: only logged-in users can review
- **Authorization**: only the author can edit/delete their own review
- **CSRF**: token required for all operations
- **Sanitization**: `strip_tags()` on title and description
- **Moderation**: all reviews go through approval

### Admin Access Control

```php
// ReviewsAdminController - admin/staff only
if (!in_array($user['role'], ['admin', 'staff'])) {
    return redirect with 403;
}
```

## Troubleshooting

### I can't review

Check:
1. You are logged in
2. You have borrowed the book (status `returned` or `in_progress`)
3. You haven't already reviewed this book

### Review not visible

The review might be:
- In `pending` status (awaiting moderation)
- In `rejected` status

Contact the library for information.

### Error during submission

Check:
1. Star rating is selected (1-5)
2. Title doesn't exceed 255 characters
3. Description doesn't exceed 2000 characters

---

## Frequently Asked Questions (FAQ)

### 1. Do I have to have read the book to review it?

Not necessarily "read", but you must have **made a loan**:
- Returned loan: you can review
- Loan in progress: you can review
- Never borrowed: you cannot review

This ensures that reviews are from real users.

---

### 2. Why doesn't my review appear?

Reviews go through **moderation**:
- `pending` status: awaiting approval
- `rejected` status: not publishable

Contact the library for status information.

---

### 3. Can I edit an already approved review?

Yes:
1. Go to the book card
2. Find your review
3. Click **Edit**
4. Update the content
5. Save

The edited review maintains its "approved" status (doesn't require new approval).

---

### 4. Can a user leave multiple reviews for the same book?

No, each user can leave **only one review per book**. If you want to update it, use the Edit function.

---

### 5. How does the star average work?

The average is calculated only on **approved reviews**:
- Pending reviews: don't count
- Rejected reviews: don't count

Formula: sum of stars / number of approved reviews.

---

### 6. Who can approve or reject reviews?

Only users with **admin** or **staff** role:
1. Go to **Admin → Reviews**
2. See pending reviews
3. Click **Approve** or **Reject**

---

### 7. Are rejected reviews deleted?

No, they remain in the database but are not visible:
- The user doesn't see them published
- The admin can recover them if necessary
- To permanently delete them, use **Delete**

---

### 8. Can I completely disable reviews?

There's no global toggle, but you can:
- Never approve reviews (they stay pending)
- Remove the review form by modifying the template (requires PHP)

---

### 9. Do reviews affect search?

No, search is based on title, author, ISBN, description. Reviews:
- Are not indexed in search
- Don't affect default sorting
- Are only visible on the book card

---

### 10. How do I handle offensive or spam reviews?

1. Go to **Admin → Reviews**
2. Find the problematic review
3. Click **Reject** (keeps in database) or **Delete** (removes)

For repeat offenders, consider blocking the user.
