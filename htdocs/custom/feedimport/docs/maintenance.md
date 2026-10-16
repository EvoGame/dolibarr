# Feed Import module: maintenance guide

For maintainers. See [../README.md](../README.md) for what the module does, and [design.md](design.md) for the
agreed design.

## Where the code lives

The module is kept in our Dolibarr fork at `htdocs/custom/feedimport/`, alongside Infomatical and Square, and is
tracked the same way (see `htdocs/custom/infomatical/docs/maintenance.md`, *Where the code lives*):

- `htdocs/custom/.gitignore` re-includes it with `!/feedimport/`.
- `dev/setup/codesniffer/ruleset.xml` checks it like core code: the `htdocs/custom/` exclusion is
  `custom/(?!infomatical/|square/|feedimport/)`.
- `.github/workflows/feedimport-release.yml` packages it as `square-release.yml` does for Square: a pull request
  merged into `develop` that changes `htdocs/custom/feedimport/` and bumps `$this->version` in
  `modFeedImport.class.php` publishes `module_feedimport-<version>.zip` under the tag `feedimport-v<version>`.
  `test/` is left out of the package.

Deployment, installing from the release package and the *disable and re-enable after changing the descriptor*
rule are the same as for Infomatical.

## Module identity

| Item | Value | Notes |
|---|---|---|
| Module number | `591200` | Range 500000–599999 (modules not on DoliStore). Must not change after deployment. |
| Rights class / module key | `feedimport` | `isModEnabled('feedimport')`, `$user->hasRight('feedimport', 'read' / 'write')`, langs `feedimport@feedimport` |
| Permissions | `59120001` read, `59120002` write | Feed settings need an administrator |
| Minimum Dolibarr | 24.0 | |
| Dependencies | `modProduct` | Stocks, Vendors, Scheduled jobs, Email Collector as needed (the Feeds tab lists what is missing) |
| Hooks | `emailcollectorcard`, `emailcollector` | |
| Scheduled job | `FeedImportFeed::cronRunDue`, every 15 minutes | |
| Files | `documents/feedimport/feed_<id>/files/` (feeds received), `/schema/` (XSD or mapping file), `temp/` | |

## Code map

| File | Role |
|---|---|
| `class/feedimportfeed.class.php` | A feed (`llx_feedimport_feed`): settings (`params` JSON, `mapping` JSON), schedule (`computeNextRun()`), the lock (`claim()`), `run()`, the email inbox (`queueFile()`), the scheduled job (`cronRunDue()`) |
| `class/feedimportimporter.class.php` | One run's import: finding products, typo checks, creating and updating products, supplier prices, stock, zeroing; the review queue's actions |
| `class/feedimportparser.class.php` | Reading CSV and XML as streams, XSD validation and reading (item element, fields, annotations), the two-row mapping file. No database: tested by `test/parser_test.php` |
| `class/feedimporttransport.class.php` | FTP/FTPS/SFTP/HTTP through curl: listing, choosing the file, downloading, the SFTP key fingerprint |
| `class/feedimportrun.class.php` | A run (`llx_feedimport_run`): counters and log |
| `class/actions_feedimport.class.php` | Email Collector hooks |
| `admin/setup.php`, `admin/feed.php` | Feeds list; one feed's tab (settings, schema upload, test, preview, run, upload) |
| `runs.php`, `run.php`, `review.php` | Imports list, one import (log, file, import again), review queue |

## How a run works

`FeedImportFeed::run()`:

1. **Claim**: `UPDATE ... SET lock_date = now WHERE lock_date IS NULL OR lock_date < now - 2 h`. Only the request
   that updated the row runs; the others get "already being imported". `finishRun()` clears it. A run killed
   half-way leaves the lock, which expires after `LOCK_TIMEOUT` (2 hours).
2. **File**: given (upload, *Import this file again*), waiting in the inbox (email), or fetched
   (`FeedImportTransport::selectFile()` then `download()`). Kept in `files/` as `<YYYYMMDD-HHMMSS>_<name>`.
3. **Unchanged**: same SHA-256 as `last_file_hash` → status `unchanged`, the copy is deleted. *Run now* with
   "Import even if the file is the same" and uploads skip this.
4. **Import** (`FeedImportImporter::import()`):
   - every mapped CSV column must be in the header (the SKU's missing is an error; others are warnings);
   - XML with an XSD is validated in a first pass; any error stops the run before anything changes;
   - items, the warehouse's stock and (lazily) the supplier's references are loaded into memory once;
   - each row: values parsed (`rowValues()`), then `importItem()`. A linked item whose values hash is unchanged
     costs no product query; only its stock is compared with the warehouse's actual stock, and its "seen" date is
     updated in bulk (`flushTouched()`);
   - zeroing (`zeroMissing()`), unless a guard trips.
5. **Finish**: run saved, `last_file_hash` and `last_count` saved (`last_count` is not replaced when the item-count
   guard tripped, so a truncated file does not become the reference), next run computed, notification sent on
   `error` or `warning`, files beyond `keep` deleted.

### Finding the product of an item

`importItem()` → `findProduct()` → `doubt()` → `createProduct()`:

| Step | Outcome |
|---|---|
| Item linked, product still exists | Updated (only what differs) |
| Item ignored / to review | Counted, its values kept for the review queue |
| Exact match on the matching key (supplier reference of the feed's supplier, ref, or barcode) | Linked |
| Same normalised SKU (`feedimportNormaliseKey()`: case, spaces, punctuation, leading zeros) as **one** known item or supplier reference, not itself in the file | Linked |
| `doubt()` finds a reason | To review, with the suggested product |
| Feed creates products | Created |
| Otherwise | `not created` (status 3) |

`doubt()` reasons, in order: no label; the new ref is already used; same barcode as a product; the SKU, with O→0 and
I/L→1 (`fold()`), equals a known one; two neighbouring characters swapped; one edit away **and** the same
normalised label; the run has created `maxnew` products already (not on a feed's first import). Known SKUs that are
themselves in the file are never "typos" of a new one. A plain one-character difference is not a reason on its own:
suppliers number products sequentially (AB1234, AB1235).

### Zeroing

After a complete read, every linked item not seen in this run, not already zeroed, whose product is not the product
of an item seen in this run, gets its stock in the feed's warehouse corrected to 0 (`correct_stock()`, inventory code
`FEEDIMPORT-<run id>`), and `zeroed = 1`. Skipped when the run did not complete, when the file has fewer items than
`minratio` % of `last_count`, or when more than 2 % of rows have no SKU, unless *Ignore the safety threshold* is
ticked on *Run now*.

### Pacing

`FeedImportImporter::pace()`, called after each row and each zeroed item: every `blocksize` rows that changed
something (an item saved, a stock corrected; unchanged and ignored rows do not count), it saves the run's progress,
refreshes the feed's lock date (`FeedImportFeed::heartbeat()`, so a long paced run is never taken for a dead one) and
sleeps `pause` seconds. It only spreads the load: every row is still committed by Dolibarr's own methods as it is
imported. `blocksize` 0 turns it off. *Run now* is paced too; the browser may give up waiting on a large file, but
the run carries on (`ignore_user_abort`) and its page shows the progress.

### Dynamic prices

Products are loaded with `fetch(..., $ignore_expression = 1)`: evaluating a price formula also runs the Dynamic prices
module's pending updaters (possibly HTTP requests), for every product. A feed selling price is not written to a product
that has a formula (`fk_price_expression`), since the formula makes its price; the run's log counts them. Formulas see
the supplier prices the feed writes (`#supplier_min_price#` is the lowest of all suppliers). Dolibarr evaluates a
formula when a product is loaded, so lists and exports, which read `llx_product.price`, show the stored price.

### Updating products

Through Dolibarr's own methods, so triggers, logs and price history work as usual: `Product::create()`,
`Product::update()` (only if a field differs), `Product::updatePrice()`, `ProductFournisseur::update_buyprice()`
(the existing supplier price of the supplier with this SKU, else for this quantity; the columns the feed does not
give are passed through unchanged, since `update_buyprice()` rewrites them all), `Product::correct_stock()`. The
ref of an existing product is never changed.

## Email feeds

Email Collector runs every operation whose type starts with `hook` through the `doCollectImapOneCollector` hook,
inside the database transaction it opens for each email (and rolls back in its test mode). Importing there would make
one huge transaction, undone by a test. So `ActionsFeedImport::doCollectImapOneCollector()` only keeps the
attachment (`FeedImportFeed::queueFile()`: file in `files/`, `params.inbox`, `next_run = now`), and the scheduled job
imports it. If Email Collector rolls back, the inbox is not set and the email is collected again next time.

The hook manager keeps one instance of the hook class, and takes any `errors` left on it as a failure of the next
call: both hook methods start with `reset()`.

The operation's parameter is the feed id (`3` or `feed=3`). Attachments come as Webklex objects
(`MAIN_IMAP_USE_PHPIMAP`) or as `name => content`; both are handled.

## SFTP host keys

Ubuntu's and Debian's libcurl use **libssh**, which (a) refuses a host missing from `~/.ssh/known_hosts` unless host
checking is off, and (b) ignores SHA256 fingerprints (`CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256` is libssh2-only). So:

- no fingerprint set: the host is not checked (the feed tab warns);
- an MD5 fingerprint: checked, with either backend;
- a SHA256 fingerprint: checked with libssh2; with libssh the connection is refused (fails closed), and the feed tab
  refuses to save one.

*Test connection* learns the server's MD5 fingerprint by connecting with one that cannot match: libcurl's error names
the server's (`probeHostKeyMd5()`).

## Tests

- `php htdocs/custom/feedimport/test/parser_test.php`: readers and helpers, no database.
- `php htdocs/custom/feedimport/test/import_test.php --test-database`: imports end to end on a **test**
  installation (Products, Stocks, Vendors and Feed Import enabled): matching, typos, review queue, zeroing and its
  guards, XSD rejection, the Email Collector hook and the scheduled job, schedule and lock. It creates records named
  `FITEST...` and deletes them at the end.

Transports were tested by hand against local servers (pyftpdlib for FTP and explicit-TLS FTP, OpenSSH for SFTP,
PHP's built-in server with basic authentication for HTTP): listing, date tokens, newest-file choice, names with
spaces, absolute and relative directories, wrong passwords, self-signed certificates, MD5 fingerprints right and
wrong.

## Settings (`params` of a feed)

| Key | Meaning |
|---|---|
| `host`, `port`, `login`, `password`, `directory`, `pattern`, `passive`, `insecure`, `hostkey`, `url` | Access. `password` is encrypted with `dolEncrypt()`. `pattern` is also the attachment name pattern of email feeds. |
| `delimiter` (`''` detect), `enclosure`, `encoding`, `headerline`, `itempath`, `decimal` | File format |
| `schema_file`, `schema_type` (`xsd`/`mapping`), `fieldtypes`, `fields` | Schema, and the feed fields known |
| `matchkey` (`supplierref`/`ref`/`barcode`), `create`, `refprefix`, `fk_category`, `vat`, `pricebase` (`HT`/`TTC`) | Import |
| `zero`, `minratio`, `maxnew`, `keep`, `notify` | Zeroing, its guard, the new-products limit, files kept, email on failure |
| `blocksize`, `pause` | Pacing: rows that change something per block (0 off), seconds between blocks |
| `inbox`, `inbox_date` | Email: file waiting for the scheduled job |

## Known limits

- **Price formulas** are left to the Dynamic prices module (see above). **No** images, variants, several suppliers in one file, or
  deleting the file on the supplier's server after import.
- **One warehouse per feed.** Two feeds sharing a warehouse overwrite each other's stock (the feed tab warns).
- **Large feeds.** Rows are streamed, and unchanged items cost almost nothing, but a first import creates every
  product through `Product::create()` (about 13 ms and 25 queries each on the test machine, 3,000 items in under a
  minute), plus the pacing pauses. Prefer letting the scheduled job do a first big import rather than *Run now*.
- **Extrafields** are written as text: date and list extrafields must be in the format Dolibarr stores.
- **Repeated XML elements**: only the first occurrence of a repeated child element is read.
