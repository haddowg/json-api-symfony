# A public view of the matched JSON:API route

Applications that needed to recognise the bundle's routes in their own listeners read the `_jsonapi`
route default through `ExceptionListener::ROUTE_MARKER`, which ties app code to an internal listener,
and had no supported way to learn the server, type or endpoint. `JsonApiRoute` now owns the marker,
with `matches()` as the yes/no check and `of()` returning the route's server, type, id, relationship,
action and atomic flag plus core's `Target`; the listener constant stays as a deprecated alias. It
reads only routed attributes, so it is meaningful after Symfony's `RouterListener` and loads nothing:
the effective `Server` comes from `ServerProvider::forRequest()`, and the resource from the lifecycle,
after authorization.
