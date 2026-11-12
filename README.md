# Linked list in plpgsql

This has been one of the most stupidiest ideas i might've ever thought of and therefore done.
It's linked list and list over fixed array with whole backend in PL/PGSQL. I had to emulate whole RAM
4kb of RAM is there, maximum is 9999 Bytes. Not more because of my pointering logic

## Topologies used:

1. Docker
2. PostgreSQL
3. PHP

## Running localy:

1. make sure to have docker installed
2. run `docker compose up -d`
3. go to `localhost:3000` in web browser and enjoy

---

## Info

every logic here is in sql/init.sql, which runs for the first time the container starts, initializes whole ram and shi
Tables for this:

### `motherboard`

| Column     | Data Type | Constraints                | Description                                            |
| :--------- | :-------- | :------------------------- | :----------------------------------------------------- |
| `id`       | `INT`     | `PRIMARY KEY`, `DEFAULT 1` | Memory slot identifier.                                |
| `ram_slot` | `BYTEA`   | —                          | Raw 4096-byte block simulating RAM (1-based indexing). |

### `Linked_List_Headers`

| Column         | Data Type | Constraints | Description                            |
| :------------- | :-------- | :---------- | :------------------------------------- |
| `name`         | `TEXT`    | —           | Linked list instance identifier.       |
| `head_pointer` | `INT`     | —           | Starting RAM offset of the first node. |

### `Fixed_Array_Headers`

| Column          | Data Type | Constraints   | Description                                             |
| :-------------- | :-------- | :------------ | :------------------------------------------------------ |
| `name`          | `TEXT`    | `PRIMARY KEY` | Dynamic array instance identifier.                      |
| `first_pointer` | `INT`     | —             | Starting RAM offset of the contiguous pointer block.    |
| `capacity`      | `INT`     | —             | Maximum allocated element count (`capacity * 4` bytes). |
| `size`          | `INT`     | —             | Current number of elements stored.                      |

---

## 2. Raw Memory (`BYTEA`) Data Types

- **Pointer:** Fixed **4 bytes** (zero-padded ASCII string, e.g., `'0015'`). `'0000'` represents `NULL` / end-of-list / empty slot.
- **String:** Variable-length `UTF-8` bytes terminated by `chr(3)` (`0x03` / ETX).
- **Free Space:** Unallocated memory represented by null bytes (`0x00`).
- **Linked List Node:** Contiguous **8-byte** block (`[4B value_ptr][4B next_node_ptr]`).
- **Fixed Array Block:** Contiguous `capacity * 4` byte block of **4-byte** value pointers.

---

AI USAGE: AI has been used for research, frontend(src/index.php as a whole), and to locate mistakes, or corret logic errors on backend. Therefore i wrote 8 hours on this project: https://hackatime.hackclub.com/@matespok/project/linkednlist
