# Fixed membership is checked on the scoped linkage

The handler compares a replacement against the members the parent holds after the related scope has added back the members the caller can't see, rather than comparing the caller's linkage before scoping. A client can only restate the members it can read, so checking before the scope would refuse every replacement of a relation with hidden members, and checking after keeps a hidden member from being confirmed or denied by a `403`.
