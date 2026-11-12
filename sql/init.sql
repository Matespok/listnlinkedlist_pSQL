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

CREATE OR REPLACE FUNCTION ReadString(ptr INT)
RETURNS TEXT AS $$
DECLARE
  delimiter_pos INT;
  raw_bytes BYTEA;
BEGIN
  SELECT position(E'\\x03'::bytea IN substring(ram_slot from ptr)) INTO delimiter_pos FROM motherboard;
  IF delimiter_pos = 0 THEN
    RETURN NULL;
  END IF;
  
  SELECT substring(ram_slot from ptr for (delimiter_pos - 1)) INTO raw_bytes FROM motherboard;
  
  RETURN convert_from(raw_bytes, 'UTF8');
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION LLGetAll(list_name TEXT)
RETURNS TABLE(val TEXT) AS $$
DECLARE
  curr_node INT;
  val_ptr INT;
  next_node INT;
BEGIN
  SELECT head_pointer INTO curr_node
  FROM Linked_List_Headers
  WHERE name = list_name;

  IF curr_node IS NULL THEN
    RETURN;
  END IF;

  LOOP
    val_ptr := ReadText(curr_node, 4)::INT;
    val := ReadString(val_ptr);
    RETURN NEXT;
    next_node := ReadText(curr_node + 4, 4)::INT;
    IF next_node = 0 THEN
      EXIT;
    END IF;
    
    curr_node := next_node;
  END LOOP;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION LLDeleteNode(list_name TEXT, target_val TEXT)
RETURNS VOID AS $$
DECLARE
  curr_node INT;
  prev_node INT := NULL;
  next_node INT;
  val_ptr INT;
  curr_text TEXT;
BEGIN
  SELECT head_pointer INTO curr_node
  FROM Linked_List_Headers
  WHERE name = list_name;
  
  IF curr_node IS NULL THEN
    RETURN;
  END IF;
  
  LOOP
    val_ptr := ReadText(curr_node, 4)::INT;
    curr_text := ReadString(val_ptr);
    next_node := ReadText(curr_node + 4, 4)::INT;
    IF curr_text = target_val THEN
      IF prev_node IS NULL THEN
        IF next_node = 0 THEN
          DELETE FROM Linked_List_Headers WHERE name = list_name;
        ELSE
          UPDATE Linked_List_Headers SET head_pointer = next_node WHERE name = list_name;
        END IF;
      ELSE
        PERFORM WriteText(prev_node + 4, lpad(next_node::text, 4, '0'));
      END IF;
      PERFORM Free(val_ptr, octet_length(curr_text) + 1);
      PERFORM Free(curr_node, 8);
      RETURN; 
    END IF;
    IF next_node = 0 THEN
      EXIT;
    END IF;
    prev_node := curr_node;
    curr_node := next_node;
  END LOOP;
END;
$$ LANGUAGE plpgsql;


-- List over fixed array
CREATE TABLE IF NOT EXISTS Fixed_Array_Headers (
  name TEXT PRIMARY KEY,
  first_pointer INT,
  capacity INT,
  size INT);


CREATE OR REPLACE FUNCTION InitializeArray(array_name TEXT, first_val TEXT)
RETURNS VOID AS $$
DECLARE
  val_ptr INT;
  arr_ptr INT;
  init_cap INT := 2; 
BEGIN
  SELECT FindFreeBlock(octet_length(first_val) + 1) INTO val_ptr;
  IF val_ptr = -1 THEN
    RAISE EXCEPTION 'Out of Memory při alokaci textu';
  END IF;
  PERFORM WriteText(val_ptr, first_val || chr(3));

  SELECT FindFreeBlock(init_cap * 4) INTO arr_ptr;
  IF arr_ptr = -1 THEN
    RAISE EXCEPTION 'Out of Memory při alokaci pole';
  END IF;

  PERFORM WriteText(arr_ptr, lpad(val_ptr::text, 4, '0')); --leftpad for ptrs under 3 chars

  INSERT INTO Fixed_Array_Headers (name, first_pointer, capacity, size)
  VALUES (array_name, arr_ptr, init_cap, 1);
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION ResizeArray(array_name TEXT)
RETURNS VOID AS $$
DECLARE
  curr_ptr INT;
  curr_cap INT;
  curr_size INT;
  new_cap INT;
  new_ptr INT;
BEGIN
  SELECT first_pointer, capacity, size
  INTO curr_ptr, curr_cap, curr_size
  FROM Fixed_Array_Headers WHERE name = array_name;

  IF curr_ptr IS NULL THEN
    RAISE EXCEPTION 'Pole % neexistuje!', array_name;
  END IF;

  new_cap := curr_cap * 2;
  SELECT FindFreeBlock(new_cap * 4) INTO new_ptr;
  IF new_ptr = -1 THEN
    RAISE EXCEPTION 'Out of Memory při zvětšování pole %', array_name;
  END IF;
  PERFORM CopyBytes(curr_ptr, new_ptr, curr_size * 4);
  PERFORM Free(curr_ptr, curr_cap * 4);
  UPDATE Fixed_Array_Headers
  SET first_pointer = new_ptr, capacity = new_cap
  WHERE name = array_name;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION AddToArray(value TEXT, array_name TEXT)
RETURNS VOID AS $$
DECLARE
  curr_ptr INT;
  curr_cap INT;
  curr_size INT;
  val_ptr INT;
BEGIN
  SELECT first_pointer, capacity, size
  INTO curr_ptr, curr_cap, curr_size
  FROM Fixed_Array_Headers WHERE name = array_name;
  IF curr_ptr IS NULL THEN
    RAISE EXCEPTION 'Pole % neexistuje!', array_name;
  END IF;
  PERFORM WriteText(val_ptr, value || chr(3));
  
  PERFORM WriteText(curr_ptr + (curr_size * 4), lpad(val_ptr::text, 4, '0'));
  
  UPDATE Fixed_Array_Headers
  SET size = curr_size + 1
  WHERE name = array_name;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION GetValueAtIndex(target_index INT, array_name TEXT)
RETURNS TEXT AS $$
DECLARE
  curr_ptr INT;
  curr_size INT;
  val_ptr INT;
BEGIN
  SELECT first_pointer, size
  INTO curr_ptr, curr_size
  FROM Fixed_Array_Headers WHERE name = array_name;
  
  IF curr_ptr IS NULL OR target_index < 0 OR target_index >= curr_size THEN
    RETURN NULL;
  END IF;
  
  val_ptr := ReadText(curr_ptr + (target_index * 4), 4)::INT;
  RETURN ReadString(val_ptr);
END;
$$ LANGUAGE plpgsql;

/*
We will create the fixed_array_headers with First_Pointer, Capacity and Size



InitializeArray(array_name TEXT, first_val TEXT)
First we will find a free block of octet_length(value)*2;
Write the first value into memory on that pointer
save beggining pointer into fixed array
+2 to capacity or atleast count of bytes into capacity;
size will be the taken space of the capacity.
Everything else is 0, so alocated.

ResizeArray(array_name TEXT)
array := select ReadMemory(first_pointer, capacity)
pointer_for_new_array := FindFreeBlock(capacity*2)
new_array := array || repeat('00', capacity);
WriteText(pointer_for_new_array, new_array);
Free(first_pointer, capacity);
UPDATE Fixed_array(First_Pointer, Capacity) WHERE name = name
VALUES(pointer_for_new_array, capacity*2);

AddToArray(value TEXT, array_name TEXT)
if(Capacity > size + value)
ResizeArray(array_name);
end if;
pointer_to_insert := first_pointer+size+1 
WriteText(pointer_to_insert, value)

RemoveValueFromArray(value TEXT, array_name TEXT)
array_ram_dump TEXT;(read whole array by method ReadText)
find first appearance by position(value in array_ram_dump)
delete the text and concat it together without the appearance of the value we wanted to delete(from ch(3) to ch(3))
write it again to the memory and update the size.

GetValueAtIndex(index INT, array_name TEXT){
find the appearance of index times ch(3) and then read by and give it out(returns TEXT)

GetAllValues(array_name TEXT)
reads by ReadString and then continues by the next pointer after counting on the ch(3)
writes it into a table.
Returns a table.
And by apearance of more than 3 0s we will not count the string and make it empty. -> end and return
*/
