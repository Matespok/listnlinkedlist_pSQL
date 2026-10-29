# Useful commands(documentation of how things work here)

# Getting a value from ram

## Reading 1 byte

`SELECT get_byte(ram_slot, <address>) FROM motherboard WHERE id=<motherboard>;`

- reads the value on the `<address>` on the ram of `<motherboard>`
  - e. g. `SELECT get_byte(ram_slot, 42) FROM motherboard WHERE id=1;`
    - reads the actual value on address 42.

##### get_byte() indexes from 0 in postgres!

## Reading TEXT

```sql
SELECT convert_from(
  substring(ram_slot from <pointer> for <length>),
  'UTF8'
) FROM motherboard WHERE id = <motherboard>;
```

- Reads text from ram. We need to know the beggining pointer of the text and the byte length. We also decode it from UTF8 to text.
  - e. g.
  ```sql
  SELECT convert_from(
    substring(ram_slot from 101 for 15),
    'UTF8'
    ) FROM motherboard WHERE id = <motherboard>;
  ```
  Returns text from pointer 100, that is 15 bytes long.

##### substring() indexes from 1!

# Writing to RAM

## Write 1 byte to RAM:

```sql
UPDATE motherboard
  SET ram_slot = set_byte(ram_slot, <pointer>, <value>)
WHERE id = <motherboard>;
```

- writes value to pointer on motherboard
  - e. g.
  ```sql
  UPDATE motherboard
    SET ram_slot = set_byte(ram_slot, 42, 255)
  WHERE id = 1;
  ```
  writes value 255 to pointer 42 on motherboard 1.

## Write a string to RAM

- to write it, we need to seperate the ram into 3 sections:
  - memory before || our text || memory after

```sql
UPDATE motherboard
  SET ram_slot =
  (ram_slot from 1 for <beforeOurPointer>) ||
  convert_to('<text>', 'UTF8') ||
  (ram_slot from <beforeOurPointer + 1 + length(convert_to('<text>', 'UTF8'))>)
WHERE id = <motherboard>;
```

- seperates: 0 to beforeOurPointer; our value; After our value.
- concats it together
