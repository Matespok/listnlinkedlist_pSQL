CREATE TABLE IF NOT EXISTS RAM(
  pointer SERIAL PRIMARY KEY,
  value TEXT 
);

CREATE TABLE IF NOT EXISTS Fixed_Array(
  name VARCHAR(30) NOT NULL PRIMARY KEY,
  first_pointer INT NOT NULL,
  capacity INT NOT NULL,
  size INT NOT NULL
);

-- First 4 pointers:
-- Head_Pointer(first day)
-- Tail_Pointer(last day)
-- Current_Pointer(today)
-- All days


INSERT INTO RAM(value) VALUES('Head_Pointer');
INSERT INTO RAM(value) VALUES('Tail_Pointer');
INSERT INTO RAM(value) VALUES('Current_Pointer');
INSERT INTO RAM(value) VALUES('TotalDays');

CREATE TABLES IF NOT EXISTS Linked_List_Headers(
  name VARCHAR(30) NOT NULL PRIMARY KEY,
  Head_Pointer INT NOT NULL
);


-- NODE in ram of fixed_array:
-- | PTR | VALUE |
-- |-------------|
-- |  x  | val[0]|
-- | x+1 | val[2]|
-- And so on..

-- Node in ram of linked list looks like:
-- | PTR | VALUE |
-- |-------------|
-- | x   |headptr|
-- | x+1 |value  |
-- | x+2 |nextptr|


