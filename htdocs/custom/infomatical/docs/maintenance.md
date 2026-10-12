# Infomatical module: maintenance guide

For maintainers. See [README.md](README.md) for what the module does.

## Where the code lives

The module is kept in our Dolibarr fork at `htdocs/custom/infomatical/`. Upstream ignores everything
in `htdocs/custom/`; our fork re-includes this one directory with the line `!/infomatical/` in
`htdocs/custom/.gitignore`. If an upstream merge ever conflicts on that file, keep that line.

Two upstream CI files are adjusted for the same reason, because upstream assumes nothing under `htdocs/custom/`
is tracked. Keep both changes if a merge conflicts on them:

- `dev/setup/codesniffer/ruleset.xml`: the `htdocs/custom/` exclusion skips `infomatical/`, so PHP
  CodeSniffer checks the module like core code. Without it, the pre-commit job fails with "No files were
  checked" on any pull request whose PHP changes are all in the module.
- `dev/translation/sanity_check_trans_missing_unused.sh`: `htdocs/custom/` is left out of the scan, because
  the module's keys are in its own `langs/`, not in `htdocs/langs/`. Without it, every module key is
  reported as a missing translation.

## Deployment

1. Make sure the `custom` directory is active in `conf/conf.php`:
   ```php
   $dolibarr_main_url_root_alt = '/custom';
   $dolibarr_main_document_root_alt = '/path/to/dolibarr/htdocs/custom';
   ```
2. Deploy the fork as usual. The module is under `htdocs/custom/infomatical/`.
3. **Home → Setup → Modules**: enable *Ticket* first (Infomatical depends on it), then *Infomatical*
   (family *Customer Relationship Management*).
4. After **any** change to `modInfomatical.class.php` (menus, hooks, triggers, constants) or to `sql/`,
   disable and re-enable the module. Dolibarr copies the descriptor into the database (`llx_menu`,
   `llx_const`) and runs the module's SQL files only on activation. The SQL files are safe to run again:
   "already exists" errors are ignored.
   - **Upgrading from 0.1.0:** re-enabling is what creates `llx_infomatical_ticketplan` and adds the
     *Plan my day* menu entry.

### Installing on a stock Dolibarr (release package)

A Dolibarr that is not our fork installs the module from its release package instead:

1. Download `module_infomatical-<version>.zip` from the repository's GitHub releases (tags
   `infomatical-v<version>`). A `.sha256` file is published next to it.
2. **Home → Setup → Modules → Deploy/install an external app/module**: upload the zip. The `custom`
   directory must be active and writable (step 1 above). Dolibarr replaces any existing
   `custom/infomatical/` with the package's contents.
3. Enable *Ticket*, then *Infomatical*. When upgrading, disable and re-enable *Infomatical* (see step 4
   above).

### Releases

`.github/workflows/infomatical-release.yml` publishes a release when a pull request that changes
`htdocs/custom/infomatical/` is merged into `develop`:

- The version is `$this->version` in `modInfomatical.class.php`, and the tag is `infomatical-v<version>`.
  Digits and dots only, because the Dolibarr installer rejects anything else in the zip name.
- **Bump `$this->version` in any pull request you want released.** If the tag already exists, the run
  publishes nothing and ends with a warning. Existing tags and releases are never moved or overwritten.
- Before packaging, the workflow runs `php -l` on every PHP file and checks that `en_US` and `en_GB` have
  the same translation keys. If either check fails, nothing is released.
- The package is the whole module directory except hidden files, with `infomatical/` at the root of the
  zip.

To release by hand (for example after a failed run), build the same zip from `htdocs/custom`:
`zip -r -X module_infomatical-<version>.zip infomatical -x "*/.*"`.

### AI tool allow-list

If the AI module's tool allow-list has been configured (**Home → Setup → Modules → AI**, setup page, tab *Tool Access Control*; constants
`AI_ASSISTANT_ALLOWED_TOOLS` and `AI_MCP_SERVER_ALLOWED_TOOLS`), new tools are **not** allowed
automatically. Tick the `infomatical_*` tools there after enabling the module or upgrading it. The
module's own setup page shows which of its tools are allowed or blocked, and links to that page. While the list has
never been configured, all tools are allowed.

## Module identity

| Item | Value | Notes |
|---|---|---|
| Module number | `591000` | 500000–599999 is the range Dolibarr leaves for modules not published on DoliStore. Must not change after deployment: permission ids and stored settings derive from it. |
| Rights class / module key | `infomatical` | Used by `isModEnabled('infomatical')`, `$user->hasRight('infomatical', …)`, langs `infomatical@infomatical` |
| Minimum Dolibarr | 24.0 | The AI write tools need 25.0 or later; see [Running on Dolibarr 24](#running-on-dolibarr-24) |
| Dependencies | `modTicket` | |

When the module gains its own permissions, number them `591000` + two digits (e.g. `59100001`),
following `modulebuilder/template/core/modules/modMyModule.class.php`.

## Running on Dolibarr 24

The module runs on Dolibarr 24.0 (tested on 24.0.2, PostgreSQL) as well as on our 25 fork. Three things in
25 that it uses are missing from 24, and it handles each at run time, with no setting to change:

| Missing in 24 | What the module does on 24 | Where |
|---|---|---|
| `getObjectIdsRefusedToUser()` | Runs `checkUserAccessToObject()` on each ticket, which is all the 25 function does for tickets | `infomaticalTicketIdsRefusedToUser()` in `lib/infomatical.lib.php` |
| Rights check in `McpHandler` (`getRequiredRights()`) | Each tool checks its own `getRequiredRights()` at the start of `execute()`. On 25 the rights are therefore checked twice, which is harmless. | `infomaticalAiMissingRight()` |
| Write confirmation in `McpHandler` (`writeConfirmationPreview()`) | The four write tools are **not registered**, and the setup page marks them *Not available*. Only `infomatical_my_open_tickets` is offered. | `infomaticalAiConfirmsWrites()`, `ActionsInfomatical::addMcpTools()` |

Why the write tools are withheld on 24 rather than offered without confirmation:

- 24's `McpHandler::executeTool()` runs a tool as soon as it is called.
- The assistant's own confirmation (`AI_ASK_FOR_CONFIRMATION`) is off unless an admin turns it on. At level 1
  it only matches tool names containing create/update/delete/add/remove/modify/edit, so it would never
  stop `infomatical_ticket_assign` or `infomatical_ticket_set_status`.
- `ai/assistant/execute_tool.php` runs whatever tool call the browser sends, so even that confirmation is a
  client-side step.
- 24's MCP server has no confirmation at all.

Also on 24, the MCP server runs every call as one service user (`AI_MCP_USER_ID`), so
`infomatical_my_open_tickets` called through it returns **that user's** queue. The chat assistant runs as
the logged-in user, as on 25.

When we stop supporting 24, remove the three functions above and call the core equivalents directly.

## Upstream-merge checklist

The fork tracks Dolibarr `develop`, where internal APIs change without notice. After each merge from
upstream, check the items below. Each line names what we depend on and where to look.

| Dependency | Check | Where |
|---|---|---|
| Hook `addMcpTools` in context `aimcp` | Still called, same parameters (`db`, `user`, `conf`), results still read from `resArray` | `htdocs/ai/class/mcp.class.php`, `loadExternalTools()` |
| `McpTool` base class | Abstract methods and `getRequiredRights()` / `writeConfirmationPreview()` / `writePrecheck()` contract unchanged | `htdocs/ai/class/mcptool.class.php` |
| Tool allow-lists | `McpHandler::resolveAllowList()` and the two `AI_*_ALLOWED_TOOLS` constants still exist (used by the setup page) | `htdocs/ai/class/mcp.class.php` |
| Write confirmation | `McpHandler::checkWriteConfirmation()` still runs precheck, preview, then execute on confirmation | `htdocs/ai/class/mcp.class.php` |
| Ticket write methods | `assignUser()`, `close()`, `setStatut()`, `setReadDate()`, `update()`, `setProgression()`, `createTicketMessage()` keep their signatures | `htdocs/ticket/class/ticket.class.php` |
| Ticket card rules | Rights and steps for assign, status change, close, edit, add message still match the AI tools | `htdocs/ticket/card.php` |
| Intent categories for tickets | Ticket words still map to `thirdparty` / `project` | `htdocs/ai/assistant/parse_intent.php`, `classifyIntentUniversal()` |
| Ticket statuses | `Ticket::STATUS_CLOSED` (8) and `STATUS_CANCELED` (9) still mean "not open" | `htdocs/ticket/class/ticket.class.php` |
| Ticket columns | `fk_user_assign`, `fk_statut`, `severity_code`, `datec`, `date_last_msg_sent` still exist | `htdocs/install/mysql/tables/llx_ticket-ticket.sql` |
| Contact type | Internal ticket contact code is still `SUPPORTTEC` | `htdocs/install/mysql/data/llx_c_type_contact.sql` |
| Severity cache | `loadCacheSeveritiesTickets()` still fills `$conf->cache['severity_tickets']` with `code`, `label`, `pos` | `htdocs/ticket/class/ticket.class.php` |
| Ticket left menu | Parent `fk_mainmenu=ticket,fk_leftmenu=ticket` still exists | `htdocs/core/modules/modTicket.class.php` |
| Ticket deletion | `Ticket::delete()` still calls the `TICKET_DELETE` trigger, and runs it before deleting the row | `htdocs/ticket/class/ticket.class.php` |
| Access filtering | `getObjectIdsRefusedToUser()` still exists and handles `ticket`; on 24 the fallback uses `checkUserAccessToObject()`, whose ticket rule must still match | `htdocs/core/lib/security.lib.php` |
| Version detection | `McpHandler::checkWriteConfirmation()` keeps that name: `infomaticalAiConfirmsWrites()` looks for it, and if it is renamed the write tools silently disappear | `htdocs/ai/class/mcp.class.php` |
| Module SQL loading | `_load_tables()` still returns `<= 0` on failure | `htdocs/core/modules/DolibarrModules.class.php` |

Quick scan for changes since the last merge (replace `<previous-merge>` with that commit):

```sh
git diff <previous-merge>..HEAD --stat -- htdocs/ai/class htdocs/ticket/class \
    htdocs/install/mysql/tables/llx_ticket-ticket.sql htdocs/core/modules/modTicket.class.php
```

Then smoke-test in a browser: open **Tickets → My ticket queue**, and ask the AI assistant
"what tickets are on my plate?".

## Adding features

Follow the conventions in `htdocs/modulebuilder/template/AGENTS.md` (tabs for indentation,
`GETPOST()` with a type, `$db->escape()` or integer casts in SQL, `$langs->trans()` for every string,
CSRF token on every POST form).

**A new hook on ticket screens**

1. Find the context and method: `grep -rn "initHooks\|executeHooks" htdocs/ticket/`.
   The ticket card uses contexts `ticketcard` and `globalcard`; the list uses `ticketlist`.
2. Add the context to `module_parts['hooks']` in the descriptor.
3. Add a public method of the same name to `ActionsInfomatical`. Return `0` to run alongside core
   code; return `1` from an *addreplace* hook (e.g. `doActions`) only when the core behaviour must be
   skipped entirely.
4. Disable and re-enable the module.

Where no hook exists, add one to the core file in the fork with the standard `executeHooks()` pattern
and offer it upstream as a pull request. That is far cheaper than maintaining a copied core page.

**A new AI tool**

Add a definition to `ToolInfomaticalTickets::getDefinitions()` (reads) or `ToolInfomaticalTicketWrites::getDefinitions()`
(writes) and a `case` in `execute()`, or create a
new `McpTool` subclass and append it to the array returned by `addMcpTools()`. Tool names must start
with `infomatical_` to avoid clashes with core tools. Any tool that writes data must implement
`writeConfirmationPreview()` (and `writePrecheck()` where useful) so the user approves the change first.

**Storage**

Use ticket extra fields for single values shown on the ticket card. For anything else, add
`sql/llx_infomatical_<name>.sql` (and `.key.sql`). `modInfomatical::init()` already loads everything in
`sql/` with `_load_tables()`. Never alter core tables.

- **Compatibility.** Write the SQL in MySQL syntax. Dolibarr converts it for PostgreSQL.
- **Duplicates.** Avoid relying on unique-key errors inside a transaction: on PostgreSQL a failed
  statement aborts the whole transaction. `InfomaticalTicketPlan::add()` checks for existing rows first.
- **Foreign keys.** Do not add a foreign key to `llx_ticket`, because `Ticket::delete()` deletes the
  ticket without knowing about our tables. Clean up in the trigger instead.

**Translations**

Every string has a key in **both** `langs/en_US/infomatical.lang` (US English) and
`langs/en_GB/infomatical.lang` (British English). Keep the two files' keys identical:

```sh
diff <(grep -oE '^[A-Za-z]+' langs/en_US/infomatical.lang | sort) \
     <(grep -oE '^[A-Za-z]+' langs/en_GB/infomatical.lang | sort)
```

Two limits of core's `$langs->trans()`:

- It passes at most **four** values into a string; a fifth argument is taken as a maximum length.
- It does not support positional placeholders (`%1$s`).

Split long sentences rather than exceed either limit. The AI tools' descriptions and error messages are
English on purpose: they are read by the language model, not shown in the UI.

## Known limits

- The queue is capped at 500 tickets. The 500 oldest are loaded, then sorted by severity.
- `Ticket::load_board()` (the open-ticket counter on the home page) counts **all** open tickets, not the
  user's. It has no hook, so it cannot be changed from this module.
- A ticket has one assignee. Other technicians appear in the queue only as *Ticket manager* contacts.
- Day plans are personal. Team leads cannot yet see their technicians' plans.
- Estimates are planning figures only: they are not linked to time spent (agenda events or interventions).
