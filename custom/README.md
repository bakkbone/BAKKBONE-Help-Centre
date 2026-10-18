# custom/ — your own changes, safe from updates

Files in this folder are never shipped with BAKKBONE Help Centre, so uploading a
new version never overwrites them. (This README is the only file we ship here.)

| Put this here                | What it does                                                              |
|------------------------------|---------------------------------------------------------------------------|
| `custom.css`                 | Added to every page, portal and agent panel, after the built-in styles.  |
| `custom.js`                  | Loaded on every page (after the built-in script).                         |
| `templates/<path>.php`       | Used instead of `templates/<path>.php`, e.g. `templates/client/home.php`. |
| `lang/<code>.json`           | A language pack, or your own wording for some strings. See `lang/README.md`. |
| images, fonts, etc.          | Served at `/custom/<file>`, e.g. `/custom/logo.svg`.                      |

## Tips

- **Styles:** the design uses CSS variables, so most changes are one line, e.g.
  `:root { --md-shape-md: 4px; }` for squarer corners. Dark mode values live
  under `:root[data-theme="dark"]` and `@media (prefers-color-scheme: dark)`.
- **Template overrides** replace the whole file. Copy the original from
  `templates/` and edit the copy. After an update, compare your copy with the
  new original in case it gained features you want.
- **Scripts:** the help desk blocks inline `<script>` tags for security, so put
  JavaScript in `custom.js` rather than in a template.
- For anything bigger (new pages, reacting to tickets, settings) write an
  add-on instead — see `ADDONS.md` in the main folder.
