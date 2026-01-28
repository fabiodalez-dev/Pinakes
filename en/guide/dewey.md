# Dewey Classification

Guide to using Dewey Decimal Classification in Pinakes.

## What is Dewey Classification

Dewey Decimal Classification (DDC) is a library system for organizing publications by subject. It uses numbers from 000 to 999, with decimals for greater specificity.

## Main Classes

| Code | Class |
|------|-------|
| 000 | Computer science, information, general works |
| 100 | Philosophy and psychology |
| 200 | Religion |
| 300 | Social sciences |
| 400 | Language |
| 500 | Science |
| 600 | Technology |
| 700 | Arts and recreation |
| 800 | Literature |
| 900 | History and geography |

## Included Database

Pinakes includes **1,287 pre-loaded Dewey categories**:
- Official Italian translation
- English translation available
- Complete hierarchy up to 7 levels
- Source: OCLC DDC standards

## Usage

### Selection in Book Form

Two modes:

**1. Direct Entry**
- Type the code in the field (e.g., `823.91`)
- System automatically searches for the name
- If exact, shows code + name
- If not found, uses parent category name

**2. Hierarchical Navigation**
- Click "Browse by categories"
- Select main class
- Navigate subclasses
- Select desired code

### Code Format

Valid codes:
- Three digits: `823`
- With decimals: `823.91`, `599.9374`
- Maximum 4 decimals after the dot

Invalid codes:
- Fewer than three digits: `82`
- More than 4 decimals: `823.91234`
- Non-numeric characters: `823a`

## Search by Dewey

### In Catalog

1. Use the "Classification" filter
2. Type the code or part of it
3. Books with that code or subclasses appear

### Navigation

The catalog page allows:
- Browsing by Dewey class
- Hierarchical view
- Book count per category

## Editing Dewey Database

### Integrated Editor

The **Dewey Editor** plugin allows you to:
- Add new codes
- Modify existing names
- Delete unused codes
- Import/Export JSON

### Access

1. Install "Dewey Classification Editor" plugin
2. Go to **Admin → Dewey Editor**
3. Modify needed categories

### Source Files

Data is in `data/dewey/`:
- `dewey_completo_it.json` - Italian
- `dewey_completo_en.json` - English

## Localization

The system automatically loads the correct language:
- Italian: `dewey_completo_it.json`
- English: `dewey_completo_en.json`

Names change based on interface language.

---

## Frequently Asked Questions (FAQ)

### 1. Is it mandatory to assign a Dewey code to every book?

No, the Dewey field is optional. However, it's recommended because:
- Facilitates subject search
- Enables category browsing
- Helps physically organize the library
- It's an internationally recognized standard

**For small libraries** that don't use Dewey, you can simply leave the field empty.

---

### 2. I can't find the exact Dewey code for my book, what do I do?

You have two options:

**Option 1 - Use a more general code**:
- If you search for `823.91` and it doesn't exist, use `823.9` or `823`
- System will show parent category name

**Option 2 - Enter custom code**:
- You can type any valid code (e.g., `823.91234`)
- System accepts it even if not in database
- Will be shown with nearest parent category

---

### 3. How does Dewey hierarchical navigation work?

The "by categories" navigation guides you step by step:

1. Click **"Browse by categories"** in book form
2. Select main class (e.g., "800 - Literature")
3. System loads subclasses
4. Continue descending until you find the right code
5. Click to select

**Breadcrumb**: At top you see the complete path (e.g., "Home > 800 > 823 > 823.91").

---

### 4. Can I modify or add new Dewey codes?

Yes, with the **Dewey Classification Editor** plugin:

1. Install plugin from **Admin → Plugins**
2. Go to **Admin → Dewey Editor**
3. You can:
   - Add new codes
   - Modify existing names
   - Delete unused codes
   - Import/Export JSON

**Without plugin**: manually edit JSON files in `data/dewey/`.

---

### 5. How do I change the language of Dewey categories?

Language automatically follows interface settings:

- **Italian** → `dewey_completo_it.json`
- **English** → `dewey_completo_en.json`

**To change language**:
1. Modify user language in profile
2. Or change default language in Settings
3. Dewey names update automatically

---

### 6. How do I find books in a specific Dewey category?

**In public catalog**:
1. Use "Classification" filter
2. Type code (e.g., `800` for all literature)
3. All books with that code or subclasses appear

**For operators**:
- Search also looks in subclasses
- E.g., searching `800` finds `823`, `823.91`, etc.

---

### 7. What's the difference between 3-digit codes and decimals?

The Dewey system uses decimals to increase specificity:

| Code | Meaning | Specificity |
|------|---------|-------------|
| `800` | Literature | General |
| `823` | English fiction | More specific |
| `823.91` | Modern English novels | Very specific |
| `823.912` | English novels 1910-1945 | Ultra-specific |

**Practical rule**: more decimals = more precise. Use the detail level appropriate for your library.

---

### 8. Are Dewey codes already translated to Italian?

Yes, Pinakes includes **1,287 categories** completely translated:

- **Source**: OCLC DDC standards (Dewey Decimal Classification)
- **Italian**: Complete official translation
- **English**: Available for international libraries

Both versions are synchronized and contain the same codes.

---

### 9. How can I print Dewey position on labels?

Book labels automatically include Dewey code if present:

1. Go to book card
2. Click **"Print Label"**
3. Select format
4. Label will include:
   - Dewey code
   - Position/Shelf (if set)
   - Barcode

---

### 10. I imported books without Dewey, how do I assign them in bulk?

Currently Dewey assignment is done book by book. For large quantities:

**Recommended procedure**:
1. Use "Without classification" filter in catalog
2. Sort by genre or publisher
3. Open each book and assign code
4. Use hierarchical navigation to speed up

**Tip**: group similar books and use same code for all.

