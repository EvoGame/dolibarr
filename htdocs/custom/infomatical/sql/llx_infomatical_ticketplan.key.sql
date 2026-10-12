-- Copyright (C) 2026	Infomatical
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.

-- No foreign key on fk_ticket: Ticket::delete() would fail on it. The module trigger removes rows on TICKET_DELETE.
ALTER TABLE llx_infomatical_ticketplan ADD UNIQUE INDEX uk_infomatical_ticketplan (entity, fk_user, date_plan, fk_ticket);
ALTER TABLE llx_infomatical_ticketplan ADD INDEX idx_infomatical_ticketplan_fk_ticket (fk_ticket);
