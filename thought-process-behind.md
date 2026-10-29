# What i actually want (Project goal & rules)

## Project goal

- Have a functional linked list and list over fixed array
- Idealy have some type of front end. Probably web app

# Tech stack

- Docker; i want it to be able to run anywhere
- Database:count `Postgres:16-alpine` -> Light postgres, that provides pSQL
- Web backend `C#` or `PHP`. I gotta think if i want API and some frontend or simple PHP fullstack is enough
  count

# How am I going to implement it

- ## Firstly, let's divide it into 2 sections:
  ## Memory & data topologies
  - I must simulate atleast a little of logic of heap of ram
  ### Fixed array methods
  - `malloc(size)`
    - allocates size in ram of fixed array
  - `free(pointer)`
    - frees the ram after resize of an array
  - `get(pointer)`
    - get the value from array
  - `set(pointer)`.
    - set a value in an array
  ### List over array methods
  - `init(size)`
    - initialize an array of size
  - `resize(arrayPtr)`
    - doubles the size, copies the elements to it and frees the ram
  - `add(element, arrayPtr)`
    - add an element to an array, if size(the amount of elements) == capacity(maximum capacity of elements) -> resize.
  - `remove(index)`
    - removes an element at its index and ships all elements to the left, so it fills the gap
  ### Linked list
  - `insertAfter(Pointer)`
    - inserts an element after previous, must rewrite the linking of previous element and the element after.
  - `remove(pointer)`
    - removes an element at pointer provided

# TABLES

| motherboard |
| ----------- |
| BYTEA ram   |

| Fixed_Array   |
| ------------- |
| First_Pointer |
| Capacity      |
| Size          |

| Linked_List_Headers |
| ------------------- |
| Name                |
| Head_Pointer        |

# how it works

The only essential table is RAM, where we store everything.
Array has first pointer and is stored in ram, the next pointer of it is the next element. Thats why we need size & firt_pointer
Capacity>size, else resize.
linked list has head, which points to next element, the next element stores prev ptr, value, next ptr.

# RAM LOGIC

Must make malloc, which hands registers of (size)
Free methods also.
