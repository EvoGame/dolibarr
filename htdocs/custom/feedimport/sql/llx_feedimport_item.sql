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

-- An item (SKU) of a feed, and the product it is linked to
CREATE TABLE llx_feedimport_item(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_feed integer NOT NULL,
	sku varchar(128) NOT NULL,					-- as in the feed
	sku_norm varchar(128) NOT NULL,				-- feedimportNormaliseKey(sku)
	status smallint DEFAULT 0 NOT NULL,			-- 1 linked, 0 to review, 2 ignored, 3 not created (the feed does not create products)
	fk_product integer,							-- product linked
	fk_product_candidate integer,				-- to review: product it probably is
	reason varchar(255),						-- to review: why it was not created; linked: how it was matched
	label varchar(255),							-- label in the feed, for the review queue
	data text,									-- JSON: last values of the mapped fields
	hash varchar(64),							-- hash of data, to skip unchanged rows
	date_first_seen datetime NOT NULL,
	date_last_seen datetime NOT NULL,
	fk_run_last_seen integer,					-- last run whose file listed it
	zeroed smallint DEFAULT 0 NOT NULL,			-- 1 once its stock was set to 0 because it left the feed
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
