# Theme Customization

Pinakes includes a theme system that allows customizing the application's appearance.

## Predefined Themes

The application includes 10 ready-to-use themes:

| Theme | Description |
|-------|-------------|
| **Pinakes Classic** | Default theme with magenta tones |
| **Minimal** | Clean and minimalist design |
| **Ocean Blue** | Professional blue tones |
| **Forest Green** | Natural green tones |
| **Sunset Orange** | Warm orange tones |
| **Burgundy** | Elegant burgundy red |
| **Teal Professional** | Professional teal |
| **Slate Gray** | Sober slate gray |
| **Coral Warm** | Warm and welcoming coral |
| **Navy Classic** | Classic navy blue |

## Theme Management

### Accessing Themes

1. Go to **Settings → Themes**
2. View the grid with all available themes

### Activating a Theme

1. Find the desired theme
2. Click **Activate**
3. The change is immediate for all users

The active theme shows a green "Active" badge.

### Information Displayed

For each theme the card shows:
- **Name** and **version**
- **Author**
- **Description**
- **Color palette** (preview of 4 colors)

## Color Editor

Each theme can be customized by modifying 4 colors.

### Accessing the Editor

1. In the themes grid, click **Customize** on the theme
2. The customization page opens

### The 4 Configurable Colors

| Color | Use | Default |
|-------|-----|---------|
| **Primary** | Links, accents, interactive elements | `#d70161` |
| **Secondary** | Main buttons (e.g., "Request Loan") | `#111827` |
| **CTA Buttons** | Buttons in cards (e.g., "Details") | `#d70262` |
| **Button Text** | Text color in CTA buttons | `#ffffff` |

### Real-Time Preview

As you modify colors, the preview on the right shows:
- An example link with primary color
- The CTA button with background and text
- The primary button with secondary color

### Automatic Text Selection

The "magic wand" button next to button text color automatically calculates whether to use white or black based on background brightness.

## Accessibility Check (WCAG)

The editor includes an automatic contrast check between button background and text.

### Compliance Levels

| Status | Ratio | Meaning |
|--------|-------|---------|
| **Green** | ≥ 4.5:1 | WCAG AA compliant (normal text) |
| **Yellow** | ≥ 3.0:1 | AA only for large text |
| **Red** | < 3.0:1 | Insufficient, difficult to read |

The system recommends maintaining at least a 4.5:1 ratio to ensure readability.

## Custom CSS

For advanced modifications beyond colors:

### Adding CSS

1. In the **Advanced** section you'll find the custom CSS field
2. Enter your CSS rules
3. Save

### Common Examples

#### Hide an element
```css
.element-to-hide {
  display: none !important;
}
```

#### Change font
```css
body {
  font-family: 'Georgia', serif !important;
}
```

#### Customize header
```css
header {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
```

#### Round card corners
```css
.card {
  border-radius: 16px;
}
```

### Best Practices

- Use `!important` only when necessary
- Test on mobile and desktop
- Avoid overriding too many base rules
- Document modifications with CSS comments

## Color Reset

To return to the theme's original colors:

1. Click **Reset** on the customization page
2. Confirm the operation
3. All colors return to theme defaults

> **Note**: Reset does not delete custom CSS.

## Troubleshooting

### Colors not being applied

1. Clear browser cache (Ctrl+Shift+R or Cmd+Shift+R)
2. Verify custom CSS isn't overriding colors
3. Check that theme is actually active

### Contrast is insufficient

1. Use the automatic text selector (magic wand)
2. Choose colors with greater brightness difference
3. Avoid combinations like yellow on white or dark blue on black

### Custom CSS not working

1. Verify CSS syntax (parentheses, semicolons)
2. Use browser console (F12) to see errors
3. Ensure selectors are correct

---

## Frequently Asked Questions (FAQ)

### 1. How do I change my library's theme?

Changing theme is immediate:

1. Go to **Settings → Themes**
2. View the grid with 10 available themes
3. Click **Activate** on the desired theme
4. The change is immediate for all users

**Available themes:**
Pinakes Classic, Minimal, Ocean Blue, Forest Green, Sunset Orange, Burgundy, Teal Professional, Slate Gray, Coral Warm, Navy Classic.

---

### 2. Can I create a completely custom theme?

Currently there's no system to create new themes from the interface. Options:

**Option 1 - Customize existing theme:**
1. Activate a base theme (e.g., Minimal)
2. Go to **Customize**
3. Modify the 4 colors
4. Add custom CSS in Advanced section

**Option 2 - Complete CSS:**
In the custom CSS field you can override any style:
```css
:root {
  --color-primary: #your-color;
  --color-background: #ffffff;
}
```

---

### 3. How does WCAG accessibility checking work?

The color editor automatically verifies contrast between button background and text:

| Ratio | Status | Meaning |
|-------|--------|---------|
| ≥ 4.5:1 | Green | WCAG AA compliant |
| 3.0-4.5:1 | Yellow | Large text only |
| < 3.0:1 | Red | Not compliant |

**Calculation:**
- Based on WCAG formula for relative luminance
- Considers CTA color and button text color

**Recommendation:** Always maintain ≥ 4.5:1 for universal readability.

---

### 4. What exactly does the "magic wand" button do?

The button automatically calculates whether to use white or black text:

**Logic:**
1. Calculates the luminance of button background color
2. If luminance > 0.5 → black text (#000000)
3. If luminance ≤ 0.5 → white text (#ffffff)

**When to use it:**
- After choosing a new CTA color
- If WCAG check shows yellow/red
- To ensure automatic readability

---

### 5. How do I add a custom font?

In the custom CSS field:

**Google Fonts:**
```css
@import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap');

body {
  font-family: 'Roboto', sans-serif !important;
}
```

**Local font:**
```css
@font-face {
  font-family: 'MyFont';
  src: url('/assets/fonts/myfont.woff2') format('woff2');
}

body {
  font-family: 'MyFont', sans-serif !important;
}
```

Upload the font file to `public/assets/fonts/`.

---

### 6. Why aren't my CSS modifications being applied?

**Common causes:**

| Problem | Solution |
|---------|----------|
| Browser cache | Ctrl+Shift+R (hard refresh) |
| Insufficient specificity | Add `!important` |
| Wrong selector | Inspect element (F12) to find correct class |
| Syntax error | Check parentheses and semicolons |

**Debug:**
1. Open browser console (F12)
2. "Console" tab for CSS errors
3. "Elements" tab to inspect applied styles

---

### 7. How do I restore original theme colors?

1. Go to **Settings → Themes → Customize** (on active theme)
2. Click **Reset**
3. Confirm the operation

**What gets restored:**
- The 4 colors return to theme's original values
- Custom CSS is **NOT deleted**

To also delete CSS, manually clear the field.

---

### 8. Do colors change only for me or for all users?

Theme modifications are **global for all users**.

**Behavior:**
- Activating a theme → immediate for all
- Modifying colors → immediate for all
- Custom CSS → immediate for all

There is **no** per-user theme system. Everyone sees the same appearance.

---

### 9. How do I hide interface elements I don't use?

In custom CSS, use `display: none`:

**Common examples:**
```css
/* Hide events section */
.events-section {
  display: none !important;
}

/* Hide wishlist button */
.btn-wishlist {
  display: none !important;
}

/* Hide footer */
footer {
  display: none !important;
}
```

**Find the selector:**
1. F12 → Inspect element
2. Copy the element's class
3. Add to CSS

---

### 10. Can I have different themes for desktop and mobile?

Not natively, but you can use media queries in custom CSS:

```css
/* Desktop */
@media (min-width: 1024px) {
  header {
    background: linear-gradient(to right, #667eea, #764ba2);
  }
}

/* Mobile */
@media (max-width: 1023px) {
  header {
    background: #667eea;
  }
}
```

**Note:** The 4 main colors don't support media queries from the visual editor, only from custom CSS.
