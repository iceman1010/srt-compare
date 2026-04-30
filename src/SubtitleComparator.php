<?php

namespace App;

use Done\Subtitles\Subtitles;
use Done\Subtitles\Code\Helpers;

class SubtitleComparator
{
    private array $subtitles1;
    private array $subtitles2;
    private array $comparisonData;

    public function __construct(string $file1, string $file2)
    {
        $this->subtitles1 = $this->loadSubtitles($file1);
        $this->subtitles2 = $this->loadSubtitles($file2);
        $this->createComparisonData();
    }

    private function loadSubtitles(string $filePath): array
    {
        $subtitles = Subtitles::loadFromFile($filePath);

        $parsed = [];
        $internalFormat = $subtitles->getInternalFormat();
        
        foreach ($internalFormat as $index => $subtitle) {
            // Convert from microseconds to the format expected by our UI
            // The internal format uses microseconds
            $start = $subtitle['start'];
            $end = $subtitle['end'];
            
            // Get the text (join lines with space)
            $text = implode(' ', $subtitle['lines']);

            // The parser returns items with 0-based index, but we want to store with 1-based index for display
            $parsed[] = [
                'index' => $index + 1,
                'start' => $start,
                'end' => $end,
                'text' => $text
            ];
        }

        return $parsed;
    }

    private function createComparisonData(): void
    {
        $maxCount = max(count($this->subtitles1), count($this->subtitles2));
        $this->comparisonData = [];

        for ($i = 0; $i < $maxCount; $i++) {
            $caption1 = isset($this->subtitles1[$i]) ? $this->subtitles1[$i] : null;
            $caption2 = isset($this->subtitles2[$i]) ? $this->subtitles2[$i] : null;

            $this->comparisonData[] = [
                'index' => $i + 1,
                'left' => $caption1,
                'right' => $caption2
            ];
        }
    }

    public function getComparisonData(): array
    {
        return $this->comparisonData;
    }

    public function getTotalCaptions(): int
    {
        return count($this->comparisonData);
    }
}