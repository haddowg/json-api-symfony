# A scoped WhereMorph admits stored ids as a related read does

A stored polymorphic id is text that may be the target's storage key or its wire id, while
the target's id column may hold a converted value such as a binary UUID, so comparing the
two in SQL matches nothing. The filter admits the requested ids, or the distinct ids the
column holds, through the type's encoder and its id's Doctrine type as a related read loads
them. It keeps a SQL subquery only for an unencoded id whose column is of the morph column's
family, both integers or both text, since PostgreSQL compares no other pair.
