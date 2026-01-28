# Location System

The location system allows you to manage the physical position of books in the library.

## Overview

The system organizes books in a three-level hierarchy:

```
Shelf → Level → Position
  A       2        15
```

**Location format**: `A.2.15` (Shelf A, Level 2, Position 15)

## Hierarchical Structure

### Shelves

The shelf is the main container (cabinet, bookcase, etc.).

| Field | Description | Required |
|-------|-------------|----------|
| `code` | Identifying letter (e.g., A, B, C) | Yes |
| `name` | Description (e.g., "Italian Fiction") | No |
| `order` | Position in the list | No |

**Rules**:
- The code is automatically converted to uppercase
- The code must be unique
- You cannot delete a shelf that contains levels

### Levels

The level is a tier within the shelf.

| Field | Description | Required |
|-------|-------------|----------|
| `shelf_id` | Parent shelf | Yes |
| `level_number` | Tier number (1, 2, 3...) | Yes |
| `order` | Position in the list | No |

**Rules**:
- The level number must be unique per shelf
- You cannot delete a level that contains books

### Positions

The position indicates the specific slot on the level.

| Field | Description |
|-------|-------------|
| `shelf_id` | Shelf |
| `level_id` | Level |
| `position_number` | Sequential number (1, 2, 3...) |

## Access

Location management is found in:
- **Admin → Location**

## Operations

### Creating a Shelf

1. Go to **Admin → Location**
2. **Shelves** section
3. Enter:
   - **Code**: unique letter (e.g., A, B, C)
   - **Name**: optional description
4. Click **Create Shelf**

### Creating a Level

1. **Levels** section
2. Select the parent shelf
3. Enter:
   - **Level number**: 1, 2, 3...
   - **Generate positions**: number of slots to create (optional)
4. Click **Create Level**

If you specify "Generate positions", the system automatically creates N positions on the level.

### Deleting a Shelf

1. Click **Delete** next to the shelf
2. Confirm

**Constraints**:
- The shelf must not contain levels
- The shelf must not contain books

### Deleting a Level

1. Click **Delete** next to the level
2. Confirm

**Constraints**:
- The level must not contain books

### Reordering

Elements can be reordered via drag-and-drop:
- Shelves
- Levels
- Positions

The order is automatically saved via API.

## Assigning Location to a Book

### During Entry

In the book form:
1. Select **Shelf**
2. Select **Level**
3. The system automatically suggests the **next available position**

### Suggestion by Genre

The system can suggest location based on book genre:

```
GET /api/location/suggest?genre_id=5&subgenre_id=12
```

This helps group books of the same genre on the same shelf.

### Next Position Calculation

```
GET /api/location/next-position?shelf_id=1&level_id=3
```

Response:
```json
{
  "next_position": 15,
  "location": "A.2.15",
  "level_number": 2,
  "shelf_code": "A"
}
```

## Viewing Books by Location

### Book List

```
GET /api/location/books?shelf_id=1&level_id=3
```

Shows all books positioned on the specified shelf/level.

### CSV Export

```
GET /admin/location/export?shelf_id=1&level_id=3
```

Exports a CSV with:
- Location
- Title
- Authors
- Publisher
- ISBN
- Year

The CSV uses `;` separator and includes UTF-8 BOM for Excel compatibility.

## Database Tables

### shelves

```sql
CREATE TABLE `shelves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `order` int DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
);
```

### levels

```sql
CREATE TABLE `levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `shelf_id` int NOT NULL,
  `level_number` int NOT NULL,
  `order` int DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shelf_level` (`shelf_id`, `level_number`),
  CONSTRAINT `levels_shelf_fk` FOREIGN KEY (`shelf_id`) REFERENCES `shelves` (`id`) ON DELETE CASCADE
);
```

### books (location fields)

```sql
-- Fields in the books table
shelf_id int DEFAULT NULL,
level_id int DEFAULT NULL,
position_number int DEFAULT NULL
```

## Security

### Access Control

Only `admin` and `staff` users can manage locations.

### CSRF

All operations require a valid CSRF token.

### Validation

- Shelf code: required, converted to uppercase
- Level number: must be unique per shelf
- Positions: generated automatically

## Troubleshooting

### Cannot delete shelf

Check:
1. There are no levels in the shelf
2. There are no books assigned to the shelf

### Duplicate shelf code

The code must be unique. Choose another letter.

### Position already occupied

The system automatically calculates the next free position. If it appears occupied, the existing book in that position must be moved.

### Location not displayed

Verify the book has all three fields filled:
- shelf_id
- level_id
- position_number

---

## Frequently Asked Questions (FAQ)

### 1. Is it mandatory to assign a location to books?

No, location is optional. However, it's useful for:
- Physically finding books
- Organizing the library by subject
- Printing labels with position
- Exporting inventories by shelf

---

### 2. How do I organize shelves logically?

**Recommended approach**:
| Shelf | Content |
|-------|---------|
| A | Italian Fiction |
| B | Foreign Fiction |
| C | Non-Fiction |
| D | Science |
| E | Children/Young Adult |

Use the **Name** field to describe each shelf's content.

---

### 3. Can I rename an existing shelf?

Yes, you can modify:
- **Name**: freely
- **Code**: only if it doesn't create duplicates

Associated books maintain their location.

---

### 4. How does automatic genre suggestion work?

The system can suggest where to place a book based on genre:
1. Configure the genre → shelf mapping
2. When you enter a book with genre X
3. The system suggests the associated shelf

Configuration in **Settings → Location → Genre Mapping**.

---

### 5. Can I move a book to another shelf?

Yes:
1. Go to the book card
2. Edit the **Location** section
3. Select new Shelf/Level/Position
4. Save

The previous position becomes available for other books.

---

### 6. How do I print labels with location?

1. Go to the book card
2. Click **Print Label**
3. Choose the format
4. The label includes location (e.g., A.2.15)

Label formats configurable in **Settings → Labels**.

---

### 7. How do I export the book list by shelf?

1. Go to **Admin → Location**
2. Select the shelf (optionally level)
3. Click **Export CSV**
4. File includes: location, title, author, ISBN

---

### 8. What happens if I delete a shelf with books?

**You can't**: the system blocks deletion if there are books or levels associated.

**Correct procedure**:
1. Move all books to another shelf
2. Delete the empty levels
3. Delete the shelf

---

### 9. How do I manage multiple locations/libraries?

Use shelves to represent locations:
- Shelf A = Downtown Location
- Shelf B = North Location
- Shelf C = South Location

Or use a prefix: `A-DOWNTOWN`, `A-NORTH`.

---

### 10. Can I reorder shelves in the menu?

Yes, using drag-and-drop:
1. Go to **Admin → Location**
2. Drag shelves to desired order
3. Order is automatically saved

Same applies to levels and positions.
