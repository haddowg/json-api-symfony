# The Doctrine data layer decodes encoded resource ids; the SPI stays wire-id

A resource may attach an id encoder to its `Id` field (core's
`Id::encodeUsing(IdEncoderInterface)`) so the JSON:API `id` a client sees is the
**wire** form of a distinct **storage** key — the domain entity always holds the
storage key (a binary UUID, an integer PK, …), and the wire id is `encode(storageKey)`.
The encode/decode boundary is split by *where* the id flows: core owns the
entity's-own-id transform (encode on serialize, decode a client-generated id on
create — a `null` decode is a `422`), and the **reference Doctrine implementation**
owns the id-as-lookup-key transforms the storage-agnostic `DataProvider`/`DataPersister`
SPI passes as wire strings — the route `{id}` (decoded before the find/query; a
`null` decode short-circuits to a `404` without querying) and linkage ids (decoded,
keyed by the *related* type, before `getReference` — a `null` decode is a bad target
and raises a `404` rather than feeding the raw wire string to `getReference`, which
would build a proxy that errors on initialization). Core only `decode()`s a
*client-supplied* create id; a server-generated id is set as-is, never decoded. The
SPI keeps its **wire-id signatures unchanged** so only the Doctrine impl decodes; the
in-memory provider has
no encoder and is untouched (wire == storage, so it uses wire ids as keys directly).
A shared `IdEncoderResolver` resolves a type → its resource's `Id` field →
encoder / route pattern, and the route loader stamps the `{id}` requirement from
`Id::matchAs()` / the format shortcuts so a malformed id `404`s at routing before any
handler runs. A type with no encoder behaves exactly as before.

We keep encoders **user-supplied** (no encoder dependency is added to the bundle):
storage-key obfuscation (hashids, binary-UUID `symfony/uid`, a custom codec) is an
application concern, and the bundle only supplies the resolver + the decode points.
