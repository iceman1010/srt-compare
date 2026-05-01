<?php

namespace App;

class TerminalUI
{
    // ANSI color constants
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BLUE = "\033[34m";
    private const MAGENTA = "\033[35m";
    private const CYAN = "\033[36m";
    private const WHITE = "\033[37m";
    private const BRIGHT_BLACK = "\033[90m";
    private const BRIGHT_RED = "\033[91m";
    private const BRIGHT_GREEN = "\033[92m";
    private const BRIGHT_YELLOW = "\033[93m";
    private const BRIGHT_BLUE = "\033[94m";
    private const BRIGHT_MAGENTA = "\033[95m";
    private const BRIGHT_CYAN = "\033[96m";

    // Semantic color assignments
    private const COLOR_TIMECODE = self::BRIGHT_CYAN;
    private const COLOR_CAPTION_INDEX = self::BRIGHT_YELLOW;
    private const COLOR_CAPTION_TEXT = self::WHITE;
    private const COLOR_SEPARATOR = self::BRIGHT_BLACK;
    private const COLOR_HEADER = self::BOLD . self::BRIGHT_GREEN;
    private const COLOR_FOOTER = self::BRIGHT_BLACK;
    private const COLOR_DIVIDER = self::BLUE;
    private const COLOR_FILE_NAME = self::BOLD . self::BRIGHT_MAGENTA;

    private array $comparisonData;
    private int $currentRow;
    private int $viewportHeight;
    private ?int $terminalWidth;
    private ?int $terminalHeight;
    private string $file1;
    private string $file2;
    private ?string $savedTerminalState = null;
    private $stdinHandle = null;
    private bool $windowResized = false;
    private int $lastRenderedRow = -1;
    private int $lastRenderedWidth = -1;
    private int $lastRenderedHeight = -1;

    public function __construct(array $comparisonData, string $file1, string $file2)
    {
        $this->comparisonData = $comparisonData;
        $this->currentRow = 0;
        $this->viewportHeight = 0;
        $this->terminalWidth = null;
        $this->terminalHeight = null;
        $this->file1 = $file1;
        $this->file2 = $file2;
    }

    private function configureTerminal(): void
    {
        // Save current terminal state
        exec('stty -g', $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0])) {
            $this->savedTerminalState = $output[0];
        }

        // Set raw mode: no echo, no canonical input buffering
        exec('stty -echo -icanon min 0 time 0 2>/dev/null');

        // Setup SIGWINCH handler for window resize detection (if pcntl available)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGWINCH, function() {
                $this->windowResized = true;
            });
        }
    }

    private function restoreTerminal(): void
    {
        if ($this->savedTerminalState) {
            exec('stty ' . escapeshellarg($this->savedTerminalState) . ' 2>/dev/null');
        }
    }

    private function needsRender(): bool
    {
        // Always render if window was resized
        if ($this->windowResized) {
            return true;
        }

        // Always render on first frame
        if ($this->lastRenderedRow === -1) {
            return true;
        }

        // Render if scroll position changed
        if ($this->currentRow !== $this->lastRenderedRow) {
            return true;
        }

        // Render if terminal dimensions changed
        if ($this->terminalWidth !== $this->lastRenderedWidth || $this->terminalHeight !== $this->lastRenderedHeight) {
            return true;
        }

        // No changes, don't render
        return false;
    }

    private function updateRenderState(): void
    {
        $this->lastRenderedRow = $this->currentRow;
        $this->lastRenderedWidth = $this->terminalWidth;
        $this->lastRenderedHeight = $this->terminalHeight;
    }

    public function render(): void
    {
        try {
            $this->configureTerminal();
            $this->stdinHandle = fopen('php://stdin', 'r');
            stream_set_blocking($this->stdinHandle, false);

            // Get terminal size
            $size = $this->getTerminalSize();
            $this->terminalWidth = $size[0];
            $this->terminalHeight = $size[1];

            // Calculate viewport height: max caption pairs that fit on screen
            // Layout: header (3 lines) + N caption pairs (3 lines each except last: 2) + footer (2 lines)
            // Total lines ≈ 3*N + 4. Solve: N = floor((terminalHeight - 4) / 3).
            // Ensure at least 1 pair visible.
            $this->viewportHeight = max(1, (int) floor(($this->terminalHeight - 4) / 3));

            // Ensure currentRow is within bounds
            $this->currentRow = max(0, min($this->currentRow, count($this->comparisonData) - 1));

            // Adjust currentRow so that the viewport shows a valid range
            if ($this->currentRow + $this->viewportHeight > count($this->comparisonData)) {
                $this->currentRow = max(0, count($this->comparisonData) - $this->viewportHeight);
            }

            // Main render loop
            while (true) {
                // Handle window resize
                if ($this->windowResized) {
                    $this->windowResized = false;
                    $size = $this->getTerminalSize();
                    $this->terminalWidth = $size[0];
                    $this->terminalHeight = $size[1];
                    // Recalculate viewport height using same logic as initial
                    $this->viewportHeight = max(1, (int) floor(($this->terminalHeight - 4) / 3));
                    
                    // Adjust currentRow if it's now out of bounds
                    if ($this->currentRow + $this->viewportHeight > count($this->comparisonData)) {
                        $this->currentRow = max(0, count($this->comparisonData) - $this->viewportHeight);
                    }
                }

                // Only render if state has changed
                if ($this->needsRender()) {
                    $this->clearScreen();
                    $this->renderHeader();
                    $this->renderCaptions();
                    $this->renderFooter();
                    $this->updateRenderState();
                } else {
                    // No render needed, but still need to process input
                    // Use a small sleep to avoid busy-waiting
                    usleep(50000); // 50ms
                }

                $key = $this->readKey($this->stdinHandle);
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
                    case "\x1b[1~": // Home variant
                        $this->scrollToTop();
                        break;
                    case "\x1b[F": // End
                    case "\x1b[4~": // End variant
                        $this->scrollToBottom();
                        break;
                    default:
                        // Ignore other keys
                        break;
                }
            }
        } finally {
            if ($this->stdinHandle) {
                fclose($this->stdinHandle);
            }
            $this->restoreTerminal();
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
        // Get just the filenames without path
        $file1Name = basename($this->file1);
        $file2Name = basename($this->file2);

        // Create header with file names (colored)
        $headerText = sprintf(
            self::COLOR_HEADER . 'Comparing: ' . self::RESET .
            self::COLOR_FILE_NAME . '%s' . self::RESET .
            self::COLOR_HEADER . ' ↔ ' . self::RESET .
            self::COLOR_FILE_NAME . '%s' . self::RESET,
            $file1Name,
            $file2Name
        );
        $padding = max(0, ($this->terminalWidth - strlen($file1Name) - strlen($file2Name) - 13) / 2);
        echo str_repeat(' ', (int)$padding) . $headerText . PHP_EOL;
        echo self::COLOR_SEPARATOR . str_repeat('═', $this->terminalWidth) . self::RESET . PHP_EOL . PHP_EOL;
    }

    private function renderCaptions(): void
    {
        $endRow = min($this->currentRow + $this->viewportHeight, count($this->comparisonData));
        $columnWidth = (int)(($this->terminalWidth - 5) / 2); // Reserve space for separator and padding
        $columnWidth = max(10, $columnWidth); // Minimum column width

        for ($i = $this->currentRow; $i < $endRow; $i++) {
            $pair = $this->comparisonData[$i];
            $left = $pair['left'];
            $right = $pair['right'];

            // Left column content
            $leftLine1 = '';
            $leftLine2 = '';
            if ($left !== null) {
                $leftIndex = $left['index'];
                $leftTimecode = sprintf('%s --> %s', $this->formatTime($left['start']), $this->formatTime($left['end']));
                $leftText = $this->formatText($left['text']);

                // Build first line: index (colored) + timecode (colored)
                $leftLine1 = sprintf(
                    self::COLOR_CAPTION_INDEX . '%3d' . self::RESET . ' ' .
                    self::COLOR_TIMECODE . '%s' . self::RESET,
                    $leftIndex,
                    $leftTimecode
                );

                // Build second line: text (colored)
                $leftLine2 = self::COLOR_CAPTION_TEXT . $leftText . self::RESET;
            }

            // Right column content
            $rightLine1 = '';
            $rightLine2 = '';
            if ($right !== null) {
                $rightIndex = $right['index'];
                $rightTimecode = sprintf('%s --> %s', $this->formatTime($right['start']), $this->formatTime($right['end']));
                $rightText = $this->formatText($right['text']);

                // Build first line: index (colored) + timecode (colored)
                $rightLine1 = sprintf(
                    self::COLOR_CAPTION_INDEX . '%3d' . self::RESET . ' ' .
                    self::COLOR_TIMECODE . '%s' . self::RESET,
                    $rightIndex,
                    $rightTimecode
                );

                // Build second line: text (colored)
                $rightLine2 = self::COLOR_CAPTION_TEXT . $rightText . self::RESET;
            }

            // Render first line
            $leftLine1Padded = $this->padToWidth($leftLine1, $columnWidth);
            $rightLine1Padded = $this->padToWidth($rightLine1, $columnWidth);
            echo $leftLine1Padded . self::COLOR_DIVIDER . ' │ ' . self::RESET . $rightLine1Padded . PHP_EOL;

            // Render second line
            $leftLine2Padded = $this->padToWidth($leftLine2, $columnWidth);
            $rightLine2Padded = $this->padToWidth($rightLine2, $columnWidth);
            echo $leftLine2Padded . self::COLOR_DIVIDER . ' │ ' . self::RESET . $rightLine2Padded . PHP_EOL;

            // Add separator between captions (except after last caption)
            if ($i < $endRow - 1) {
                echo self::COLOR_SEPARATOR . str_repeat('─', $this->terminalWidth) . self::RESET . PHP_EOL;
            }
        }
    }

    private function visibleLength(string $text): int
    {
        // Remove ANSI escape sequences and calculate visible length
        $clean = preg_replace('/\033\[[0-9;]*m/', '', $text);
        return mb_strlen($clean, 'UTF-8');
    }

    private function padToWidth(string $text, int $width): string
    {
        $visibleLen = $this->visibleLength($text);

        if ($visibleLen >= $width) {
            return $text; // Already at or exceeding width
        }
        return $text . str_repeat(' ', $width - $visibleLen);
    }

    private function renderFooter(): void
    {
        $total = count($this->comparisonData);
        $start = $this->currentRow + 1;
        $end = min($this->currentRow + $this->viewportHeight, $total);

        // Build colored footer
        $info = sprintf(
            self::COLOR_FOOTER . 'Caption ' . self::RESET .
            self::COLOR_CAPTION_INDEX . '%d-%d' . self::RESET .
            self::COLOR_FOOTER . ' of ' . self::RESET .
            self::COLOR_CAPTION_INDEX . '%d' . self::RESET,
            $start,
            $end,
            $total
        );

        $controls = self::COLOR_FOOTER . '[j/k: ↓/↑, Page Up/Down, Home/End, q: quit]' . self::RESET;

        // Calculate visible length (without ANSI codes)
        $infoVisible = $this->visibleLength($info);
        $controlsVisible = $this->visibleLength($controls);
        $totalVisible = $infoVisible + 1 + $controlsVisible; // +1 for space separator

        // Ensure footer fits within terminal width
        if ($totalVisible > $this->terminalWidth) {
            $controls = self::COLOR_FOOTER . '[...]' . self::RESET;
            $controlsVisible = 5; // [...]
            $totalVisible = $infoVisible + 1 + $controlsVisible;
        }

        $padding = max(0, $this->terminalWidth - $totalVisible);
        echo PHP_EOL . str_repeat(' ', $padding) . $info . ' ' . $controls;
    }

    private function readKey($stdin): ?string
    {
        $startTime = microtime(true);
        $timeout = 0.1; // 100ms timeout
        $buffer = '';

        while (microtime(true) - $startTime < $timeout) {
            // Process pending signals (for SIGWINCH handling)
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $char = @fread($stdin, 1);
            if ($char === false || $char === '') {
                usleep(5000); // 5ms between checks
                continue;
            }

            $buffer .= $char;

            // Ctrl+C (always immediate)
            if ($char === "\x03") {
                return "\x03";
            }

            // Regular keys (single byte)
            if (in_array($char, ['q', 'j', 'k'])) {
                return $char;
            }

            // Ignore newlines and carriage returns
            if ($char === "\n" || $char === "\r") {
                $buffer = '';
                continue;
            }

            // Escape sequence detection
            if ($buffer === "\x1b") {
                continue; // Wait for next byte
            }

            // Check if we have a complete escape sequence
            // Matches: \x1b[A (single letter) or \x1b[5~ (tilde-terminated)
            if (preg_match('/^\x1b\[([A-Za-z]|[0-9;]*[~])$/', $buffer)) {
                return $buffer;
            }
        }

        // Dispatch pending signals before returning
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return null;
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