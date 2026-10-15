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

-- One row per Square payment link or Square Terminal checkout created by the module
CREATE TABLE llx_square_transaction(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	type varchar(16) NOT NULL,				-- 'link' (online payment link) or 'terminal' (Square Terminal)
	livemode smallint DEFAULT 0 NOT NULL,	-- 1 production, 0 sandbox
	status varchar(16) NOT NULL,			-- see SquareTransaction::STATUS_*
	square_id varchar(64) NOT NULL,			-- payment link id or terminal checkout id
	square_order_id varchar(64),			-- order of a payment link
	square_payment_id varchar(255),			-- payment(s) that settled it, comma separated
	square_url varchar(255),				-- checkout page of a payment link
	device_id varchar(64),					-- Terminal the checkout was sent to
	source varchar(32),						-- what is paid, as in the payment page: 'invoice', 'order', 'member', 'free'...
	source_ref varchar(128),
	fk_facture integer,						-- invoice paid, if any
	fulltag varchar(255),					-- tag of the payment page (INV=..., PM=square...)
	amount double(24,8) NOT NULL,
	currency varchar(3) NOT NULL,
	fk_paiement integer,					-- payment recorded in Dolibarr
	note varchar(255),						-- last problem met, for the person who reconciles it
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer					-- user who took a terminal payment (empty for online payments)
) ENGINE=innodb;
