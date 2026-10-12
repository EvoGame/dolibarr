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

### Plan my day

Menu: **Tickets → Plan my day** (`plan.php`), for internal users with the `ticket read` permission.
Each technician plans their own days. Nobody can see or change another person's plan.

- **Day navigation.** Previous/next day buttons and a date selector. Opens on today, in the user's timezone.
- **Planned list.** The day's tickets in the order the technician will work them. Each row has a move
  up/down control, an estimate in minutes (in steps of 15), a short note, and a remove action. Closed
  tickets stay in the list with their status, as a record of what was done.
- **Summary.** Number of tickets, total estimate against the working day, tickets done, open work left,
  and either the free time or an *overbooked* warning.
- **Not planned for this day.** The rest of the user's queue (the same tickets as *My ticket queue*), each
  with an *Add* button and a *Last planned* link to the most recent earlier day it was on. Only tickets
  in the user's own queue can be added. The server checks this; it does not rely on the form.
- **Carry forward.** One button copies the unfinished tickets from the most recent earlier plan onto
  this day, keeping their estimates and notes.

Settings (**Home → Setup → Other**; created when the module is enabled, kept when it is disabled):

| Constant | Default | Meaning |
|---|---|---|
| `INFOMATICAL_WORKDAY_MINUTES` | `450` (7h30) | Length of the working day the plan is measured against |
| `INFOMATICAL_DEFAULT_PLAN_MINUTES` | `60` | Estimate given to a ticket when it is added |

Storage is the table `llx_infomatical_ticketplan`: one row per ticket per technician per day, unique on
`(entity, fk_user, date_plan, fk_ticket)`. The row holds the order (`rang`), the estimate in minutes
(`duration_planned`) and the note.

Days are stored as `YYYY-MM-DD` strings in the user's calendar, so a timezone conversion can never move a
plan to the neighbouring day.

There is deliberately no foreign key to `llx_ticket`, because `Ticket::delete()` would fail on it.
Instead, the module trigger deletes the plan rows on `TICKET_DELETE`, and every read joins
`llx_ticket`, so any leftover row is never shown.

Planned tickets the user can no longer open (for example after a reassignment, with
`TICKET_LIMIT_VIEW_ASSIGNED_ONLY` on) are hidden, using the same core rules as the queue.

### My ticket queue

Menu: **Tickets → My ticket queue** (`myqueue.php`), internal users with `ticket read` permission.

Lists open tickets (status not *Closed* or *Cancelled*) where the current user is either:

- the assignee (`llx_ticket.fk_user_assign`), or
- an internal *Ticket manager* contact (`llx_element_contact` with contact type code `SUPPORTTEC`).

Tickets the user could not open on the ticket card are left out, using the same core rules: core's
`getObjectIdsRefusedToUser()` (entity, external users, sales-representative restriction), and, when
`TICKET_LIMIT_VIEW_ASSIGNED_ONLY` is on, assigned tickets only for users without the *manage* right.

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
tool out of the prompt. The write tools below carry the same categories.

### AI write tools

| Tool | What it does | Right required |
|---|---|---|
| `infomatical_ticket_assign` | Assigns a ticket to a user (by login, or `me`), or removes the assignee | ticket *manage* |
| `infomatical_ticket_set_status` | Sets `read`, `in_progress`, `need_more_info`, `waiting`, `closed` or `canceled` | ticket *manage* |
| `infomatical_ticket_update` | Changes severity and/or progress | ticket write |
| `infomatical_ticket_add_note` | Adds a private note (never shown or emailed to the customer) | ticket read |

The rights match what `ticket/card.php` requires for the same action. *Manage* means ticket write, or
the "manage tickets" advanced permission (`manage_advance`) when `MAIN_USE_ADVANCED_PERMS` is on.

How a write runs:

1. The AI module's `McpHandler` calls `writePrecheck()`, which refuses changes that cannot work. For
   example: unknown ticket or user, a closed ticket, no actual change, or a status disabled in ticket setup.
2. It then shows the user the sentence from `writeConfirmationPreview()` and stores a confirmation tied
   to the user and the exact arguments (`AiWriteConfirmation`, 10 minutes by default). If the
   arguments change, the confirmation is refused.
3. Only after the user confirms does `execute()` run. It repeats the checks, because the ticket may have
   changed in between, then calls the same core `Ticket` methods as the ticket card. Core triggers,
   and therefore notifications, fire as they would from the UI.

Every tool checks access to the ticket with the same rules as the ticket card: `restrictedArea()`, plus
`TICKET_LIMIT_VIEW_ASSIGNED_ONLY`.

Deliberate differences from the ticket card:

- **Assigning** adds the new assignee as a *Ticket manager* contact but keeps the other contacts. The card
  removes them, which would drop technicians from each other's queues.
- **Closed or canceled tickets** cannot be reopened or edited through the AI. Use the ticket card.
- **Notes are private only.** Replies to the customer (public messages, email) are not available yet,
  because sending email on the AI's behalf needs its own decision.

## How it plugs into Dolibarr

| Extension point | Declared in | Implemented in |
|---|---|---|
| Left menu entries under `mainmenu=ticket` | `modInfomatical::$menu` | `plan.php`, `myqueue.php` |
| Trigger `TICKET_DELETE` | `modInfomatical::$module_parts['triggers']` | `InterfaceInfomaticalTriggers` |
| Table `llx_infomatical_ticketplan` | `sql/`, loaded by `modInfomatical::init()` | `InfomaticalTicketPlan` |
| Settings | `modInfomatical::$const` | read in `plan.php` |
| Hook context `aimcp`, method `addMcpTools` | `modInfomatical::$module_parts['hooks']` | `ActionsInfomatical::addMcpTools()` |
| AI tool classes (extend core `McpTool`) | registered by the hook | `ToolInfomaticalTickets` (read), `ToolInfomaticalTicketWrites` (write) |
| Shared query | none | `InfomaticalTicketQueue::fetchOpenForUser()` |

The dashboard and the AI tool share `InfomaticalTicketQueue`, so "my queue" means the same thing in
both places. Change the rule there, not in either caller.

The day plan also uses `InfomaticalTicketQueue` for its "not planned" list and to check what may be
added. New storage should follow the day plan's lead: an `llx_infomatical_*` table keyed on
`llx_ticket.rowid`, or ticket extra fields. Never alter core tables.

## File layout

```
infomatical/
├── core/modules/modInfomatical.class.php   Module descriptor (id, menus, hooks, dependencies)
├── core/triggers/interface_99_modInfomatical_InfomaticalTriggers.class.php  Cleans plans of deleted tickets
├── class/actions_infomatical.class.php     Hook implementations
├── class/infomaticalticketplan.class.php   Day plans (llx_infomatical_ticketplan)
├── class/infomaticalticketqueue.class.php  Shared "my open tickets" query
├── class/toolinfomaticaltickets.class.php  AI / MCP read tool
├── class/toolinfomaticalticketwrites.class.php  AI / MCP write tools
├── langs/en_US/infomatical.lang            Translations, US English (reference and fallback)
├── langs/en_GB/infomatical.lang            Translations, British English (complete)
├── sql/llx_infomatical_ticketplan*.sql     Day-plan table and indexes
├── plan.php                                Plan my day
├── myqueue.php                             My ticket queue
├── README.md                               Short public description, shown on the module page
└── docs/                                   This documentation
```
