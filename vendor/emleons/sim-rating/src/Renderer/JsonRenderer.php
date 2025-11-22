<?php

namespace Emleons\SimRating\Renderer;

use Emleons\SimRating\Interfaces\RendererInterface;
use Emleons\SimRating\Rating;

class JsonRenderer implements RendererInterface
{
    protected Rating $rating;
    
    public function __construct(Rating $rating)
    {
        $this->rating = $rating;
    }
    
    public function render(): string
    {
        return json_encode([
            'average' => $this->rating->getAverage(),
            'total' => $this->rating->getTotal(),
            'distribution' => $this->rating->getDistribution()
        ]);
    }
}