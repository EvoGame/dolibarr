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

-- No foreign key on fk_facture: Facture::delete() would fail on it
ALTER TABLE llx_square_transaction ADD UNIQUE INDEX uk_square_transaction_square_id (square_id);
ALTER TABLE llx_square_transaction ADD INDEX idx_square_transaction_status (entity, status);
ALTER TABLE llx_square_transaction ADD INDEX idx_square_transaction_fk_facture (fk_facture);
ALTER TABLE llx_square_transaction ADD INDEX idx_square_transaction_square_order_id (square_order_id);
