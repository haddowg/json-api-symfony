# The ReDoc viewer defaults to 3.x with telemetry off

The ReDoc renderer now loads ReDoc 3.0.1, the current major, pinned exactly. ReDoc 3's standalone bundle is an ES module that sends usage telemetry unless the `<redoc>` tag carries `disable-telemetry="true"`, so the page always renders that attribute unless the app sets `json_api.openapi.ui.redoc_telemetry: true`, because a docs page shouldn't report on an API's readers unless the app chose that. `json_api.openapi.ui.redoc_version` keeps 2.x selectable for an app that needs it, and its major picks the loader: the module for 3.x, the classic global script for 2.x.

## Considered options

- Stay on 2.x by default. It needs no telemetry handling, but it keeps every new app on the older major.
- Accept a version range or a bare major. A range lets the CDN serve a release nobody tested, and a mirrored `ui.cdn` directory has to match one exact release anyway.
