# An action link is read off the resource and needs its action on some server

A resource's `ActionLink` (core ADR 0186) lives in `links()`, which a compiler pass can't read, so the pass now hands `ActionLinkContributor` every resource-scope action with its `asLink` flag, and the contributor reads the declared action links off the server's registered resource when it renders. An action links when it sets `asLink` or the resource declares an action link for its path; security is checked first, then the action link's `when()`. The OpenAPI metadata reads the same declarations through `allActionLinks()`, the way it reads declared links (ADR 0154).

## Consequences

`ServableResourceWarmer` builds each resource's links at warm-up, so core's duplicate and name-clash errors fail the build. Actions are exposed per server, and one resource is often served on several, so a server without the named action leaves the link out of its resources and its document. The warmer, which sees every server, fails the build only when no server serving the type has the action, which still catches a typo.
