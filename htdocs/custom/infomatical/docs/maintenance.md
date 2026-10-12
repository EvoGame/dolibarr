# Infomatical module: maintenance guide

**Internal use only. Not for distribution outside the company.**

## Where the code lives

The module is kept in our Dolibarr fork at `htdocs/custom/infomatical/`. Upstream ignores everything
in `htdocs/custom/`, and the root `.gitignore` ignores every `.htaccess`. Our fork re-includes the module
with two lines in `htdocs/custom/.gitignore`: `!/infomatical/` and `!/infomatical/docs/.htaccess`.
If an upstream merge ever conflicts on that file, keep both lines.

## Deployment

1. Make sure the `custom` directory is active in `conf/conf.php`:
   ```php
   $dolibarr_main_url_root_alt = '/custom';
   $dolibarr_main_document_root_alt = '/path/to/dolibarr/htdocs/custom';
   ```
2. Deploy the fork as usual. The module is under `htdocs/custom/infomatical/`.
3. **Home → Setup → Modules**: enable *Ticket* first (Infomatical depends on it), then *Infomatical*
   (family *Customer Relationship Management*).
4. After **any** change to `modInfomatical.class.php` (menus, hooks, permissions), disable and
   re-enable the module. Dolibarr copies the descriptor into the database (`llx_menu`,
   `llx_const` for hooks) only on activation.

### AI tool allow-list

If the AI module's tool allow-list has been configured (**Home → Setup → Modules → AI**, setup page, tab *Tool Access Control*; constants
`AI_ASSISTANT_ALLOWED_TOOLS` and `AI_MCP_SERVER_ALLOWED_TOOLS`), new tools are **not** allowed
automatically. Tick `infomatical_my_open_tickets` there after enabling the module. While the list has
never been configured, all tools are allowed.

### Web exposure

Everything under `htdocs/` can be served by the web server. `docs/.htaccess` (`Require all denied`)
blocks these documents on Apache with `AllowOverride` enabled. On nginx, or with `AllowOverride None`,
add an equivalent rule, for example:

```nginx
location ~ ^/custom/infomatical/docs/ { deny all; }
```

Check with `curl -I https://<dolibarr>/custom/infomatical/docs/README.md`: it must return `403`.

## Module identity

| Item | Value | Notes |
|---|---|---|
| Module number | `591000` | 500000–599999 is the range Dolibarr leaves for modules not published on DoliStore. Must not change after deployment: permission ids and stored settings derive from it. |
| Rights class / module key | `infomatical` | Used by `isModEnabled('infomatical')`, `$user->hasRight('infomatical', …)`, langs `infomatical@infomatical` |
| Minimum Dolibarr | 25.0 (alpha) | The `aimcp` / `addMcpTools` hook comes from the 25.0 development branch |
| Dependencies | `modTicket` | |

When the module gains its own permissions, number them `591000` + two digits (e.g. `59100001`),
following `modulebuilder/template/core/modules/modMyModule.class.php`.

## Upstream-merge checklist

The fork tracks Dolibarr `develop`, where internal APIs change without notice. After each merge from
upstream, check the items below. Each line names what we depend on and where to look.

| Dependency | Check | Where |
|---|---|---|
| Hook `addMcpTools` in context `aimcp` | Still called, same parameters (`db`, `user`, `conf`), results still read from `resArray` | `htdocs/ai/class/mcp.class.php`, `loadExternalTools()` |
| `McpTool` base class | Abstract methods and `getRequiredRights()` / `writeConfirmationPreview()` contract unchanged | `htdocs/ai/class/mcptool.class.php` |
| Intent categories for tickets | Ticket words still map to `thirdparty` / `project` | `htdocs/ai/assistant/parse_intent.php`, `classifyIntentUniversal()` |
| Ticket statuses | `Ticket::STATUS_CLOSED` (8) and `STATUS_CANCELED` (9) still mean "not open" | `htdocs/ticket/class/ticket.class.php` |
| Ticket columns | `fk_user_assign`, `fk_statut`, `severity_code`, `datec`, `date_last_msg_sent` still exist | `htdocs/install/mysql/tables/llx_ticket-ticket.sql` |
| Contact type | Internal ticket contact code is still `SUPPORTTEC` | `htdocs/install/mysql/data/llx_c_type_contact.sql` |
| Severity cache | `loadCacheSeveritiesTickets()` still fills `$conf->cache['severity_tickets']` with `code`, `label`, `pos` | `htdocs/ticket/class/ticket.class.php` |
| Ticket left menu | Parent `fk_mainmenu=ticket,fk_leftmenu=ticket` still exists | `htdocs/core/modules/modTicket.class.php` |

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

Add a definition to `ToolInfomaticalTickets::getDefinitions()` and a `case` in `execute()`, or create a
new `McpTool` subclass and append it to the array returned by `addMcpTools()`. Tool names must start
with `infomatical_` to avoid clashes with core tools. Any tool that writes data must implement
`writeConfirmationPreview()` (and `writePrecheck()` where useful) so the user approves the change first.

**Storage**

Use ticket extra fields for single values shown on the ticket card. For anything else, add
`sql/llx_infomatical_<name>.sql` (and `.key.sql`) and load them in `modInfomatical::init()` with
`$this->_load_tables('/infomatical/sql/')`. Never alter core tables.

## Known limits

- The queue is capped at 500 tickets. The 500 oldest are loaded, then sorted by severity.
- `Ticket::load_board()` (the open-ticket counter on the home page) counts **all** open tickets, not the
  user's. It has no hook, so it cannot be changed from this module.
- A ticket has one assignee. Other technicians appear in the queue only as *Ticket manager* contacts.
