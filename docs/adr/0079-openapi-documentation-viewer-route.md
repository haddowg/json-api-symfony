# A single config-driven documentation-viewer route renders Swagger UI or ReDoc as plain CDN-linked HTML

The OpenAPI document needs a human-facing viewer, but the bundle is deliberately
dependency-light (every controller returns a raw `Response`; Twig is not a dependency).
So the viewer is a **single config-driven route** at `json_api.openapi.ui.path` (default
`/docs`) whose controller returns a **plain HTML string** — no Twig — that embeds
**either** Swagger UI **or** ReDoc (per `json_api.openapi.ui.renderer`, one not both,
design D6) loaded from a **pinned public CDN**. `json_api.openapi.ui.cdn` overrides the
asset origin for a self-hosted/air-gapped mirror (design §11). The route rides the same
expose gate as the document (`kernel.debug` or `expose_in_prod`) and is additionally
gated on `ui.enabled`, so the viewer is independently switchable from the document.

We chose plain HTML over pulling in Twig (one page does not justify a hard or soft
template-engine dependency) and CDN-default over vendoring assets (zero build step,
no asset versioning to maintain — the CSP allowance and the `cdn` self-host override are
documented). **App-overridability** without a template engine: an app registers its own
controller on the configured path (it imports the docs route loader, so its own
`GET {ui.path}` route wins by registration order), or retargets the asset origin via
`ui.cdn`. The spec URL the page fetches is generated from the document route via the
router, so the viewer works behind any routing prefix (`->prefix('/api')`) and the
front-controller script base alike — the viewer and the document it points at always share
the same mount; the configured json path is kept only as a last-resort fallback for the
unexpected case where the document route is absent. Per-server / combined doc selection
stays the document controller's concern.
