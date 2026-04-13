# Release Checklist

Follow these steps before tagging and publishing a new release.

---

## 1. Check WordPress version compatibility

- Go to https://wordpress.org/download/ and find the latest stable WP version.
- Test the plugin on that version (or check the changelog for breaking changes).
- If compatible, update **Tested up to** in:
  - `readme.txt` (line: `Tested up to: x.x`)
  - `gallery-for-immich.php` is not required to carry this field, but the value in `readme.txt` is authoritative for the WordPress plugin directory.

## 2. Check Immich version compatibility

- Go to https://github.com/immich-app/immich/releases and find the latest release.
- Check the API changelog for any breaking changes to endpoints the plugin uses:
  - `GET /api/albums`
  - `GET /api/assets/{id}`
  - `GET /api/assets/{id}/thumbnail`
  - `GET /api/assets/{id}/video/playback`
  - `GET /api/assets/{id}/original`
  - `POST /api/shared-links`
  - `DELETE /api/shared-links/{id}`
- Test against the latest Immich version.
- Update the documented minimum/tested Immich version in `README.md` if applicable.

## 3. Check and update translations

- Generate the POT template and update all `.po` files with new/changed strings:
  ```bash
  npm run i18n:pot
  npm run i18n:update
  ```
- Review the `.po` files (nl_NL, de_DE, fr_FR) in `languages/` for untranslated strings (`msgstr ""`).
- Fill in any missing translations manually in the `.po` files.
- **Do not compile yet** — run the full pipeline after bumping the version in step 6.
- See `TRANSLATION.md` for the full translation workflow.

## 4. Update README.md

- Review and update sections that describe new features or changed behaviour.
- Check that the **required permissions** list is current.
- Update the **Changelog** section with the changes for this release.

## 5. Update readme.txt

- Mirror the same changelog entry in the `== Changelog ==` section.
- Update `Tested up to` if changed in step 1.
- Update `Stable tag` to the new version number.

## 6. Bump version numbers

Update the version in **all** of the following files:

| File | Field / location |
|---|---|
| `gallery-for-immich.php` | `* Version: x.x.x` (plugin header) |
| `readme.txt` | `Stable tag: x.x.x` |
| `package.json` | `"version": "x.x.x"` |

There is no version field in `README.md` — the plugin header and `readme.txt` are authoritative.

## 7. Compile translations

Now that the version is bumped, run the full translation pipeline. This ensures the `.pot` header carries the correct version number:

```bash
npm run translate
```

## 8. Commit, tag and push

```bash
git add -A
git commit -m "Release vx.x.x"
git tag vx.x.x
git push origin main --tags
```

## 9. Release archive

The GitHub Action in `.github/workflows/release.yml` triggers automatically on any `v*` tag and builds + publishes the release ZIP. No manual steps needed.
