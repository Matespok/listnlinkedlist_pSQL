CREATE TABLE IF NOT EXISTS motherboard(
  id INT PRIMARY KEY DEFAULT 1,
  ram_slot BYTEA
);

--insert 4kb of rma xdd
INSERT INTO motherboard(id, ram_slot)
VALUES(1, decode(repeat('00', 4096), 'hex'));



CREATE OR REPLACE FUNCTION ReadText(begginingPtr INT, length INT)
RETURNS VARCHAR AS $$
SELECT convert_from(
  substring(ram_slot from begginingPtr for length),
  'UTF8'
) FROM motherboard WHERE id = 1;
$$ LANGUAGE sql;



CREATE OR REPLACE FUNCTION WriteText(ptr INT, newValue TEXT)
RETURNS VOID AS $$
BEGIN
UPDATE motherboard
  SET ram_slot =
  substring(ram_slot from 1 for (ptr-1)) ||
  convert_to(newValue, 'UTF8') ||
  substring(ram_slot from (ptr + length(convert_to(newValue, 'UTF8'))))
WHERE id = 1;
END;
$$ language plpgsql;


CREATE OR REPLACE FUNCTION Free(ptr INT, length INT)
RETURNS VOID AS $$
BEGIN
UPDATE motherboard
  SET ram_slot =
  substring(ram_slot from 1 for (ptr-1)) ||
  decode(repeat('00', length),'hex')||
  substring(ram_slot from (ptr + length))
WHERE id = 1;
END;
$$ LANGUAGE plpgsql;


CREATE OR REPLACE FUNCTION FindFreeBlock(requiredSize INT)
RETURNS INT AS $$
DECLARE
ram_hex TEXT;
FBBegginPtr INT;
target_pattern TEXT;
hex_pos INT;
byte_pos INT;
BEGIN
  SELECT encode(ram_slot, 'hex') INTO ram_hex FROM motherboard WHERE id=1;
  target_pattern := repeat('00', requiredSize);
  hex_pos := position (target_pattern IN ram_hex);
  IF hex_pos = 0 THEN
    RETURN -1; -- full memory
  END IF;
  byte_pos := (hex_pos - 1) / 2 + 1; -- hex-1: the first beggining of the byte, cuz hex = 2 bytes
  RETURN byte_pos;
END;
$$ LANGUAGE plpgsql;



