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


-- Linked list

CREATE TABLE IF NOT EXISTS Linked_List_Headers(
  name TEXT,
  head_pointer INT 
);

CREATE OR REPLACE FUNCTION InitializeLinkedList(list_name TEXT, firstValue TEXT)
RETURNS VOID AS $$
DECLARE
  nodePtr INT;
  textPtr INT;
BEGIN
  SELECT FindFreeBlock(octet_length(firstValue) + 1) INTO textPtr;
  PERFORM WriteText(textPtr, (firstValue || chr(3))); --char 3 - ETX - end of text
  SELECT FindFreeBlock(8) INTO nodePtr;
  PERFORM WriteText(nodePtr, lpad(textPtr::text, 4, '0'));
  PERFORM WriteText(nodePtr + 4, '0000'); -- end of linked list
  INSERT INTO Linked_List_Headers VALUES (list_name, nodePtr);
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION LLAddNode(value TEXT, list_name TEXT)
RETURNS VOID AS $$
DECLARE
  curr_node INT;
  next_node INT;
  value_ptr INT;
BEGIN
  SELECT head_pointer INTO curr_node FROM Linked_List_Headers WHERE name = list_name;
  IF curr_node IS NULL THEN
    RAISE EXCEPTION 'Linked list % does not exist!', list_name;
  END IF;
  
  LOOP
    next_node := ReadText(curr_node+4, 4)::INT;
    IF next_node = 0 THEN
      EXIT;
    END IF;
    curr_node := next_node;
  END LOOP;

  --write value
  SELECT FindFreeBlock(octet_length(value) + 1) INTO value_ptr;
  PERFORM WriteText(value_ptr, (value || chr(3)));
  --allocate node
  SELECT FindFreeBlock(8) INTO next_node;
  PERFORM WriteText(next_node, lpad(value_ptr::text, 4, '0'));
  PERFORM WriteText(next_node + 4, '0000');
  --write next node to next node pointer of the previous node
  PERFORM WriteText(curr_node+4, lpad(next_node::text, 4, '0'));
END;
$$ LANGUAGE plpgsql;


