# Each server builds its own instance of a shared resource

Core's registry injects itself into every resource it resolves, and the resource reads
server-level settings back through that resolver: the output timezone and the
serializers of related types it renders into `included`. A resource class assigned to
several servers was one shared service, so whichever server resolved it last won, and a
`tokyo` response could come out in another server's zone. `ResourceLocatorPass` now
gives each server its own `ResourceLocator`, and for every class more than one server
resolves (resources, serializer/hydrator overrides, standalone pairs) it registers a
private copy of the service definition per server. A class on one server keeps its
original service.

## Considered options

- **Make shared resources non-shared services.** Every lookup through the global
  locator (id encoders, route and OpenAPI metadata) would then build a new instance,
  and so would any app code that injects the resource.
- **Clone the instance inside the registry.** That is a core change, and a clone made
  after construction would share any objects the constructor built.
- **Fail the build when the servers' zones differ.** This was the first fix. It hid the
  zone symptom but left related serializers resolving through the wrong registry.

## Consequences

- The copies keep the original's arguments, method calls and autowiring, so
  constructor injection works as before. They drop tags and decoration, so no other
  pass collects a copy, and a copy never decorates what the original decorates.
- The relationship-count holder and the action link contributor are single services
  threaded into every server, and the contributor reads the server off the request, so
  neither leaked. They still come from each server's own resolver now.
- Code that fetches a shared resource from the container gets the original, which no
  server serves.
