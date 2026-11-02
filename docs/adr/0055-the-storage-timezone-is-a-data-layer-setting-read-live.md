# The storage timezone is a data-layer setting read live

`storage_timezone` names the zone the Doctrine data layer stores naive `datetime` columns in, and it has no per-server override because the columns belong to the database, not to an API server. Left null it means PHP's default timezone, read with `date_default_timezone_get()` at each bind and write rather than captured when the container compiles, because multi-tenant applications switch the default per request.
