# Action links share the server's link base

An `asLink` action link was generated through the Symfony router as an absolute URL, so it followed the request host and ignored a configured `base_uri`, while the resource's own `self` and relationship links used that base. `ActionLinkContributor` now returns the action route's server-relative path, `/{uriType}/{id}/-actions/{path}`, and core prefixes it with the same base it gives every other link: the configured `base_uri`, or the request origin plus the recorded mount when that is empty (ADR 0121).

## Consequences

- A configured `base_uri` must carry the import prefix for action links too, as ADR 0121 already requires for the rest.
- The id goes into the href as the serializer returns it, the same way core builds the `self` link, rather than through the router's encoding.
