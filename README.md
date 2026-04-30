# SRT Compare

A terminal application to compare two subtitle files side-by-side with keyboard navigation.

![SRT Compare Demo](demo.gif)

## Features

- **Side-by-side comparison**: View two subtitle files in parallel columns
- **Keyboard navigation**: 
  - `j` or `↓`: Scroll down
  - `k` or `↑`: Scroll up
  - `Page Up` / `Page Down`: Scroll multiple lines
  - `Home` / `End`: Jump to start/end
  - `q` or `Ctrl+C`: Quit application
- **Plain text output**: No colors or highlighting - ideal for translation comparison
- **Format agnostic**: Supports SRT, VTT, and other formats via mantas-done/subtitles
- **Self-updating**: Use `--update` flag to get latest version from GitHub
- **Standalone distribution**: Single executable PHAR file

## Installation

### Option 1: Download Pre-built PHAR (Recommended)

Get the latest version from the [GitHub Releases](https://github.com/iceman1010/srt-compare/releases) page:
```bash
# Download the PHAR file
wget https://github.com/iceman1010/srt-compare/releases/latest/download/srt-compare.phar

# Make it executable
chmod +x srt-compare.phar

# Move to your PATH (optional)
sudo mv srt-compare.phar /usr/local/bin/srt-compare
```

### Option 2: Build from Source

```bash
# Clone the repository
git clone https://github.com/iceman1010/srt-compare.git
cd srt-compare

# Install dependencies
composer install

# Build PHAR (requires humbug/box)
composer require --dev humbug/box
./vendor/bin/box compile
```

## Usage

### Basic Comparison
```bash
./srt-compare.phar -i original.srt -i translation.srt
```

### With Different Formats
```bash
./srt-compare.phar -i movie.vtt -i movie.srt
```

### Self-Update
```bash
# Check for and install latest version
./srt-compare.phar --update
```

### Help
```bash
./srt-compare.phar --help
```

## Keyboard Controls

| Key | Action |
|-----|--------|
| `j` or `↓` | Scroll down |
| `k` or `↑` | Scroll up |
| `Page Up` | Scroll up one page |
| `Page Down` | Scroll down one page |
| `Home` | Jump to beginning |
| `End` | Jump to end |
| `q` or `Ctrl+C` | Quit application |

## How It Works

1. **File Loading**: Uses [mantas-done/subtitles](https://github.com/mantas-done/subtitles) to parse subtitle files (SRT, VTT, ASS, etc.)
2. **Comparison**: Matches subtitles by caption index (1st caption from file 1 ↔ 1st caption from file 2, etc.)
3. **Display**: Renders two columns with caption index, timecode, and text
4. **Navigation**: Tracks viewport and renders visible portion based on terminal size
5. **Updates**: Checks GitHub releases for newer versions and can replace itself

## Technical Details

### Dependencies
- PHP 7.4+ or 8.0+
- [mantas-done/subtitles](https://packagist.org/packages/mantas-done/subtitles) - Subtitle parsing
- [symfony/console](https://packagist.org/packages/symfony/console) - CLI framework
- [humbug/box](https://github.com/humbug/box) - PHAR compilation (dev dependency)

### File Structure
```
srt-compare/
├── bin/
│   └── srt-compare          # Executable script
├── src/
│   ├── Application.php      # CLI orchestration
│   ├── SubtitleComparator.php # Comparison logic
│   └── TerminalUI.php       # Terminal rendering
├── .github/
│   └── workflows/
│       └── build-phar.yml   # GitHub Actions for auto-compilation
├── composer.json            # PHP dependencies
├── box.json                 # PHAR configuration
├── VERSION                  # Current version
└── srt-compare.phar         # Compiled executable
```

## Examples

### Translation Comparison
Perfect for comparing original subtitles with translations:
```bash
./srt-compare.phar -i movie_en.srt -i movie_ja.srt
```

### Format Conversion Verification
Verify that conversion between formats preserved content:
```bash
./srt-compare.phar -i movie.srt -i movie.vtt
```

### Work in Progress Review
Compare your work against a reference:
```bash
./srt-compare.phar -i reference.srt -i work_in_progress.srt
```

## Release Process

1. Update VERSION file with new version number
2. Commit changes: `git commit -am "Release vX.Y.Z"`
3. Tag release: `git tag vX.Y.Z`
4. Push to GitHub: `git push && git push --tags`
5. GitHub Actions automatically:
   - Compiles the PHAR
   - Creates GitHub release
   - Uploads PHAR as release asset

## License

MIT

## Acknowledgments

- [mantas-done/subtitles](https://github.com/mantas-done/subtitles) for robust subtitle parsing
- [Symfony Console](https://symfony.com/doc/current/components/console.html) for CLI framework
- [humbug/box](https://github.com/humbug/box) for PHAR compilation

---
*Built with ❤️ for subtitle translators and enthusiasts*