-- Copyright (C) 2026	Infomatical
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- A run of a feed: the file imported, what changed, and its log
CREATE TABLE llx_feedimport_run(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_feed integer NOT NULL,
	trigger_type varchar(16) NOT NULL,			-- cron, manual, email, upload
	status varchar(16) NOT NULL,				-- running, ok, unchanged, warning, error
	filename varchar(255),						-- file kept in documents/feedimport/feed_<id>/files/
	remote_name varchar(255),					-- name of the file on the server or in the email
	file_hash varchar(64),
	nb_rows integer DEFAULT 0 NOT NULL,			-- items in the file
	nb_created integer DEFAULT 0 NOT NULL,
	nb_updated integer DEFAULT 0 NOT NULL,
	nb_unchanged integer DEFAULT 0 NOT NULL,
	nb_review integer DEFAULT 0 NOT NULL,		-- sent to the review queue
	nb_skipped integer DEFAULT 0 NOT NULL,		-- not in Dolibarr and not created, or ignored
	nb_zeroed integer DEFAULT 0 NOT NULL,
	nb_warnings integer DEFAULT 0 NOT NULL,
	nb_errors integer DEFAULT 0 NOT NULL,
	log mediumtext,
	date_start datetime NOT NULL,
	date_end datetime,
	fk_user integer
) ENGINE=innodb;
