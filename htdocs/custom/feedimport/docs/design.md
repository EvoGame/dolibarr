# Feed Import module: design

Status: agreed 2026-10-15, then adjusted while building 0.1.0 (see *Changes while building* at the end).
`maintenance.md` describes the code as built.

## Purpose

Suppliers publish CSV or XML files of their products (references, prices, stock available to buy) every day, by
email or on an FTP/SFTP/FTPS server. The module imports them into Dolibarr's products without anyone touching the
files: any number of feeds, each set up once on its own tab of the module setup, then run on a schedule.

Success means:

- a new supplier feed is set up from the setup page alone, without code;
- each scheduled run updates prices and stock, creates the supplier's new items, and sets to 0 the stock of the
  items the supplier no longer lists;
- a bad file (truncated, empty, wrong format, SKUs reformatted) never wipes or duplicates the catalogue: the run
  stops or holds back, and someone is told;
- a typo or a cosmetic change in a supplier's SKU does not create a duplicate product.

## Decisions

| Question | Decision |
|---|---|
| What the feed's stock is | The supplier's stock available to buy (drop shipping or our own purchasing). Each feed has its **own warehouse** in Dolibarr; the feed's quantities are applied there by stock corrections. Stock bought for the shop is moved out with a normal warehouse transfer. |
| "Set the virtual stock to 0" | Virtual stock is computed by Dolibarr (physical ± open orders) and cannot be set. A product that leaves the feed gets its **physical stock in the feed's warehouse** corrected to 0, which lowers its virtual stock by the same amount. The product itself is kept. |
| Detecting removed items | A table of the feed's items (feed SKU → product, last seen). After a complete run, linked items not seen in it are zeroed. The last files are kept (default 7) to inspect and re-run, but are not what is compared: a file-to-file diff is wrong as soon as a run failed half-way or the mapping changed. |
| Unknown items | Created as new products, after sanity checks and fuzzy matching (below). Anything doubtful goes to a **review queue** instead of being created. |
| Retrieval | FTP, FTPS (explicit or implicit TLS), SFTP and HTTP(S) through PHP's curl (one code path; no `ssh2` extension). Email through the **Email Collector** module (below). |
| Email | No IMAP code in this module. The module adds an operation type to Email Collector rules, *Feed import: import the attached file*, whose parameter is the feed id. Email Collector keeps the mailbox, filters and OAuth; the module receives the attachments through Email Collector's `doCollectImapOneCollector` hook, keeps the one matching the feed's file name pattern, and its scheduled job imports it (within 15 minutes; *Run now* at once). |
| Schema, XML | An XSD. It gives the list of fields with their types, the repeating item element (`maxOccurs="unbounded"`), and every incoming file is validated against it before anything is imported. An XSD cannot name Dolibarr fields, so the mapping is completed on the feed tab, or read from a `dolibarr:field="…"` attribute on the XSD's elements when the XSD is annotated. |
| Schema, CSV | A two-row CSV: the first row is the feed's column titles, the second row the Dolibarr field each one goes to (code or label, e.g. `sku`, `label`, `buy_price`; empty = ignored). The same two-row format is accepted for XML feeds (first row: field paths). A template can be downloaded from the feed tab. |

## Feed setup (one tab per feed)

*Setup → Modules → Feed Import*: a **Feeds** tab listing the feeds with an **Add new feed** button, then one tab
per feed. A feed tab is one form, in the order the feed is set up:

1. **Retrieval method**: FTP, FTPS, SFTP, HTTP(S), Email (Email Collector), or Manual upload only.
2. **File type**: CSV (delimiter, enclosure, encoding, header row, decimal separator) or XML (item path, decimal
   separator).
3. **Schema and mapping**: upload an XSD or a two-row mapping CSV (optional), then the mapping table: each feed
   field, its type when the XSD gives one, and the Dolibarr field it goes to. Fields are also discovered from the
   last file fetched (*Fetch & preview*).
4. **Access**: host, port, login, password (stored encrypted with `dolEncrypt`), directory, file name pattern
   (wildcards and date tokens `{YYYY}` `{MM}` `{DD}` `{YYYYMMDD}`), passive mode, SFTP server key fingerprint (MD5);
   or the URL; or, for Email, the
   instructions and the operation to add to an Email Collector rule, with a link to the collectors and the date
   the last file arrived.
5. **Schedule**: manual, daily at HH:MM, or every N hours. Email feeds import when the file arrives (the collector's
   own scheduled job decides how often the mailbox is read).
6. **Other settings**: supplier (third party), warehouse, matching key (supplier's reference, product ref or
   barcode), create new products or not, product ref prefix for new products, category given to new products,
   default VAT rate, sell price including VAT or not, zero missing items or not, safety threshold, maximum new
   products per run, files kept, email address for failures, enabled or not.

Buttons: **Save**, **Test connection**, **Fetch & preview** (fetch the file, show its fields and the first rows as
they would be imported, without importing), **Run now**, **Import a file** (upload a file by hand and import it),
**Delete feed**.

## Dolibarr fields a feed field can go to

| Code | Goes to |
|---|---|
| `sku` (required) | The supplier's reference: the key of the item in the feed. Supplier reference of the supplier price; ref of new products (with the prefix) when `ref` is not mapped. |
| `ref` | Product ref (new products only; never renamed afterwards) |
| `label`, `description`, `barcode`, `weight` (kg) | Product fields |
| `sell_price` | Selling price (excluding or including VAT, per feed) |
| `cost_price` | Cost price |
| `buy_price` | Supplier price for the feed's supplier, quantity `min_qty` |
| `min_qty` | Minimum purchase quantity of the supplier price (default 1) |
| `vat_rate` | VAT rate of new products and prices |
| `stock` | Stock in the feed's warehouse |
| `options_<code>` | A product extrafield |

Numbers accept `12.50`, `12,50` (per the feed's decimal separator), thousands separators, currency signs and
`>100`-style stock. A field that does not parse is skipped and counted as a warning; the rest of the row is used.

## A run

1. **Claim** the feed (a conditional update of its lock), so cron, *Run now* and an email never run it twice at
   once. A lock older than 2 hours is considered dead.
2. **Fetch**: pick the newest file matching the pattern; skip the run (*unchanged*) if its SHA-256 is that of the
   last file imported, unless forced. Keep it in `documents/feedimport/feed_<id>/files/`, keeping the last N.
3. **Validate**: XML against the XSD when there is one (a first pass; nothing is imported if it fails), CSV
   header against the mapping (every mapped column must exist).
4. **Import each row**: find the product (below), then update only what changed. A hash of the mapped values is
   kept per item: an unchanged row costs one comparison. Stock is compared with the warehouse's actual stock,
   since people may move stock too.
5. **Zero missing items**, if enabled, unless a guard trips (below).
6. **Record** the run (counts and log), release the lock, compute the next run, email the address set on failure
   or warning.

### Finding the product of a row

In order, stopping at the first that applies:

1. The feed's item table already links this SKU to a product.
2. **Exact match** on the matching key: supplier price of the feed's supplier with this supplier reference, or
   product ref, or barcode. The item is linked.
3. **Normalised match**: the SKU compared without case, spaces, punctuation and leading zeros (`AB-0123` =
   `ab123`) with the feed's known items and the supplier's references. One match: linked, and noted in the log.
4. **Sanity checks and fuzzy match** before creating anything. The row goes to the **review queue**, with the
   reason and the suggested product, if:
   - the barcode is that of an existing product;
   - the SKU equals a known item or supplier reference once O/0 and I/L/1 are made the same, or differs from one
     by two neighbouring characters swapped;
   - the SKU is one edit away from a known one **and** has the same label (a one-character difference alone is
     not a typo: suppliers number products sequentially, AB1234, AB1235);
   - the new product's ref is already used by another product;
   - there is no label;
   - the run has already created the maximum number of new products (default 100), which is what happens when a
     supplier reformats all its SKUs at once.
5. Otherwise, if the feed creates products, a new product is created (and linked); if not, the row is counted as
   *not in Dolibarr*.

A SKU appearing twice in the same file: the first row is used, the others are warnings.

The review queue (*Products → Supplier feeds → Review*) offers, per item: link to the suggested product, link to
another product, create a new product, or ignore the item for good. A linked or created item is updated by the
next run.

### Zeroing guards

Zeroing is skipped, and the run marked *warning*, when:

- the run did not complete (fetch, validation or a database error);
- the file has fewer items than the threshold (default 50 %) of the previous successful run's count;
- more than 2 % of the rows had no usable SKU.

Only items linked to a product and with stock above 0 in the feed's warehouse are corrected. An item seen again
later is simply updated with the new quantity.

## Storage

- `llx_feedimport_feed`: one row per feed: its settings, mapping (JSON), schedule, lock, last run summary.
- `llx_feedimport_item`: one row per SKU per feed: product linked, status (linked, to review, ignored), suggested
  product and reason, last values seen, hash, first and last seen.
- `llx_feedimport_run`: one row per run: trigger (cron, manual, email, upload), file, counts, status, log.
- Files under `documents/feedimport/feed_<id>/`: `files/` (feeds received) and `schema/` (XSD or mapping CSV).

## Permissions and menus

- *Read* (see runs and the review queue) and *Manage* (run now, resolve the review queue). Feed settings need an
  administrator, as any module setup.
- *Products → Supplier feeds*: the runs, and *Review* with the number of items waiting.
- One scheduled job, every 15 minutes, runs the feeds that are due.

## Out of scope for this version

Price formulas (e.g. selling price = buy price × margin), images, product variants, several suppliers in one file,
deleting files on the supplier's server after import. Each can be added to a feed's settings later.

## Changes while building

- **Email files are imported by the scheduled job**, not inside Email Collector's hook: Email Collector runs its
  operations in one database transaction per email, rolled back in its test mode, so an import there would be one
  huge transaction that a test would undo. The hook keeps the file and makes the feed due at once.
- **Typo checks**: the "label ≥ 90 % similar" and "SKU one edit away" rules sent ordinary new products to review
  (size and colour variants have near-identical labels; SKUs are often sequential). They were replaced by the
  O/0 and I/1 confusion, swapped neighbouring characters, and "one edit away with the same label".
- **SFTP host key**: an MD5 fingerprint, because the libcurl of Debian and Ubuntu (libssh) cannot check SHA256 ones.
  *Test connection* shows the server's.
- **A supplier is required** when products are matched by supplier reference, which is the default.
