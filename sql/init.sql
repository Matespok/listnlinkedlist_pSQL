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
target_pattern BYTEA;
hex_pos INT;
byte_pos INT;
BEGIN
  target_pattern := decode(repeat('00', requiredSize), 'hex');
  SELECT position(target_pattern IN ram_slot) INTO byte_pos FROM motherboard WHERE id=1;
  IF byte_pos = 0 THEN
    RETURN -1; -- full memory
  END IF;
  RETURN byte_pos;
END;
$$ LANGUAGE plpgsql;



