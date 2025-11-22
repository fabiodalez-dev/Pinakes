<?php

namespace Emleons\SimRating\Renderer;

use Emleons\SimRating\Interfaces\RendererInterface;
use Emleons\SimRating\Rating;

class SvgRenderer implements RendererInterface
{
    protected Rating $rating;

    public function __construct(Rating $rating)
    {
        $this->rating = $rating;
    }

    public function render(): string
    {
        $average = $this->rating->getAverage();
        $total = $this->rating->getTotal();
        $distribution = $this->rating->getDistribution();

        return json_encode([
            'average' => round($average, 2),
            'total' => $total,
            'distribution' => array_map(fn($v) => round($v, 2), $distribution)
        ], JSON_PRETTY_PRINT);
    }
}
