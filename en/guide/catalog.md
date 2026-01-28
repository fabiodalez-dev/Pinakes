# Catalog Management

Complete guide to entering and managing books in the Pinakes catalog.

## Adding a New Book

### Access

**Path**: Catalog → New Book

### ISBN Search

The fastest method to add a book:

1. Enter the ISBN in the **"ISBN or EAN Code"** field
2. Click **Import Data** (or press Enter)
3. The system searches for metadata on:
   - **Google Books**
   - **Open Library**
   - **SBN Italia** (National Library Service)
4. If found, fields are automatically pre-filled
5. Verify and modify data if needed
6. Click **Save Book**

**Alternative Sources**: After import, you can click "View alternatives" to see data from other sources and choose which to use.

### Manual Entry

If ISBN returns no results or for books without ISBN:

1. Fill in the required **Title** field
2. Add **Authors** (select existing or create new)
3. Fill in other relevant fields
4. Upload the **cover** (optional)
5. Click **Save Book**

---

## Book Form Fields

The form is organized into 7 sections. Below is each field with its description.

### Section 1: Basic Information

| Field | Description | Required |
|-------|-------------|----------|
| **Title** | Main book title | Yes |
| **Subtitle** | Subtitle or secondary title | No |
| **ISBN 10** | 10-digit ISBN code (format before 2007) | No |
| **ISBN 13** | 13-digit ISBN code (current format) | No |
| **Edition** | Edition number or description (e.g., "First edition", "Revised edition") | No |
| **Publication Date** | Original publication date (e.g., "August 26, 2025") | No |
| **Publication Year** | Numeric year for filters and sorting (e.g., 2025) | No |
| **EAN** | European Article Number (often matches ISBN-13) | No |
| **Language** | Book language(s) (e.g., "English", "Italian") | No |
| **Publisher** | Publishing house - search existing or type to create new | No |
| **Authors** | One or more authors - search existing or type to create new | No |
| **Availability** | General book status (see table below) | Yes |
| **Description** | Plot, synopsis or content description | No |

#### Availability Values

| Value | Meaning |
|-------|---------|
| `Available` | Book available for loan |
| `Not Available` | Temporarily unavailable |
| `On Loan` | Currently on loan |
| `Reserved` | Reserved for a user |
| `Damaged` | Damaged book |
| `Lost` | Lost book |
| `Under Repair` | Being restored/repaired |
| `Out of Catalog` | Removed from active catalog |
| `To Inventory` | Awaiting complete cataloging |

**Note**: The `Availability` status is independent of physical copy states. The system automatically calculates `available_copies` based on individual copy states in the `copies` table.

### Section 2: Dewey Classification

| Field | Description | Required |
|-------|-------------|----------|
| **Dewey Code** | Dewey Decimal classification code | No |
| **Root** | Main genre level (e.g., Prose, Poetry, Drama) | No |
| **Genre** | Literary genre (depends on root) | No |
| **Subgenre** | Specific subgenre (depends on genre) | No |
| **Keywords** | Comma-separated tags to facilitate search | No |

#### Dewey Classification

Two entry modes:

**Direct entry:**
1. Type the Dewey code in the field (e.g., `599.93`, `004.6782`)
2. Click **Add**
3. The system automatically searches for the corresponding name
4. The code appears as a blue chip with name

**Category navigation:**
1. Expand "Or browse by categories"
2. Select the main class (0-9)
3. Navigate subcategories
4. Click on the desired code

**Accepted format:**
- 1 to 3 whole digits: `000` - `999`
- Up to 4 decimals: `599.9374`
- Examples: `823`, `813.54`, `641.5945`

**Note**: Pinakes' Dewey system contains 1,287 entries. You can enter any valid code, even if not in the predefined list.

### Section 3: Acquisition Details

| Field | Description | Required |
|-------|-------------|----------|
| **Acquisition Date** | Date the book entered the library | No |
| **Acquisition Type** | Acquisition method (e.g., Purchase, Donation, Interlibrary loan) | No |
| **Price (€)** | Purchase price or estimated value | No |

### Section 4: Physical Details

| Field | Description | Required |
|-------|-------------|----------|
| **Format** | Binding type (e.g., Hardcover, Paperback, Pocket) | No |
| **Number of Pages** | Book page count | No |
| **Weight (kg)** | Weight in kilograms (e.g., 0.450) | No |
| **Dimensions** | Physical format (e.g., 21x14 cm) | No |
| **Total Copies** | Number of physical copies owned | Yes (default: 1) |

**Total Copies Note**: In edit mode, you cannot reduce the number of copies below those currently in use (on loan, lost, damaged). The `available_copies` field is calculated automatically by the system.

### Section 5: Library Management

| Field | Description | Required |
|-------|-------------|----------|
| **Inventory Number** | Unique inventory identification code (e.g., INV-2024-001) | No |
| **Series** | Editorial series name (e.g., "The Classics", "Penguin Classics") | No |
| **Series Number** | Sequential number in the series | No |
| **File URL** | Link to digital file (PDF, ePub) if available | No |
| **Audio URL** | Link to audiobook if available | No |
| **Notes** | Additional notes or special observations | No |

### Section 6: Book Cover

Cover upload via drag-and-drop or file selection.

**Supported formats**: JPG, PNG, WebP
**Maximum size**: 5 MB
**Recommended resolution**: at least 300x450 pixels

**Features:**
- Drag the image to the upload area
- Or click to select the file
- Immediate preview after selection
- "Remove" button to delete existing cover

**Cover from ISBN**: If available, the cover is automatically downloaded during ISBN import from Google Books or Open Library.

### Section 7: Physical Location in Library

| Field | Description | Required |
|-------|-------------|----------|
| **Shelf** | Shelf where the book is located | No |
| **Shelf Level** | Level/shelf of the bookcase (depends on shelf) | No |
| **Position Number** | Order number on the shelf | No |
| **Calculated Location** | Automatically generated location string (read-only) | - |

**Automatic location:**
- The system generates the location by combining: `[Shelf Code]-[Level]-[Position]`
- Example: `A1-L2-015` = Shelf A1, Level 2, Position 15
- Click **Generate automatically** to get the next available position
- Click **Suggest location** for a proposal based on Dewey classification

**Note**: Physical position is independent of Dewey classification and indicates where the book is physically located on shelves.

---

## Author Management

### Selecting Existing Author

1. Start typing the name in the **Authors** field
2. A list of matching authors appears
3. Click on the author to select
4. Appears as a chip with X button to remove

### Creating New Author

1. Type the author's full name
2. If it doesn't exist, the option "Add [name] as new author" appears
3. Click to confirm creation
4. The author is automatically created when saving the book

### Name Normalization

The system automatically normalizes formats:
- `SMITH, John` → `John Smith`
- `smith john` → `John Smith`

### Multiple Authors

A book can have multiple authors:
1. Select the first author
2. Continue typing to add others
3. Each author appears as a separate chip
4. Author order is preserved

---

## Publisher Management

### Selecting Existing Publisher

1. Type in the **Publisher** field
2. A list of matching publishers appears
3. Click to select
4. The publisher appears as a chip

### Creating New Publisher

1. Type the publisher name
2. If it doesn't exist, the name is used as a new publisher
3. The publisher is created when saving the book

### Single Publisher

Unlike authors, a book can have only one publisher. Selecting a new publisher replaces the previous one.

---

## Copy Management

Each book can have multiple physical copies, each with its own state and position.

### Adding Copies

1. Open the book card
2. Go to the **Copies** section
3. Click **Add Copy**
4. Specify:
   - Inventory number (required, unique)
   - Initial state
   - Notes about the copy
   - Physical position

### Copy States

| State | Description | Loanable |
|-------|-------------|----------|
| `available` | Ready for loan | Yes |
| `on_loan` | Currently on loan (automatic) | No |
| `reserved` | Reserved for future loan (automatic) | No |
| `maintenance` | Temporarily unavailable | No |
| `under_restoration` | Being restored/repaired | No |
| `lost` | Lost copy | No |
| `damaged` | Damaged unusable copy | No |
| `in_transit` | Being transferred between locations | No |

**Automatic states**: `on_loan` and `reserved` are managed by the loan system and cannot be set manually.

### Deleting Copies

A copy can only be deleted if in state:
- `lost`
- `damaged`
- `maintenance`

This prevents accidental deletion of copies in use.

---

## Covers

### Manual Upload

1. In the book card, go to the **Cover** section
2. Drag the image to the upload area
3. Or click to select the file
4. Preview appears immediately
5. Save the book to confirm

### Cover from ISBN

During ISBN import, the cover is automatically downloaded if available:
- **Google Books**: high resolution
- **Open Library**: various resolutions

### Removing Cover

1. Click **Remove** under the preview
2. The cover is deleted when saving

---

## Import/Export

### Import from CSV

For bulk imports:

1. Go to **Catalog → Import**
2. Upload the CSV file
3. Map CSV columns to database fields
4. Verify preview of first records
5. Confirm import

**Supported CSV fields:**
- title, subtitle
- isbn10, isbn13, ean
- author (name)
- publisher (name)
- publication_year
- description
- genre
- dewey_classification
- page_count
- format
- language

### Catalog Export

1. Go to **Catalog → Export**
2. Select format (CSV, JSON)
3. Select fields to export
4. Click **Export**
5. File is downloaded

---

## Search and Filters

### Quick Search

The search bar searches in:
- Title
- Subtitle
- ISBN (10 and 13)
- EAN
- Authors
- Publisher

### Advanced Filters

| Filter | Description |
|--------|-------------|
| **Author** | Filter by specific author |
| **Publisher** | Filter by publishing house |
| **Genre** | Filter by literary genre |
| **Year** | Publication year range |
| **Availability** | Only available/unavailable books |
| **Shelf** | Filter by physical location |
| **Dewey Classification** | Filter by Dewey code or category |

---

## Troubleshooting

### ISBN returns no results

1. Verify the ISBN is correct (10 or 13 digits)
2. Try without hyphens
3. The book may not be in online databases
4. Proceed with manual entry

### Cover not uploaded

Possible causes:
1. File too large (max 5 MB)
2. Unsupported format (use JPG, PNG, WebP)
3. Connection error

### Duplicate Author/Publisher

The system tries to prevent duplicates, but if you find one:
1. Go to **Management → Authors** or **Management → Publishers**
2. Find the duplicate element
3. Use the **Merge** function to consolidate

### Dewey not found

If the Dewey code is not in the list:
1. You can still enter it manually
2. The system saves any valid code
3. It will be displayed without a descriptive name

---

## Frequently Asked Questions (FAQ)

### 1. How do I add a book without ISBN?

Many old books, local publications, or self-published works don't have ISBN. To add them:

1. Go to **Catalog → New Book**
2. Leave the ISBN field empty
3. Manually fill in the **Title** field (required)
4. Add **Author** and **Publisher** if known
5. Upload a cover photo if available
6. Click **Save Book**

The book will still be searchable and manageable like all others.

---

### 2. Can I modify a book after saving it?

Yes, you can modify any book at any time:

1. Search for the book in the catalog
2. Click on the book card
3. Click the **Edit** button (pencil icon)
4. Modify desired fields
5. Click **Save Book**

**Note**: Some fields like total copies have restrictions if there are active loans.

---

### 3. How do I manage multiple copies of the same book?

Pinakes distinguishes between the "book" (bibliographic record) and "copies" (physical specimens):

1. Open the book card
2. In the **Copies** section, click **Add Copy**
3. Assign a unique **Inventory Number** to each copy
4. Each copy can have different state and position

**Example**: If you have 3 copies of "The Name of the Rose", you have 1 book and 3 copies. Each copy can be loaned independently.

---

### 4. ISBN finds nothing, what do I do?

If ISBN search returns no results:

1. **Verify ISBN**: Check it's correct (10 or 13 digits, no spaces)
2. **Try EAN**: Some books use EAN instead of ISBN
3. **Limited sources**: Google Books, Open Library and SBN may not have all books
4. **Enter manually**: Fill in fields by hand using information from the physical book

**Tip**: For Italian books not found, try searching on the OPAC SBN site (opac.sbn.it) to copy metadata.

---

### 5. How does Dewey classification work?

Dewey classification organizes books by subject in 10 main classes (000-999):

| Class | Subject |
|-------|---------|
| 000 | Computer science and general works |
| 100 | Philosophy and psychology |
| 200 | Religion |
| 300 | Social sciences |
| 400 | Language |
| 500 | Natural sciences |
| 600 | Technology |
| 700 | Arts and sports |
| 800 | Literature |
| 900 | History and geography |

**Two ways to enter**:
- **Direct**: Type the code (e.g., `853.914` for contemporary Italian fiction)
- **Navigation**: Use "Browse by categories" to explore the hierarchy

---

### 6. How do I delete a book from the catalog?

To delete a book:

1. Open the book card
2. Click **Delete** (trash icon)
3. Confirm deletion

**Warning**: You cannot delete a book if:
- It has copies currently on loan
- It has active reservations

First close the loans and cancel the reservations.

---

### 7. Can I import books from an Excel file?

Yes, via the CSV Import function:

1. Export the Excel file to CSV format
2. Go to **Catalog → Import**
3. Upload the CSV file
4. Map columns to Pinakes fields
5. Verify preview and confirm

**Required fields**: At least the `title` field must be present.

---

### 8. How do I quickly find a specific book?

Pinakes offers several search options:

- **Quick search**: Type in the search bar (searches title, author, ISBN, publisher)
- **Advanced filters**: Use filters for author, publisher, genre, year, shelf
- **Dewey code**: Filter by thematic classification
- **ISBN scanning**: If you have a scanner, scan the barcode

**Tip**: Search supports partial matches (e.g., "ros" finds "Rossi" and "The Rose").

---

### 9. The cover won't upload, why?

Check these points:

1. **File size**: Maximum 5 MB
2. **Format**: Only JPG, PNG or WebP
3. **Connection**: Verify internet connection
4. **Permissions**: The `uploads` folder must be writable

**Alternative solution**: If upload keeps failing, reduce image size with an editor before uploading.

---

### 10. How do I change a book's physical location?

To move a book (or copy) to a different shelf:

1. Open the book card
2. Go to the **Physical Location** section
3. Select the new **Shelf** and **Level**
4. Modify **Position Number** if needed
5. Click **Save**

To move a single copy (if you have multiple), edit the specific copy from the Copies section.
