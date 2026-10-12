# Infomatical module: maintainer documentation

Infomatical is written for our own use. The source is public, but we do not market, package or support it
for anyone else. Because the repository is public, never put credentials, internal hostnames or customer
data in these documents.

| Document | Contents |
|---|---|
| This file | What the module does and how it hooks into Dolibarr |
| [maintenance.md](maintenance.md) | Deployment, upgrades, the upstream-merge checklist, adding features |

## Purpose

Infomatical adds features to the core **Ticket** module without replacing it. Core Dolibarr keeps
owning the ticket tables, screens, email collector, public interface and REST API. Infomatical reads
and writes the same data through the core `Ticket` class and adds its own pages, hooks and AI tools.

We chose enhancement rather than replacement because:

- core pages cannot be shadowed: `dol_buildpath()` searches the core directory before `htdocs/custom`;
- the email collector, public interface, notifications, REST API and AI module all call the core
  `Ticket` class, so the core module must stay enabled anyway;
- a copied ticket UI would stop receiving upstream fixes.

## Features

### My ticket queue

Menu: **Tickets → My ticket queue** (`myqueue.php`), internal users with `ticket read` permission.

Lists open tickets (status not *Closed* or *Cancelled*) where the current user is either:

- the assignee (`llx_ticket.fk_user_assign`), or
- an internal *Ticket manager* contact (`llx_element_contact` with contact type code `SUPPORTTEC`).

Sorted by severity (dictionary position `llx_c_ticket_severity.pos`, highest first), then by creation
date (oldest first). Capped at 500 rows (`InfomaticalTicketQueue::MAX_ROWS`).

This differs from core **My tickets** (`ticket/list.php?mode=mine`), which shows tickets assigned to
*or created by* the user and ignores contacts.

### AI tool `infomatical_my_open_tickets`

Returns the same queue as the dashboard, as JSON, to the AI assistant and the MCP server. It requires
the `ticket read` permission and runs as the calling user, so nobody can read another person's queue.

It is tagged with the intent categories `thirdparty` and `project`, because those are the categories
the assistant activates for the words "ticket", "support" and "incident" (see `classifyIntentUniversal()`
in `htdocs/ai/assistant/parse_intent.php`). Without a matching category, the assistant filters the
tool out of the prompt.

## How it plugs into Dolibarr

| Extension point | Declared in | Implemented in |
|---|---|---|
| Left menu entry under `mainmenu=ticket` | `modInfomatical::$menu` | `myqueue.php` |
| Hook context `aimcp`, method `addMcpTools` | `modInfomatical::$module_parts['hooks']` | `ActionsInfomatical::addMcpTools()` |
| AI tool class (extends core `McpTool`) | registered by the hook | `ToolInfomaticalTickets` |
| Shared query | none | `InfomaticalTicketQueue::fetchOpenForUser()` |

The dashboard and the AI tool share `InfomaticalTicketQueue`, so "my queue" means the same thing in
both places. Change the rule there, not in either caller.

The module creates no tables yet. New features that need storage (for example planned dates for a
technician's day) should use their own `llx_infomatical_*` tables keyed on `llx_ticket.rowid`, or
ticket extra fields; never alter core tables.

## File layout

```
infomatical/
├── core/modules/modInfomatical.class.php   Module descriptor (id, menus, hooks, dependencies)
├── class/actions_infomatical.class.php     Hook implementations
├── class/infomaticalticketqueue.class.php  Shared "my open tickets" query
├── class/toolinfomaticaltickets.class.php  AI / MCP tools
├── langs/en_US/infomatical.lang            Translations
├── myqueue.php                             Technician dashboard
├── README.md                               Short public description, shown on the module page
└── docs/                                   This documentation
```
