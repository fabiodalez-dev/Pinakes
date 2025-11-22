<p align="center">
  <img src="src/img/banner-min.png" alt="sim-rating" width="800">
</p>

# Sim-Rating - A Simple 5-Star Rating System for PHP

[![Latest Version](https://img.shields.io/packagist/v/emleons/sim-rating.svg)](https://packagist.org/packages/emleons/sim-rating)
[![Tests](https://github.com/emleonstz/sim-rating/actions/workflows/tests.yml/badge.svg?event=push)](https://github.com/emleonstz/sim-rating/actions/workflows/tests.yml)
[![License](https://img.shields.io/github/license/emleonstz/sim-rating.svg)](https://github.com/emleonstz/sim-rating/blob/main/LICENSE)

Sim-Rating is a lightweight,PHP library for displaying and calculating 5-star ratings. It supports multiple display formats (stars, bars, JSON) and is highly customizable and works with any php frame work.

<div align="center">

### Default Star Rating
<img src="src/img/s1.png" alt="5-star rating display" width="600">

```php
// Default star output
```

### Custom Bar Rating  
<img src="src/img/s2.png" alt="Bar rating display" width="600">

</div>


## Features

- 🎯 Works with PHP Framework and PHP plain implementation
- ⭐ Multiple output formats (HTML, JSON, SVG)
- 🎨 Customizable colors, sizes and styles
- 📊 Calculate averages, totals and distributions
- 📱 Responsive and mobile-friendly
- ✅ 100% unit tested

## Installation

Install via Composer:

```bash
composer require emleons/sim-rating
```

### 🌟 Basic Implementation
```php
require('vendor/autoload.php');
use Emleons\SimRating\Rating;

// Initialize with rating counts (all keys required)
$ratings = [
    'one_star' => 10,    // Required
    'two_star' => 20,    // Required
    'three_star' => 30,  // Required
    'four_star' => 40,   // Required
    'five_star' => 50    // Required
];

$rating = new Rating($ratings);

// Display 5-star rating
echo $rating->render(); // Defaults to HTML stars
```

### 📊 Output Formats
```php
// As interactive HTML stars
echo $rating->render('html', [
    'interactive' => true
]);

// As JSON data
$jsonOutput = $rating->render('json');
// {
//   "average": 3.67,
//   "total": 150,
//   "distribution": {
//     "five_star": 33.33,
//     "four_star": 26.67,
//     ...
//   }
// }

// As percentage bars
echo $rating->render('html', [
    'type' => 'bars',
    'bar_height' => '25px'
]);
```

### 🎨 Customization Options
#### Star Display Options
```php
new Rating($ratings, [
    'type' => 'stars',       // Display type
    'color' => '#6a5acd',    // Star color
    'size' => '1.5rem',      // Size
    'show_average' => false, // Hide average
    'show_total' => true     // Show count
]);
```

#### Bar Display Options
```php
new Rating($ratings, [
    'type' => 'bars',
    'color' => '#4a90e2',          // Bar color
    'bar_height' => '20px',        // Bar thickness
    'bar_show_percentages' => true,// Show percentages
    'show_summary' => true         // Show summary
]);
```

### ⚙️ All Configuration Options

| Option | Type | Required | Default | Description |
|--------|------|----------|---------|-------------|
| `type` | string | Yes | `stars` | `stars` or `bars` |
| `color` | string | Yes | `#ffc107` | Main color |
| `size` | string | Yes | `1em` | Display size |
| `show_average` | bool | No | `true` | Show average rating |
| `show_total` | bool | No | `true` | Show total ratings |
| `show_summary` | bool | Yes | `false` | Show summary text |
| `interactive` | bool | Yes | `false` | Make ratings clickable |
| `bar_height` | string | No | `20px` | Bar thickness |
| `bar_spacing` | string | No | `8px` | Space between bars |
| `bar_show_percentages` | bool | No | `true` | Show percentages |

### 🔄 Updating Options
```php
$rating->setOptions([
    'color' => '#e74c3c', // Change to red
    'size' => '2rem',     // Larger size
    'type' => 'bars'      // Switch to bars
]);

// Get current options
$currentOptions = $rating->getOptions();
```


#### Laravel Blade
```php
@php
    $rating = new \Emleons\SimRating\Rating($product->ratings, [
        'interactive' => true,
        'show_summary' => false
    ]);
@endphp

{!! $rating->render() !!}
```

### 💡 Pro Tips
1. Always include all 5 star rating keys
2. Set `show_summary` based on your display needs
3. Use `interactive` only when you need user ratings
4. For bars, customize heights and spacing to match your design

### 🚨 Common Errors
```php
// ❌ Missing required keys
$invalidRatings = [
    'one_star' => 10,
    // Missing other star counts
];

// ❌ Invalid color format
new Rating($ratings, ['color' => 'red']); // Use hex codes

// ❌ Wrong option name
new Rating($ratings, ['colour' => '#fff']); // Should be 'color'
```

## Display Types

### Stars
```php
echo $rating->render('html'); // Default star display
```

### Bars
```php
echo $rating->render('html', ['type' => 'bars']);
```

### JSON Output
```php
echo $rating->render('json');
// {
//   "average": 3.67,
//   "total": 150,
//   "distribution": {
//     "one_star": 6.67,
//     "two_star": 13.33,
//     ...
//   }
// }
```

## Advanced Usage

### Custom Templates
```php
// Create custom template at path/to/template.php
echo $rating->render('html', [
    'template' => 'path/to/template.php'
]);
```

### Using in Frameworks

**Laravel:**
```php
// In controller
public function show(Product $product)
{
    return view('products.show', [
        'rating' => new \SimRating\Rating($product->ratings)
    ]);
}

// In Blade
{!! $rating->render() !!}
```

**Symfony:**
```php
// In controller
public function show(Product $product): Response
{
    return $this->render('product/show.html.twig', [
        'rating' => new \SimRating\Rating($product->getRatings())
    ]);
}

// In Twig
{{ rating.render()|raw }}
```

**CodeIgniter 4:**
```php
// In your Controller:
public function showProduct($productId)
{
    $productModel = new \App\Models\ProductModel();
    $product = $productModel->find($productId);
    
    $rating = new \Emleons\SimRating\Rating($product['ratings'], [
        'color' => '#f39c12', 
        'size' => '1.5rem'
    ]);
    
    return view('product_view', [
        'product' => $product,
        'ratingStars' => $rating->render('html')
    ]);
}

// In your View (PHP syntax):
<div class="product-rating">
    <?= $ratingStars ?>
    <small><?= number_format($rating->getAverage(), 1) ?> average (<?= $rating->getTotal() ?> ratings)</small>
</div>

// Or in Blade-style syntax if using CodeIgniter's View Decorator:
<div class="product-rating">
    {!! $ratingStars !!}
    <small>{{ number_format($rating->getAverage(), 1) }} average ({{ $rating->getTotal() }} ratings)</small>
</div>
```

## Testing

Run the test suite:

```bash
./vendor/bin/phpunit
```

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

## License

MIT License. See [LICENSE](LICENSE) for more information.