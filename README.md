# PostgreSQL dump to SQL dump

## Usage

```sh
php postgres-to-mysql.php postgres_dump.sql > mysql_dump.sql
```

## Limitations

SQL dump must be encoded in UTF-8.

## Storing to memory

Currently default storage engine is set to be MEMORY. This allows very fast data load from INSERT INTO queries. But it also requires massive amount of RAM (memory engine is space ineffictient, it will be many times GREATER than SQL dump itself!), some specific changes to the server configuration and it might not support all data types (like BLOB or TEXT for MariaDB).

In regards to server configuration you have to assign high values to some settings to actually have the memory space. Sample configuration:

```
innodb_buffer_pool_size=24G
max_heap_table_size=8G
tmp_table_size=8G
```

In the example above, maximum size of a single memory table is 8G. Tmp_table_size applies to internal memory tables only, but I included it "just in case". After the import you should lower those values anyway.

After quick and successful loading data to memory you can alter tables to InnoDB or any other engine. Transition will be quick and resulting table will be around 8 times smaller.

```sql
ALTER TABLE my_table ENGINE=InnoDB;
```