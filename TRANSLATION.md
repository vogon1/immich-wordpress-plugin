# Translation Workflow

## Quick Start

### One-Command Translation Update

```bash
npm run translate
```

This does everything in one go:

1. Generates .pot file from source code
2. Updates .po files for all languages (nl_NL, de_DE, fr_FR) with new strings
3. Compiles .mo files for PHP
4. Generates .json files for JavaScript/Gutenberg
5. **Automatically syncs JSON files to match webpack build hash**

### Build Only (Auto-syncs translations)

```bash
npm run build
```

The build process automatically:

1. Compiles JavaScript with webpack
2. Syncs all JSON translation files to match the new webpack hash
3. Cleans up old JSON files with outdated hashes

## Separate Commands

**1. Generate POT template:**

```bash
npm run i18n:pot
```

Generates `languages/gallery-for-immich.pot` from all source code.

**2. Update PO files:**

```bash
npm run i18n:update
```

Updates all .po files (nl_NL, de_DE, fr_FR) with new strings from .pot file.

**3. Compile translations:**

```bash
npm run i18n:build
```

Or separately:

- `npm run i18n:mo` - Compiles .mo files for PHP
- `npm run i18n:json` - Generates .json files for JavaScript/Gutenberg

**Note:** After any build, the sync script automatically runs to match webpack hash.

## Translation Files

### Keep These Files

- `languages/gallery-for-immich.pot` - Template (generated from code)
- `languages/gallery-for-immich-{locale}.po` - Translation source (**edit manually, commit to git**)
- `languages/gallery-for-immich-{locale}.mo` - Compiled for PHP (auto-generated, commit to git)
- `languages/gallery-for-immich-{locale}-{hash}.json` - For Gutenberg (**only keep files matching current webpack hash**)

### Clean Up Old Files

After building, you may have multiple JSON files with different hashes:

```bash
gallery-for-immich-nl_NL-e707ab1fed28c31a5264.json  # Current hash - KEEP
gallery-for-immich-nl_NL-1fdf421c05c1140f6d71.json  # Old hash - DELETE
gallery-for-immich-nl_NL-dfbff627e6c248bcb3b6.json  # Old hash - DELETE
```

**Automatic cleanup:** The sync script (`npm run build`) removes old JSON files automatically.

**Manual cleanup:** Delete all JSON files except those matching the hash in `build/index.asset.php`:

```bash
# Check current hash
cat build/index.asset.php | grep version

# Remove old JSON files (keep only matching hash)
rm languages/gallery-for-immich-*-{old-hash}.json
```

## Supported Languages

- **Dutch (nl_NL)** - Nederlands
- **German (de_DE)** - Deutsch  
- **French (fr_FR)** - Français

## Adding a New Language

1. Copy an existing .po file:

   ```bash
   cp languages/gallery-for-immich-nl_NL.po languages/gallery-for-immich-{locale}.po
   ```

2. Update the header with correct language info

3. Translate all msgstr entries (use [Poedit](https://poedit.net/) for easy editing)

4. Update `package.json` scripts to include the new language:
   - Add to `i18n:update` command
   - Add to `i18n:mo` command

5. Run the translation workflow:

   ```bash
   npm run translate
   ```

## How Translation Loading Works

### PHP Translations

- Loaded via `load_plugin_textdomain()` in `__construct()`
- Uses `.mo` files from `languages/` directory
- Works automatically for admin pages and shortcodes

### JavaScript/Gutenberg Translations

- Loaded inline via `wp.i18n.setLocaleData()` in `enqueue_block_editor_assets()`
- JSON file must match exact webpack hash: `gallery-for-immich-{locale}-{hash}.json`
- The sync script (`scripts/sync-translations.js`) automatically:
  - Reads webpack hash from `build/index.asset.php`
  - Copies JSON files to match the hash
  - Removes outdated JSON files

### Why Inline Loading?

WordPress's standard `wp_set_script_translations()` doesn't always work reliably with custom plugin paths. We use inline loading to ensure translations are always loaded correctly:

```php
wp_add_inline_script(
    'gallery-for-immich-block',
    sprintf('wp.domReady(function() { wp.i18n.setLocaleData(%s, "gallery-for-immich"); });', ...),
    'before'
);
```

## Troubleshooting

### Gutenberg Block Shows English

1. Check if JSON file exists with correct hash:

   ```bash
   ls -lh languages/*.json
   ```

2. Verify hash matches webpack build:

   ```bash
   cat build/index.asset.php
   ```

3. Rebuild to sync:

   ```bash
   npm run build
   ```

### New Strings Not Appearing

1. Run full translation update:

   ```bash
   npm run translate
   ```

2. Hard refresh browser (Cmd+Shift+R / Ctrl+Shift+R)
3. Clear WordPress cache if using a caching plugin

### JSON Files Have Wrong Hash

The sync script should handle this automatically, but if needed:

```bash
node scripts/sync-translations.js
```

## Dependencies

- **Node.js & npm** - For build scripts
- **WP-CLI** - `brew install wp-cli` (for .pot and .json generation)
- **gettext** - `brew install gettext` (for msgfmt/msgmerge)

## Development Tips

- Edit translations in .po files using [Poedit](https://poedit.net/)
- Always run `npm run translate` after adding new translatable strings
- The build process handles JSON syncing automatically
- Test in browser with network cache disabled during development
- Check `wp-content/debug.log` if translations don't load (enable `WP_DEBUG_LOG`)
