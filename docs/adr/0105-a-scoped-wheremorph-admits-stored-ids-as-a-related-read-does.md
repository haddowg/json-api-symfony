# A scoped WhereMorph admits stored ids as a related read does

A stored polymorphic id is text that may be the target's storage key or its wire id, while
the target's id column may hold a converted value such as a binary UUID, so comparing the
two in SQL matches nothing. The filter admits the requested ids, or the distinct ids the
column holds, through the type's encoder and its id's Doctrine type as a related read loads
them, and binds them in chunks under every database's parameter limit. It keeps one SQL
subquery only where both sides compare on every platform: an unencoded id of the morph
column's family, or a uuid read as its canonical text by a platform expression. A ulid's
base32 text has no such expression, so it is admitted instead.
