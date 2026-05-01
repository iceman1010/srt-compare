# CI/CD Workflow Documentation

## Version Management

### When to Bump Version
**ALWAYS** bump the version number when making changes that users will see or use:
- New features (UI changes, new commands, etc.)
- Bug fixes that affect functionality
- Any change that triggers the self-update mechanism
- Changes to the PHAR build

### Version Numbering
- Format: `MAJOR.MINOR.PATCH` (e.g., 1.0.6 → 1.0.7)
- MAJOR: Breaking changes
- MINOR: New features
- PATCH: Bug fixes

### Version File Location
- File: `VERSION` (in project root)
- Single line with version number (e.g., `1.0.7`)

## Commit Workflow

### Required Steps (IN THIS ORDER)
1. Make code changes
2. **Test the changes** (syntax check + functional test)
3. **Update VERSION file** with new version number
4. Commit with descriptive message
5. Push to remote

### Commit Message Convention
```
git commit -m "Brief description of change

- Detailed point 1
- Detailed point 2"
```

Examples:
- `Add ANSI colors to TUI: timecodes (cyan), indices (yellow), text (white)`
- `Fix self-update progress callback - remove unsupported getProgressStep() call`
- `Bump version to 1.0.7`

### Common Mistakes to Avoid
❌ **NEVER** commit and push without updating VERSION number
❌ **NEVER** push without testing changes first
❌ **NEVER** push automatically without user permission
❌ **NEVER** assume user wants changes pushed immediately

✅ **ALWAYS** ask before pushing: "Should I push this?"
✅ **ALWAYS** update VERSION file with EVERY user-visible change
✅ **ALWAYS** test before committing

## Self-Update Mechanism

### How It Works
1. User runs `srt-compare --update` or `srt-compare -o update`
2. App checks GitHub for latest VERSION file
3. If newer version available, downloads PHAR artifact from GitHub Actions
4. Replaces current PHAR binary
5. Updates local VERSION file

### Key Files
- `src/Application.php` - Self-update logic
- `VERSION` - Version tracking
- `.github/workflows/build-phar.yml` - GitHub Actions workflow that builds PHAR

### PHAR Replacement Logic
The self-update must:
1. Download to temp location
2. Determine installed PHAR location (`/usr/local/bin/srt-compare` or similar)
3. Replace with proper permissions (0755)
4. Update VERSION file in same directory

## Build Process

### Local Build
```bash
# Install dependencies
composer install

# Build PHAR (uses box)
php box.phar build
```

### GitHub Actions Build
- Triggered on push to main branch
- Builds PHAR using box
- Uploads as artifact
- Artifact name: `srt-compare-phar`

## Testing Checklist

Before committing, verify:
- [ ] PHP syntax check: `php -l src/Application.php`
- [ ] PHAR builds successfully
- [ ] Self-update works: `srt-compare --update`
- [ ] Main functionality works: `srt-compare -i file1.srt -i file2.srt`
- [ ] Colors display correctly (if TUI changes made)
- [ ] Version number is updated in `VERSION` file

## User Permissions

### What Requires Permission
- Pushing to remote repository
- Creating releases
- Merging PRs

### What You Can Do Without Asking
- Make code changes
- Run local tests (`php -l`, local execution)
- Update VERSION file (but only when user asks to commit)
- Build PHAR locally

## Terminal UI (TUI) Development

### Color Scheme
- Timecodes: Bright Cyan (`\033[96m`)
- Caption indices: Bright Yellow (`\033[93m`)
- Caption text: White (`\033[37m`)
- Layout dividers: Blue (`\033[34m`)
- Header: Bright Green Bold (`\033[1m\033[92m`)
- File names: Bright Magenta Bold (`\033[1m\033[95m`)
- Footer: Bright Black/Dim (`\033[90m`)

### ANSI Color Constants
Always use the defined constants in `TerminalUI.php`:
- `self::COLOR_TIMECODE`
- `self::COLOR_CAPTION_INDEX`
- `self::COLOR_CAPTION_TEXT`
- etc.

## Quick Reference

```bash
# Full workflow example
# 1. Make changes
vim src/TerminalUI.php

# 2. Test
php -l src/TerminalUI.php
php bin/srt-compare test/test1.srt test/test2.srt

# 3. Update version
echo "1.0.8" > VERSION

# 4. Commit
git add .
git commit -m "Description of changes"

# 5. Ask user before pushing!
# git push  # Only after user says yes
```

## Notes for AI Assistant

- Users get frustrated when you push without asking
- Always verify VERSION is updated before suggesting a push
- Self-update mechanism is fragile - test it after modifying `Application.php`
- The installed PHAR location may vary (`/usr/local/bin`, `/usr/bin`, etc.)
- When in doubt, ask the user before taking action
