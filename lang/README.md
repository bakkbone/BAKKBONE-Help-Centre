# Translating BAKKBONE Help Centre

The whole help desk (agent panel, end-user portal, emails, setup and error pages)
can run in one language other than English. Each help desk uses a single
language, chosen under **Admin → Settings → General → Language** (or on the
first setup screen). Right-to-left languages such as Arabic, Hebrew and Persian
are supported: the layout mirrors when the language says `"dir": "rtl"`.

English is built in. Other languages come from **language packs**: one JSON
file per language, which anyone can write. This folder ships only
`template.json`, the list of every piece of text to translate.

## Where packs go

| Folder                          | Use it for                                                                    |
|---------------------------------|-------------------------------------------------------------------------------|
| `lang/<code>.json`              | Packs shipped with a release (send yours as a pull request to have it included). |
| `custom/lang/<code>.json`       | Your own pack or tweaks. Updates never touch `custom/`, and this file wins over the others. |
| `addons/<add-on>/lang/<code>.json` | An add-on's own text (see `ADDONS.md`).                                     |

`<code>` is the language code, e.g. `fr`, `de`, `pt-BR`, `ar`. Files with the
same code in more than one folder are merged, so `custom/lang/fr.json` can hold
just the handful of strings you'd like worded differently from `lang/fr.json`.
You can even reword the English: put the strings you want to change in
`custom/lang/en.json`.

## Starting a pack

1. Copy `lang/template.json` to `custom/lang/<code>.json`.
2. Fill in `_meta` (below).
3. Translate each value. A value left as `""` shows the English, so a partly
   translated pack works and you can fill it in over time.
4. Choose the language under **Admin → Settings → General**.

The file must be saved as UTF-8 JSON. If it isn't valid JSON it's ignored and
a line is written to PHP's error log; `php bin/i18n.php check` points to the
problem.

### `_meta`

| Key            | Example                                   | Meaning                                                    |
|----------------|-------------------------------------------|------------------------------------------------------------|
| `name`         | `"French"`                                | The language's name in English.                            |
| `native`       | `"Français"`                              | Its own name, shown in the language list.                  |
| `dir`          | `"ltr"` or `"rtl"`                        | Text direction.                                            |
| `html_lang`    | `"fr"`                                    | The `lang` attribute for pages and emails (BCP 47).        |
| `plural`       | `"n > 1"`                                 | Which plural form to use for a count `n` (below).          |
| `plural_count` | `2`                                       | How many plural forms the language has.                    |
| `decimal`      | `","`                                     | Decimal separator in numbers.                              |
| `thousands`    | `" "`                                     | Thousands separator.                                       |
| `authors`, `version` | `"Jo Bloggs"`, `"1"`                | For your records.                                          |

## How the strings work

The keys are the English text itself, and the values are your translation:

```json
"Save settings": "Enregistrer les paramètres",
```

**Placeholders.** Words in braces are filled in by the help desk. Keep them,
spelt exactly the same, but move them wherever your grammar needs:

```json
"Ticket #{number} deleted.": "Ticket n° {number} supprimé.",
```

**Links and other markup.** `{link}…{/link}` (and `{link2}…{/link2}`) mark the
words that become a link. Simple formatting tags (`<strong>`, `<em>`, `<b>`,
`<i>`, `<code>`, `<kbd>`, `<small>`, `<sup>`, `<sub>`, `<br>`) may be used and
moved. Any other HTML in a translation is shown as text, not run.

```json
"Merged into {link}#{number}{/link}": "Fusionné dans {link}#{number}{/link}",
```

**Merge tags.** In email template text, `%{ticket.number}` and similar are
filled in when the email is sent. Keep them as they are.

**Context.** A few short words need different translations in different places.
Their keys start with a hint and `::`, e.g. `"short month::May"` (the
abbreviation in dates) and `"time::pm"`. Translate only the part after `::`.

**Arrows.** Strings like `"← Users"` point the way "back" goes. In a
right-to-left language, write `"→ …"` instead.

### Plurals

Strings that depend on a number are lists, one entry per plural form, and
`_meta.plural` picks the entry. It's a C-style expression in `n`, the same as
the `Plural-Forms` line of a gettext `.po` file:

```json
"_meta": { "plural": "n > 1", "plural_count": 2 },
"{n} ticket": ["{n} ticket", "{n} tickets"]
```

| Language         | `plural`                                                                                               | `plural_count` |
|------------------|--------------------------------------------------------------------------------------------------------|----------------|
| English, German, Spanish… | `n != 1`                                                                                      | 2 |
| French, Portuguese (Brazil) | `n > 1`                                                                                     | 2 |
| Japanese, Chinese, Korean | `0`                                                                                           | 1 |
| Russian, Ukrainian | `n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 \|\| n%100>=20) ? 1 : 2`                  | 3 |
| Polish           | `n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 \|\| n%100>=20) ? 1 : 2`                                    | 3 |
| Arabic           | `n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5`                         | 6 |

`{n}` is replaced with the number, formatted with your separators. A form may
leave `{n}` out ("one ticket").

## Tools

Run these from the help desk's folder:

```
php bin/i18n.php extract                  # rebuild lang/template.json from the code
php bin/i18n.php update custom/lang/fr.json   # add strings that are new since your pack was made
php bin/i18n.php check custom/lang/fr.json    # how much is translated, and any mistakes
php bin/i18n.php pseudo xx [--rtl]        # a fake language for testing (see below)
```

`check` reports missing `_meta` keys, an invalid plural rule, placeholders or
tags that don't match the English, the wrong number of plural forms, and
strings the help desk no longer uses. It exits with status 1 if it finds a
problem, so it can run in CI.

`pseudo xx` writes `custom/lang/xx.json`, where every string is wrapped like
`⟦Šéŧŧíñĝš⟧`. Pick it in the settings and browse: any plain English left on
screen hasn't been made translatable yet (your own data, like department
names, will of course stay as you typed it). `--rtl` makes it right-to-left,
for checking layouts. Delete the file when you're done.

## What isn't translated

- **Your own content**: department, status and priority names, help topics,
  knowledge base articles, canned responses and so on. The starter ones are
  created in the language the help desk is installed in; after that they're
  yours to rename.
- **Email templates** are saved when the help desk is installed. If you change
  the language later, open each template under **Admin → Email templates** and
  use **Restore default** to get it in the new language (or edit it yourself).
  The same goes for the portal's home page and "ticket submitted" text under
  **Admin → Settings → Portal pages**, once they've been saved.
- **Technical text**: the system log, REST API error messages, command-line
  tools and low-level mail server errors stay in English, so they can be
  searched for and compared with documentation.

## For developers

Text in code goes through these helpers (see `src/helpers.php`):

| Helper                             | Returns                                                          |
|------------------------------------|------------------------------------------------------------------|
| `__('Text {x}', ['x' => $v])`      | Plain text. Escape it yourself (`e()`) when putting it in HTML.  |
| `t('Text {x}', ['x' => $v])`       | HTML-safe: the translation and every value are escaped.          |
| `_n('{n} file', '{n} files', $n)`  | Plain text, plural form for `$n`.                                |
| `tn('{n} file', '{n} files', $n)`  | HTML-safe plural.                                                |
| `raw($html)`                       | A value for `t()`/`tn()` that's already HTML and isn't escaped.  |
| `tag('<a href="…">')`              | A `{name}…{/name}` pair for `t()`/`tn()`.                        |
| `t_html('…')`                      | Like `t()`, for HTML that's stored and edited later (quotes stay readable). |
| `fmt_num($n)`, `fmt_date()`, `fmt_dt()`, `time_ago()` | Numbers and dates in the help desk's language. |

Write whole sentences, never glue translated pieces together, and pass the
text as a literal so `bin/i18n.php extract` can find it. Labels kept in
constants are listed in `src/Core/Translatable.php`. In `app.js`, `__()` and
`_n()` work the same way; the page carries the translations it needs.

Layouts use CSS logical properties (`margin-inline-start`, `inset-inline-end`
and so on), so right-to-left works without extra rules. Do the same in
`custom.css` and add-on styles. Directional icons (arrows, chevrons) are
mirrored automatically.
