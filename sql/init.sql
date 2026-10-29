CREATE TABLE IF NOT EXISTS motherboard(
  id PRIMARY KEY DEFAULT 1,
  ram_slot BYTEA
);

--insert 4kb of rma xdd
INSERT INTO motherboard(id, ram_slot)
VALUES(1, decode(repeat('00', 4096), 'hex'));

-- First 4 pointers:
-- Head_Pointer(first day)
-- Tail_Pointer(last day)
-- Current_Pointer(today)
-- All days

