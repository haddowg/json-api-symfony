# Servers are declared in config, a resource joins one or more, and what it declares is keyed by server and type

The top-level `base_uri`/`version` define an implicit `default` server and `json_api.servers` declares more; a resource or standalone capability joins one or several with `server:`, one immutable core `Server` is built per server, and routes mount through a per-server route import so prefixes and hosts stay in the application's routing config (a named server's routes are `jsonapi.{server}.{type}.{action}`). Because one class or type can sit on several servers, everything a class declares (security, response headers, descriptions, tags, id encoding, standalone capabilities) is recorded under server and type and read for the server the operation runs on, and a class served by several servers gets a private service instance per server, since core injects the server's own registry into each resource it resolves.

## Considered Options

- Make shared resources non-shared services. Every lookup through the global locator, and any app code injecting the resource, would build a new instance.
- Clone the instance inside core's registry. That is a core change, and a clone made after construction shares any objects the constructor built.
- Fail the build when two servers' settings differ. It hid one symptom and left related serializers resolving through the wrong registry.

## Consequences

When nothing names a server, a lookup uses the server the operation's context was built for, else `default`. A server whose class declares nothing gets the defaults, never another server's value. Code that fetches a shared resource from the container gets the original, which no server serves.
