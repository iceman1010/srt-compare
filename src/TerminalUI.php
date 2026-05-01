<?php

namespace App;

class TerminalUI
{
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
        
        // Create header with file names
        $headerText = sprintf('Comparing: %s ↔ %s', $file1Name, $file2Name);
        $padding = max(0, ($this->terminalWidth - strlen($headerText)) / 2);
        echo str_repeat(' ', (int)$padding) . $headerText . PHP_EOL;
        echo str_repeat('═', $this->terminalWidth) . PHP_EOL . PHP_EOL;
    }

    private function renderCaptions(): void
    {
        $endRow = min($this->currentRow + $this->viewportHeight, count($this->comparisonData));
        $columnWidth = ($this->terminalWidth - 5) / 2; // Reserve space for separator and padding
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

                // Pad left part to column width and add separator with visual divider
                echo str_pad($leftPart, $columnWidth) . ' │ ' . str_pad($rightPart, $columnWidth) . PHP_EOL;
            }

            // Add a subtle separator between captions for readability (except after last caption)
            if ($i < $endRow - 1) {
                echo str_repeat('─', $this->terminalWidth) . PHP_EOL;
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