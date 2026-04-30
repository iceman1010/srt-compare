<?php

namespace App;

class TerminalUI
{
    private array $comparisonData;
    private int $currentRow;
    private int $viewportHeight;
    private ?int $terminalWidth;
    private ?int $terminalHeight;

    public function __construct(array $comparisonData)
    {
        $this->comparisonData = $comparisonData;
        $this->currentRow = 0;
        $this->viewportHeight = 0;
        $this->terminalWidth = null;
        $this->terminalHeight = null;
    }

    public function render(): void
    {
        // Get terminal size
        $size = $this->getTerminalSize();
        $this->terminalWidth = $size[0];
        $this->terminalHeight = $size[1];

        // Calculate viewport height (reserve lines for header, footer, and padding)
        $this->viewportHeight = max(1, $this->terminalHeight - 4);

        // Ensure currentRow is within bounds
        $this->currentRow = max(0, min($this->currentRow, count($this->comparisonData) - 1));

        // Adjust currentRow so that the viewport shows a valid range
        if ($this->currentRow + $this->viewportHeight > count($this->comparisonData)) {
            $this->currentRow = max(0, count($this->comparisonData) - $this->viewportHeight);
        }

        // Main render loop
        while (true) {
            $this->clearScreen();
            $this->renderHeader();
            $this->renderCaptions();
            $this->renderFooter();

            $key = $this->readKey();
            if ($key === null) {
                continue;
            }

            switch ($key) {
                case 'q':
                case "\x03": // Ctrl+C
                    return;
                case 'j':
                case "\x1b[B": // Down arrow
                    $this->scrollDown();
                    break;
                case 'k':
                case "\x1b[A": // Up arrow
                    $this->scrollUp();
                    break;
                case "\x1b[5~": // Page Up
                    $this->scrollPageUp();
                    break;
                case "\x1b[6~": // Page Down
                    $this->scrollPageDown();
                    break;
                case "\x1b[H": // Home
                    $this->scrollToTop();
                    break;
                case "\x1b[F": // End
                    $this->scrollToBottom();
                    break;
                default:
                    // Ignore other keys
                    break;
            }
        }
    }

    private function getTerminalSize(): array
    {
        // Try to get terminal size using posix_ttysize if available
        if (function_exists('posix_ttysize') && posix_isatty(STDIN)) {
            $size = posix_ttysize(STDIN);
            if ($size !== false && is_array($size) && count($size) === 2) {
                return [$size[0], $size[1]]; // [columns, rows]
            }
        }

        // Fallback to using environment variables
        $columns = getenv('COLUMNS');
        $lines = getenv('LINES');
        if ($columns !== false && $lines !== false && is_numeric($columns) && is_numeric($lines)) {
            return [(int)$columns, (int)$lines];
        }

        // Default fallback
        return [80, 24];
    }

    private function clearScreen(): void
    {
        echo "\033[2J\033[H"; // ANSI escape codes to clear screen and move cursor to home
    }

    private function renderHeader(): void
    {
        // We don't have file names in this context, so just show a title
        $title = 'SRT Compare';
        $padding = max(0, ($this->terminalWidth - strlen($title)) / 2);
        echo str_repeat(' ', (int)$padding) . $title . PHP_EOL;
        echo str_repeat('=', $this->terminalWidth) . PHP_EOL . PHP_EOL;
    }

    private function renderCaptions(): void
    {
        $endRow = min($this->currentRow + $this->viewportHeight, count($this->comparisonData));
        $columnWidth = ($this->terminalWidth - 4) / 2; // Reserve space for separator and padding
        $columnWidth = max(10, (int)$columnWidth); // Minimum column width

        for ($i = $this->currentRow; $i < $endRow; $i++) {
            $pair = $this->comparisonData[$i];
            $left = $pair['left'];
            $right = $pair['right'];

            // Format left caption
            if ($left !== null) {
                $leftTimecode = sprintf('%s --> %s', $this->formatTime($left['start']), $this->formatTime($left['end']));
                $leftText = $this->formatText($left['text']);
                $leftLines = [
                    sprintf('%3d %s', $left['index'], $leftTimecode),
                    $leftText
                ];
            } else {
                $leftLines = ['', ''];
            }

            // Format right caption
            if ($right !== null) {
                $rightTimecode = sprintf('%s --> %s', $this->formatTime($right['start']), $this->formatTime($right['end']));
                $rightText = $this->formatText($right['text']);
                $rightLines = [
                    sprintf('%3d %s', $right['index'], $rightTimecode),
                    $rightText
                ];
            } else {
                $rightLines = ['', ''];
            }

            // Render each line of the caption pair
            for ($line = 0; $line < max(count($leftLines), count($rightLines)); $line++) {
                $leftPart = isset($leftLines[$line]) ? $this->truncate($leftLines[$line], $columnWidth) : '';
                $rightPart = isset($rightLines[$line]) ? $this->truncate($rightLines[$line], $columnWidth) : '';

                // Pad left part to column width and add separator
                echo str_pad($leftPart, $columnWidth) . '  ' . str_pad($rightPart, $columnWidth) . PHP_EOL;
            }

            // Add a blank line between captions for readability
            if ($i < $endRow - 1) {
                echo PHP_EOL;
            }
        }
    }

    private function renderFooter(): void
    {
        $total = count($this->comparisonData);
        $start = $this->currentRow + 1;
        $end = min($this->currentRow + $this->viewportHeight, $total);
        $info = sprintf('Caption %d-%d of %d', $start, $end, $total);
        $controls = '[j/k: ↓/↑, Page Up/Down, Home/End, q: quit]';
        $footer = sprintf('%s %s', $info, $controls);

        // Ensure footer fits within terminal width
        if (strlen($footer) > $this->terminalWidth) {
            $footer = substr($footer, 0, $this->terminalWidth - 3) . '...';
        }

        $padding = $this->terminalWidth - strlen($footer);
        echo PHP_EOL . str_repeat(' ', max(0, $padding)) . $footer;
    }

    private function readKey(): ?string
    {
        // Set stdin to non-blocking mode
        $stdin = fopen('php://stdin', 'r');
        stream_set_blocking($stdin, 0);

        $key = null;
        $buffer = '';

        while (true) {
            $c = fread($stdin, 1);
            if ($c === false) {
                usleep(50000); // 50ms
                continue;
            }

            $buffer .= $c;

            // Check for escape sequences
            if ($buffer === "\x1b") {
                // Escape key - treat as quit for simplicity
                $key = "\x1b";
                break;
            } elseif ($buffer === "\x1b[") {
                // Start of escape sequence, wait for more
                continue;
            } elseif (strlen($buffer) >= 3 && $buffer[0] === "\x1b" && $buffer[1] === '[') {
                // We have an escape sequence
                $key = $buffer;
                break;
            } elseif ($c === "\x03") { // Ctrl+C
                $key = "\x03";
                break;
            } elseif ($c === "j" || $c === "k" || $c === "q") {
                $key = $c;
                break;
            } elseif ($c === "\n" || $c === "\r") {
                // Ignore enter
                $buffer = '';
                continue;
            } else {
                // Printable character, but we only care about specific ones
                // Reset buffer for next key
                $buffer = '';
                continue;
            }
        }

        fclose($stdin);
        return $key;
    }

    private function scrollDown(): void
    {
        if ($this->currentRow < count($this->comparisonData) - 1) {
            $this->currentRow++;
        }
    }

    private function scrollUp(): void
    {
        if ($this->currentRow > 0) {
            $this->currentRow--;
        }
    }

    private function scrollPageUp(): void
    {
        $this->currentRow = max(0, $this->currentRow - $this->viewportHeight);
    }

    private function scrollPageDown(): void
    {
        $this->currentRow = min(
            count($this->comparisonData) - 1,
            $this->currentRow + $this->viewportHeight
        );
    }

    private function scrollToTop(): void
    {
        $this->currentRow = 0;
    }

    private function scrollToBottom(): void
    {
        $this->currentRow = max(0, count($this->comparisonData) - $this->viewportHeight);
    }

    private function formatTime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        $milliseconds = 0; // Since we don't have millisecond precision in the internal format

        return sprintf(
            '%02d:%02d:%02d,%03d',
            (int)$hours,
            (int)$minutes,
            (int)floor($seconds),
            (int)$milliseconds
        );
    }

    private function formatText(string $text): string
    {
        // Replace newlines with spaces and trim
        $text = preg_replace('/\s+/', ' ', trim($text));
        // Limit length to prevent overflow
        if (strlen($text) > 100) {
            $text = substr($text, 0, 97) . '...';
        }
        return $text;
    }

    private function truncate(string $text, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        // Simple truncation for ASCII (good enough for subtitles)
        return mb_substr($text, 0, $length, 'UTF-8');
    }
}