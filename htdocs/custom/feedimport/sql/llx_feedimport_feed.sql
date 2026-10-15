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

-- A supplier feed: where and when its file is fetched, how it is read, and what it updates
CREATE TABLE llx_feedimport_feed(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	label varchar(128) NOT NULL,
	status smallint DEFAULT 1 NOT NULL,			-- 1 enabled, 0 disabled (not run by the scheduled job nor by email)
	method varchar(16) NOT NULL,				-- ftp, ftpes, ftps, sftp, http, email, upload
	filetype varchar(8) NOT NULL,				-- csv, xml
	fk_soc integer,								-- supplier
	fk_entrepot integer,						-- warehouse holding the supplier's stock
	schedule_type varchar(16) DEFAULT 'manual' NOT NULL,	-- manual, daily, hours
	schedule_time varchar(5),					-- daily: HH:MM (server time)
	schedule_hours integer,						-- hours: every N hours
	next_run datetime,							-- when the scheduled job runs it next
	params text,								-- JSON: access, file format and import settings (see FeedImportFeed::$params)
	mapping text,								-- JSON: feed field => Dolibarr field
	lock_date datetime,							-- set while a run is in progress (see FeedImportFeed::claim())
	last_run datetime,
	last_status varchar(16),					-- status of the last run (see FeedImportRun::STATUS_*)
	last_file_hash varchar(64),					-- SHA-256 of the last file imported, to skip an unchanged file
	last_count integer,							-- items in the last file imported completely, for the zeroing guard
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	fk_user_modif integer
) ENGINE=innodb;
