<?php

namespace Emleons\SimRating;

use Emleons\SimRating\Interfaces\RendererInterface;
use Emleons\SimRating\Renderer\HtmlRenderer;
use Emleons\SimRating\Renderer\JsonRenderer;
use Emleons\SimRating\Renderer\SvgRenderer;

class Rating
{
    protected array $ratings;
    protected array $options;

    public function __construct(array $ratings = [], array $options = [])
    {
        $this->validateRatings($ratings);
        $this->ratings = $ratings;
        $this->options = array_merge($this->getDefaultOptions(), $options);
    }

    public function setRatings(array $ratings): self
    {
        $this->validateRatings($ratings);
        $this->ratings = $ratings;
        return $this;
    }

    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    public function getAverage(): float
    {
        $total = $sum = 0;

        foreach ($this->ratings as $stars => $count) {
            $starValue = $this->getStarValue($stars);
            $sum += $starValue * $count;
            $total += $count;
        }

        return $total > 0 ? round($sum / $total, 2) : 0;
    }

    public function getTotal(): int
    {
        return array_sum($this->ratings);
    }

    public function getDistribution(): array
    {
        $total = $this->getTotal();
        $distribution = [];

        foreach ($this->ratings as $stars => $count) {
            $distribution[$stars] = $total > 0 ? ($count / $total) * 100 : 0;
        }

        return $distribution;
    }

    public function render(string $type = 'html'): string
    {
        $renderer = $this->createRenderer($type);
        return $renderer->render();
    }

    protected function createRenderer(string $type): RendererInterface
    {
        switch (strtolower($type)) {
            case 'json':
                return new JsonRenderer($this);
            case 'svg':
                return new SvgRenderer($this);
            case 'html':
            default:
                return new HtmlRenderer($this);
        }
    }

    protected function getDefaultOptions(): array
    {
        return [
            'type' => 'stars',
            'style' => 'css', // css, svg, or font-based
            'color' => '#ffc107',
            'size' => '1em',
            'show_total' => true,
            'show_average' => true,
            'interactive' => false,
            'readonly' => true,
            'use_cdn' => true,
            'template' => null,
        ];
    }

    protected function validateRatings(array $ratings): void
    {
        $validKeys = ['one_star', 'two_star', 'three_star', 'four_star', 'five_star'];

        foreach ($ratings as $key => $value) {
            if (!in_array($key, $validKeys)) {
                throw new \InvalidArgumentException("Invalid rating key: $key");
            }

            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException("Rating count must be a positive integer");
            }
        }
    }

    protected function getStarValue(string $starKey): int
    {
        // Convert 'one_star' to 1, 'two_star' to 2, etc.
        $map = [
            'one_star' => 1,
            'two_star' => 2,
            'three_star' => 3,
            'four_star' => 4,
            'five_star' => 5
        ];

        return $map[$starKey] ?? 0;
    }

    // Getters for renderers
    public function getRatings(): array
    {
        return $this->ratings;
    }
    public function getOptions(): array
    {
        return $this->options;
    }
}
