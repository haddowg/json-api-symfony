# JSON:API routes render every error as a JSON:API document

A JSON:API endpoint must return JSON:API error documents for *every* failure —
`401`/`403` from the firewall, `404` from routing, uncaught `500`s — not only the
handler's own exceptions, yet the bundle must not disturb a mixed app. So its
`kernel.exception` listener is route-scoped (it acts only when the matched route
carries `_jsonapi_server`) and there owns every error: a core
`JsonApiExceptionInterface` natively, a Symfony `HttpExceptionInterface` by its
status, anything else a `500`; debug meta is gated on `kernel.debug` and unexpected
throwables are logged via Symfony's logger.

The whole endpoint is thus spec-compliant, including failures that never reach the
handler, while non-JSON:API routes are untouched — the trade-off being that on
JSON:API routes the bundle deliberately overrides framework error handling. The
throwable→`Error` mapping delegates to a public core seam rather than being
reimplemented, so bundle and core stay identical.
