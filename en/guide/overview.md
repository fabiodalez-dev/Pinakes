# Overview

Pinakes is a complete library management system (ILS - Integrated Library System).

## Main Features

### Catalog
- Book entry with automatic ISBN lookup
- Metadata from Google Books, Open Library, SBN Italia
- Integrated Dewey Classification (1,287 categories)
- Authors, publishers, genres management
- Cover upload

### Loans
- Complete workflow: request → approval → pickup → return
- Queue and reservation management
- Automatic email notifications
- Renewals with configurable limits

### Users
- Self-service or manual registration
- 4 roles: standard, premium, staff, admin
- Personal loan history
- Email and library card verification

### Digital Resources
- `file_url` field for attached documents
- `audio_url` field for audiobooks
- External links to digital resources

### Events
- Library activity calendar
- Event pages with dedicated SEO
- Images and descriptions

## Interface

### Public Catalog
Accessible without login:
- Book search
- Availability view
- Book details

### User Area
After login:
- Loan requests
- Reservations
- Personal history
- Profile editing

### Operator Panel
For library staff:
- Loan approval
- Pickup/return management
- Book entry
- User management

### Admin Panel
For administrators:
- System configuration
- Plugin management
- Backup
- Statistics

## Navigation

Use the sidebar menu to access sections:
- **Catalog**: book search and management
- **Loans**: active loan management
- **Users**: user registry
- **Settings**: system configuration

---

## Frequently Asked Questions (FAQ)

### 1. Is Pinakes free?

Yes, Pinakes is **completely free and open source**:

- License: MIT (freedom to use, modify, distribute)
- No license fees
- No paid features
- Source code available on GitHub

You can use it for public, school, private, or corporate libraries without limitations.

---

### 2. What types of libraries can use Pinakes?

Pinakes is suitable for:

| Library Type | Main Features |
|--------------|---------------|
| **School** | Catalog, student loans, cards |
| **Public** | Self-service, reservations, events |
| **Corporate** | Internal catalog, digital resources |
| **Private** | Personal collection management |
| **Association** | Member loans, multi-user |

Scalable from a few dozen to thousands of books.

---

### 3. Do I need programming knowledge to use Pinakes?

**For daily use**: No, the interface is designed for librarians without technical skills.

**For installation**: Basic hosting skills are needed (uploading files, creating databases) or a technician for initial setup.

**For advanced customization**: Yes, code modifications require PHP knowledge.

---

### 4. Can I use Pinakes only as a catalog without loans?

Yes, there's a **catalog-only mode**:

1. Go to **Settings → Advanced**
2. Enable **"Catalog Only"**
3. All loan functions are hidden

Useful for:
- Online browsable catalogs
- Libraries without circulation
- Historical archives

---

### 5. How many books can Pinakes manage?

There's no imposed technical limit. Tested performance:

| Catalog | Performance |
|---------|-------------|
| < 1,000 books | Excellent |
| 1,000 - 10,000 | Very good |
| 10,000 - 50,000 | Good (dedicated server recommended) |
| > 50,000 | Requires server optimization |

The limiting factor is hosting, not the software.

---

### 6. Does Pinakes work on smartphones?

Yes, the interface is **fully responsive**:

- **Users**: catalog search, loan requests, profile
- **Staff**: approvals, loan management, book entry
- **Admin**: all functions (desktop recommended for complex configurations)

Works on any modern browser (Chrome, Firefox, Safari, Edge).

---

### 7. Can I import books from another system?

Yes, via **CSV import**:

1. Export from the old system in CSV format
2. Go to **Catalog → Import**
3. Map columns to Pinakes fields
4. Import

**Supported fields**: ISBN, title, authors, publisher, year, genre, Dewey, description.

---

### 8. How does automatic ISBN lookup work?

When you enter an ISBN:

1. Pinakes searches on **Google Books**
2. If not found, tries **Open Library**
3. If not found, tries **SBN Italia** (Italian catalog)
4. Automatically fills in: title, author, publisher, year, description, cover

Works for books with valid ISBN (10 or 13 digits).

---

### 9. Can I have multiple locations/libraries with a single installation?

Currently Pinakes manages **one library per installation**. For multiple locations:

**Option 1 - Separate installations**:
- One installation per location
- Separate databases
- Different URLs

**Option 2 - Use positions/shelves**:
- "Position" field to indicate the location
- Single shared catalog
- Filter by position in search

---

### 10. What languages are supported?

Pinakes supports:

- **Italian** (primary language)
- **English** (complete translation)

The interface changes language automatically based on user preferences or system settings.

**Adding new languages**: Create a new file in `locale/` following the existing JSON format.
