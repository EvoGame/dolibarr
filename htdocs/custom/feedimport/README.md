# Feed Import

Dolibarr module that imports suppliers' product feeds (CSV or XML files) into products, every day, without anyone
opening the files:

- **Any number of feeds**, each set up on its own tab of the module setup: *Home → Setup → Modules → Feed Import*,
  or *Products → Supplier feeds → Feeds setup*. *Add new feed* adds a tab.
- **Where the file comes from:** FTP, FTPS (explicit or implicit TLS), SFTP, an HTTP(S) URL, or an email: the
  module adds an operation, *Feed import: import the attached file*, to the rules of Dolibarr's **Email Collector**,
  which keeps the mailbox, its filters and its schedule. A file can also be uploaded by hand.
- **What it updates:** label, description, barcode, weight, selling price, cost price, the supplier's buy price
  (with the SKU as supplier reference), product extrafields, and the **supplier's stock available to buy**, kept in
  a warehouse of the feed's own. Stock bought for the shop is moved out of it with a normal warehouse transfer.
- **Mapping:** which feed field goes into which Dolibarr field is set on the feed's tab. For XML, upload the
  supplier's **XSD**: it gives the fields and their types, every file is checked against it before anything is
  imported, and `dolibarr:field="..."` annotations in it fill the mapping. For CSV, upload a **two-row file**:
  the feed's column titles, then the Dolibarr field of each. *Fetch & preview* reads a file and shows its first
  items as they would be imported.
- **New items become products**, unless they look like a product Dolibarr already has: same barcode, an SKU with
  O/0 or I/1 mixed up or two characters swapped, an SKU one character away *and* the same label, a ref already
  used, no label, or more new items in one run than the feed allows (a supplier who reformats all its SKUs). Those
  go to the **review queue** (*Products → Supplier feeds → Review queue*): link to the suggested product, to
  another one, create, or ignore. SKUs differing only by case, spaces, punctuation or leading zeros (`AB-0123` /
  `ab123`) are the same item.
- **Items that leave the feed** keep their product; their stock in the feed's warehouse is set to 0. Dolibarr's
  virtual stock is computed from the physical stock and open orders, so it follows. A bad file never zeroes the
  catalogue: nothing is zeroed when the file was not read to the end, failed the XSD, has far fewer items than the
  last one (threshold per feed, 50 % by default), or has too many rows without SKU.
- **Every run is recorded** (*Products → Supplier feeds → Imports*): the file (kept, 7 by default), what was
  created, updated, sent to review and set to 0, and a log. A failed run, or one needing a look, can email someone.
  A file identical to the last one imported is skipped.

The agreed design is in [docs/design.md](docs/design.md).

## Requirements

- Dolibarr 24.0 or later, PHP 7.4 or later with the curl, xmlreader and dom extensions.
- Modules *Products* (required), *Stocks* (for stock), *Vendors* (for buy prices and supplier references),
  *Scheduled jobs* (for schedules and email feeds), *Email Collector* (for email feeds), *Categories* (optional).
- SFTP needs PHP's curl built with SFTP support (libssh or libssh2), as on Debian and Ubuntu. *Setup → Feeds*
  says if a protocol is missing.

## Setting up a feed

1. Enable the module (*Home → Setup → Modules*, family *Products*). Give users *See supplier feed imports and the
   review queue* and, to run feeds and work the review queue, *Run supplier feeds and resolve the review queue*.
2. Create a warehouse for the supplier's stock (e.g. *Acme – available*), one per feed.
3. *Feeds setup → Add new feed*, then, in order:
   1. **Retrieval method.**
   2. **File type**: CSV (delimiter, encoding, line of the titles) or XML (item element), and the decimal
      separator.
   3. **Schema and mapping**: upload the XSD or the two-row mapping file, or save the feed, use *Fetch & preview*
      to read the file's fields, and map them. Only the **SKU** is required; map a **label** for new products to be
      created.
   4. **Access**: server, login, password (stored encrypted), directory, and the file name, with wildcards and
      date tokens (`stock_{YYYYMMDD}.csv`, `*.csv` takes the newest). For SFTP, *Test connection* shows the
      server's key fingerprint: check it with the supplier and enter it, so the server is checked at every
      connection. For email, the tab says which operation to add to which Email Collector rule.
   5. **Schedule**: daily at a time, or every N hours. Email feeds are imported when the file arrives.
   6. **Other settings**: supplier, warehouse, how existing products are found (supplier reference, ref or
      barcode), create new products or not, ref prefix (recommended: two suppliers may use the same SKUs),
      category, default VAT, prices with or without VAT, zeroing and its safety threshold, most new products per
      run, files kept, email on failure.
4. *Test connection*, *Fetch & preview*, then *Run now*. Check the run, and the review queue.

### Mapping file (CSV)

```
SKU;Description;Trade price;Free stock;EAN
sku;label;buy_price;stock;barcode
```

Dolibarr fields: `sku`, `ref`, `label`, `description`, `barcode`, `weight` (kg), `sell_price`, `cost_price`,
`buy_price`, `min_qty`, `vat_rate`, `stock`, and `options_<code>` for a product extrafield. Their labels as shown
on the feed tab are accepted too. *Download the mapping as a two-row file* on the feed tab gives the current
mapping in this format.

### XSD annotations (XML)

```xml
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:dolibarr="urn:dolibarr">
  ...
  <xs:element name="code" type="xs:string" dolibarr:field="sku"/>
  <xs:element name="netPrice" type="xs:decimal" dolibarr:field="buy_price"/>
```

Fields are named by their path under the item element: `price`, `stock/available`, `@id` for an attribute.

Maintainers: see [docs/maintenance.md](docs/maintenance.md).
