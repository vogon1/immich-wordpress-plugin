# Translation Workflow

## Modern WordPress Way (WP-CLI)

### One-Command Translation Update

```bash
npm run translate
```

This does everything in one go:

1. Generates .pot file from source code
2. Updates .po files with new strings
3. Compiles .mo and JSON files

### Separate Commands

**1. Generate POT template:**

```bash
npm run i18n:pot
# Or directly: wp i18n make-pot . languages/immich-gallery.pot --domain=immich-gallery
```

**2. Update PO files (after manual translation in Poedit):**

```bash
npm run i18n:update
# Or directly: msgmerge --update --backup=none languages/immich-gallery-nl_NL.po languages/immich-gallery.pot
```

**3. Compile translations:**

```bash
npm run i18n:build
# Or separately:
npm run i18n:mo    # .mo for PHP
npm run i18n:json  # .json for JavaScript/Gutenberg
```

**4. Fix JSON filename (required):**

After running `i18n:json`, the JSON file needs to be renamed to match WordPress expectations:

```bash
# WP-CLI generates wrong hash, rename it manually:
mv languages/immich-gallery-nl_NL-*.json languages/immich-gallery-nl_NL-dfbff627e6c248bcb3b61d7d06da9ca9.json
```

The hash must be `md5('build/index.js')` = `dfbff627e6c248bcb3b61d7d06da9ca9`

## Translation Files

- `languages/immich-gallery.pot` - Template (generated from code)
- `languages/immich-gallery-nl_NL.po` - Dutch translations (manual)
- `languages/immich-gallery-nl_NL.mo` - Compiled for PHP (auto-generated)
- `languages/immich-gallery-nl_NL-{hash}.json` - For Gutenberg block (auto-generated)

## Adding a New Language

1. Copy `immich-gallery-nl_NL.po` to `immich-gallery-{locale}.po`
2. Update header with correct language/locale info
3. Translate all msgstr entries
4. Run `npm run i18n:build`
5. Rename the generated JSON file to use hash `dfbff627e6c248bcb3b61d7d06da9ca9`

## Dependencies

- **WP-CLI**: `brew install wp-cli`
- **gettext**: `brew install gettext` (for msgfmt/msgmerge)

## Tips

- When code changes: run `npm run translate` for complete update
- For JavaScript only: `npm run i18n:json` (then rename the JSON file)
- PO files can be edited with [Poedit](https://poedit.net/)
- After updating translations, hard refresh browser (Cmd+Shift+R) to clear cache
