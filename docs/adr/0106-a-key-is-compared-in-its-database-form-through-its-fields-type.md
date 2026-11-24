# A key is compared in its database form through its field's type

A foreign key, a link row's owner and id, and a filter value on a key column are each
converted through the Doctrine type of the field they're compared with, on both sides,
before they're bound or matched. Comparing their string forms failed whenever the PHP
value and the stored value differ, as a `Uuid` hydrated from 16 bytes does, so one shared
conversion on `DoctrineIdentifiers` replaces the per-path casts. A value the type converts
neither way matches nothing rather than reaching the database, where PostgreSQL would
refuse a malformed `guid` with an error.
