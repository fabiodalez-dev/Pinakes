<?php

namespace Emleons\SimRating\Renderer;

use Emleons\SimRating\Rating;
use Emleons\SimRating\Interfaces\RendererInterface;

class HtmlRenderer implements RendererInterface
{
    protected Rating $rating;
    protected array $defaultOptions = [
        'type' => 'stars',
        'color' => '#ffc107',
        'size' => '1em',
        'show_total' => true,
        'show_average' => true,
        'show_summary' => true,
        'interactive' => false,
        'readonly' => true,
        // Bar-specific options
        'bar_height' => '20px',
        'bar_spacing' => '8px',
        'bar_border_radius' => '4px',
        'bar_show_counts' => true,
        'bar_show_percentages' => true,
        'bar_percentage_precision' => 1,
        'bar_percentage_suffix' => '%',
        'bar_animate' => true
    ];

    public function __construct(Rating $rating)
    {
        $this->rating = $rating;
    }

    public function render(): string
    {
        $options = array_merge($this->defaultOptions, $this->rating->getOptions());

        return match ($options['type']) {
            'bars' => $this->renderBars($options),
            default => $this->renderDefault($options)
        };
    }

    protected function renderDefault(array $options): string
    {
        $average = $this->rating->getAverage();
        $total = $this->rating->getTotal();

        ob_start();
?>
        <div class="sim-rating" data-rating-average="<?= htmlspecialchars($average, ENT_QUOTES) ?>">
            <?= $this->renderStars($average, $options) ?>
            <?php if ($options['show_average']): ?>
                <span class="sim-rating-average"><?= htmlspecialchars(number_format($average, 1), ENT_QUOTES) ?></span>
            <?php endif; ?>
            <?php if ($options['show_total']): ?>
                <span class="sim-rating-total">(<?= htmlspecialchars($total, ENT_QUOTES) ?> ratings)</span>
            <?php endif; ?>
        </div>
        <?php if ($options['interactive']): ?>
            <script>
                // Interactive JS would go here
            </script>
<?php endif;

        return ob_get_clean();
    }

    protected function renderStars(float $average, array $options): string
    {
        $fullStars = floor($average);
        $hasHalfStar = ($average - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($hasHalfStar ? 1 : 0);
        $output = '';

        // Full stars
        for ($i = 0; $i < $fullStars; $i++) {
            $output .= $this->renderStar('full', $i + 1, $options);
        }

        // Half star
        if ($hasHalfStar) {
            $output .= $this->renderStar('half', $fullStars + 1, $options);
        }

        // Empty stars
        for ($i = 0; $i < $emptyStars; $i++) {
            $output .= $this->renderStar('empty', $fullStars + $hasHalfStar + $i + 1, $options);
        }

        return $output;
    }

    protected function renderStar(string $type, int $position, array $options): string
    {
        $color = $type === 'empty' ? '#ddd' : $options['color'];
        $size = $options['size'];
        $percentage = $type === 'half' ? '50%' : '100%';

        return <<<SVG
        <svg width="$size" height="$size" viewBox="0 0 24 24" data-rating-value="$position">
            <defs>
                <linearGradient id="grad$position">
                    <stop offset="$percentage" stop-color="$color"/>
                    <stop offset="$percentage" stop-color="transparent"/>
                </linearGradient>
            </defs>
            <path 
                fill="url(#grad$position)" 
                stroke="$color" 
                stroke-width="1"
                d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"
            />
        </svg>
        SVG;
    }

    protected function renderBars(array $options): string
    {
        $distribution = $this->rating->getDistribution();
        $ratings = $this->rating->getRatings();

        $css = $this->generateBarCSS($options);
        $bars = $this->generateBarHTML($distribution, $ratings, $options);
        $summary = $this->renderSummary($options);

        return <<<HTML
        <div class="sim-rating-bars-container">
            <style>{$css}</style>
            {$bars}
            {$summary}
        </div>
        HTML;
    }

    protected function generateBarCSS(array $options): string
    {
        $color = $options['color'];
        $height = $options['bar_height'];
        $spacing = $options['bar_spacing'];
        $borderRadius = $options['bar_border_radius'];

        return <<<CSS
        .sim-rating-bars-container {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
        }
        .sim-rating-bar {
            display: flex;
            align-items: center;
            margin-bottom: {$spacing};
        }
        .sim-rating-bar-label {
            width: 80px;
            font-size: 0.9em;
            color: #555;
        }
        .sim-rating-bar-bg {
            flex-grow: 1;
            background: #f5f5f5;
            border-radius: {$borderRadius};
            height: {$height};
            overflow: hidden;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
        }
        .sim-rating-bar-fill {
            height: 100%;
            background: {$color};
            border-radius: {$borderRadius};
            transition: width 0.6s ease-out;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .sim-rating-bar-percent {
            width: 50px;
            text-align: right;
            font-size: 0.85em;
            color: #666;
            margin-left: 10px;
        }
        .sim-rating-bar-percent:empty {
            display: none;
        }
        .sim-rating-summary {
            margin-top: 15px;
            font-size: 0.95em;
            color: #444;
        }
        CSS;
    }

    protected function generateBarHTML(array $distribution, array $ratings, array $options): string
    {
        $html = '';
        $starLabels = [
            'five_star' => '5-star',
            'four_star' => '4-star',
            'three_star' => '3-star',
            'two_star' => '2-star',
            'one_star' => '1-star'
        ];

        $showPercent = $options['bar_show_percentages'];
        $precision = $options['bar_percentage_precision'];
        $suffix = $options['bar_percentage_suffix'];

        foreach ($starLabels as $key => $label) {
            $percentage = $distribution[$key] ?? 0;
            $count = $ratings[$key] ?? 0;

            $percentageText = number_format($percentage, $precision) . $suffix;

            $percentDiv = $showPercent
                ? "<div class=\"sim-rating-bar-percent\">{$percentageText}</div>"
                : "";

            $html .= <<<HTML
        <div class="sim-rating-bar">
            <div class="sim-rating-bar-label">
                {$label}: {$count}
            </div>
            <div class="sim-rating-bar-bg">
                <div class="sim-rating-bar-fill" style="width: {$percentage}%"></div>
            </div>
            {$percentDiv}
        </div>
        HTML;
        }

        return $html;
    }


    protected function renderSummary(array $options): string
    {
        if (!$options['show_summary']) {
            return '';
        }

        $average = $this->rating->getAverage();
        $total = $this->rating->getTotal();

        return <<<HTML
        <div class="sim-rating-summary">
            Average: <strong>{$average}</strong> from <strong>{$total}</strong> total ratings
        </div>
        HTML;
    }
}
