# Cache and deprecation headers are declarative, applied on `kernel.response`, and never override the application's

A resource declares `cacheHeaders`, `deprecation`, `sunset` and `sunsetLink`, layered key by key over its server's `defaults` block and the global one, and one route-scoped `kernel.response` listener emits them: cache directives only on a successful `GET`, deprecation and sunset on every response. It runs on `kernel.response` so the final status, including an error's, is known, and it never overwrites a header the application already set, so an imperative after-hook still wins.
