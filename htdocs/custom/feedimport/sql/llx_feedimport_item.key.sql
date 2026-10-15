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


ALTER TABLE llx_feedimport_item ADD UNIQUE INDEX uk_feedimport_item_sku (fk_feed, sku);
ALTER TABLE llx_feedimport_item ADD INDEX idx_feedimport_item_norm (fk_feed, sku_norm);
ALTER TABLE llx_feedimport_item ADD INDEX idx_feedimport_item_status (fk_feed, status);
ALTER TABLE llx_feedimport_item ADD INDEX idx_feedimport_item_fk_product (fk_product);
