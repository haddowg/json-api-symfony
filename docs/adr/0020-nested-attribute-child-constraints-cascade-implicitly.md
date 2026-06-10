# A structured attribute's child constraints validate by implicit recursion

A `Map` attribute is a nested JSON object whose children each carry their own
constraints. Rather than require an explicit `Valid`-style marker to opt the
children into validation, the bridge cascades **implicitly**: when a top-level
attribute field is a core `Map`, `ResourceValidator` builds a nested Symfony
`Collection` from the Map's children that **mirrors** the top-level attribute
`Collection` — the same `allowExtraFields: true`, and per-child
`Required`/`Optional`/`NotBlank`/`NotNull` resolution by create/update `Context`
(via the same `fieldConstraint()` used for top-level fields). A child violation
therefore behaves exactly like a top-level attribute violation, and Symfony's
nested property path (`[address][postcode]`) flows through the existing
`JsonPointerBuilder` to `/data/attributes/address/postcode` with no pointer-code
change.

We chose implicit over an explicit marker for **consistency**: a structured
attribute's children should validate by the same rules as the resource's flat
attributes without a second opt-in, and core's `Map` already exposes its
`children()` as public API, so no core change was needed. The cascade is
deliberately **one level deep** — a child that is itself a `Map`, and an
`ArrayList`-of-objects, are out of scope and are *not* descended into (a `descend`
flag stops the recursion one level in, which also bounds it). Those deeper shapes
are follow-ups.

The decision is reversible: were per-attribute control wanted later, the recursion
could be gated behind an explicit `Valid` marker on the `Map` without disturbing
the nested-`Collection` machinery or the pointer mapping.
