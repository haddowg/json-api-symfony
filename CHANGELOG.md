# Changelog

## [1.0.1](https://github.com/haddowg/json-api-symfony/compare/v1.0.0...v1.0.1) (2026-07-13)


### Miscellaneous Chores

* add openapi and atomic-operations to composer keywords ([#137](https://github.com/haddowg/json-api-symfony/issues/137)) ([329d3aa](https://github.com/haddowg/json-api-symfony/commit/329d3aa6418e4efb22b741925a34a74aa5ce5dce))

## 1.0.0 (2026-07-13)


### ⚠ BREAKING CHANGES

* **actions:** declare a custom action's meta-only output ([#85](https://github.com/haddowg/json-api-symfony/issues/85))
* read belongsToMany pivot writes from meta.pivot (symmetric with reads) ([#80](https://github.com/haddowg/json-api-symfony/issues/80))
* render belongsToMany pivot meta on a primary-resource document linkage ([#79](https://github.com/haddowg/json-api-symfony/issues/79))
* gate the collection read with a securityList key ([#75](https://github.com/haddowg/json-api-symfony/issues/75))
* support filter, sort and pagination on the to-many relationship endpoint ([#71](https://github.com/haddowg/json-api-symfony/issues/71))
* bridge resource/operation/relation description overrides into OpenAPI ([#68](https://github.com/haddowg/json-api-symfony/issues/68))
* fail the build on polymorphic, Doctrine-column, and pivot misconfigurations ([#63](https://github.com/haddowg/json-api-symfony/issues/63))
* fail the build when a routed type is unservable, and project every sortable ([#62](https://github.com/haddowg/json-api-symfony/issues/62))
* adopt the mandatory related-type relationship factory ([#61](https://github.com/haddowg/json-api-symfony/issues/61))
* emit request-host-absolute links by default ([#57](https://github.com/haddowg/json-api-symfony/issues/57))
* track the core v1 surface changes and tidy the OpenAPI metadata layer ([#52](https://github.com/haddowg/json-api-symfony/issues/52))
* flattened on() attributes and correct relationship windowing ([#50](https://github.com/haddowg/json-api-symfony/issues/50))
* reject unknown sparse-fieldset members under the strict gate ([#49](https://github.com/haddowg/json-api-symfony/issues/49))
* execute the request-aware visibility, writability and relationship-authz predicates ([#48](https://github.com/haddowg/json-api-symfony/issues/48))
* a resource's read-security expression now also gates its relationship and related read endpoints (GET /{type}/{id}/{rel} and /{type}/{id}/relationships/{rel}).
* count a windowed include only when the pagination counts ([#41](https://github.com/haddowg/json-api-symfony/issues/41))
* adopt opt-in counting and pagination-as-single-source-of-truth ([#40](https://github.com/haddowg/json-api-symfony/issues/40))
* adopt the lazy-by-default relationship linkage data ([#39](https://github.com/haddowg/json-api-symfony/issues/39))
* rename relationship linkage methods to data ([#36](https://github.com/haddowg/json-api-symfony/issues/36))
* gate ?withCount behind the Relationship Counts profile ([#35](https://github.com/haddowg/json-api-symfony/issues/35))
* DataProviderInterface gains relatedToOneMatches() and relatedToOneMatchesBatch() (custom providers must implement both); DoctrineExtensionInterface::apply() now receives an ExtensionContext instead of loose arguments; and the zero-config pivot-equality filter (filter[position]) is removed in favour of a pivot.-prefixed filter declaration.
* typed constraint-translator extension, drop the Custom bridge ([#13](https://github.com/haddowg/json-api-symfony/issues/13))

### Features

* a consolidated query engine, cursor pagination, pivot-filter operators, and constrained existence ([8339087](https://github.com/haddowg/json-api-symfony/commit/83390872af5967e13481d66f0dec9330681a6916))
* accept a security boolean to document an operation as secured or public ([#74](https://github.com/haddowg/json-api-symfony/issues/74)) ([8759349](https://github.com/haddowg/json-api-symfony/commit/87593499315898c5a3c0517584a9b26cae7a67f1))
* **actions:** declare a custom action's meta-only output ([#85](https://github.com/haddowg/json-api-symfony/issues/85)) ([73eea13](https://github.com/haddowg/json-api-symfony/commit/73eea1314bc4fd77333f1ac360f957af078fedca))
* add a readOnly resource shorthand and fix doc-accuracy gaps ([#65](https://github.com/haddowg/json-api-symfony/issues/65)) ([a19c371](https://github.com/haddowg/json-api-symfony/commit/a19c3718f992a4ae587aa438821fef06c9a45826))
* add an extensible exception-to-error mapping seam ([#38](https://github.com/haddowg/json-api-symfony/issues/38)) ([146dec7](https://github.com/haddowg/json-api-symfony/commit/146dec73c9e1b011cef0e3ea041ff990f7f9c4d6))
* add custom (non-CRUD) actions ([#42](https://github.com/haddowg/json-api-symfony/issues/42)) ([8ef08f9](https://github.com/haddowg/json-api-symfony/commit/8ef08f96e1746d57baa58331e56d52fa5665ba99))
* add data-layer SPI base classes and advertise the id pattern in OpenAPI ([#64](https://github.com/haddowg/json-api-symfony/issues/64)) ([06daa93](https://github.com/haddowg/json-api-symfony/commit/06daa933ff3568d2d6c8dc6ef3c3d00bb3174e6d))
* add the Atomic Operations extension ([#55](https://github.com/haddowg/json-api-symfony/issues/55)) ([6a59e80](https://github.com/haddowg/json-api-symfony/commit/6a59e801e1efbdb68c75ac4329b1cfbe5e79dcc4))
* adopt opt-in counting and pagination-as-single-source-of-truth ([#40](https://github.com/haddowg/json-api-symfony/issues/40)) ([09d804a](https://github.com/haddowg/json-api-symfony/commit/09d804a3240b5145e12cfd58197d209f3c14a0f2))
* adopt the lazy-by-default relationship linkage data ([#39](https://github.com/haddowg/json-api-symfony/issues/39)) ([ca23298](https://github.com/haddowg/json-api-symfony/commit/ca23298b4f1f2e908750c044b39852c8992defa1))
* adopt the mandatory related-type relationship factory ([#61](https://github.com/haddowg/json-api-symfony/issues/61)) ([f03519d](https://github.com/haddowg/json-api-symfony/commit/f03519d13e514fdebaee218a6a0f6d925f305410))
* advertise the Atomic Operations endpoint in OpenAPI ([#56](https://github.com/haddowg/json-api-symfony/issues/56)) ([3e1a758](https://github.com/haddowg/json-api-symfony/commit/3e1a758bb0cf3576772f51a38b849d25caf6a92f))
* async write seam (202 Accepted / 303 See Other) ([#104](https://github.com/haddowg/json-api-symfony/issues/104)) ([64b113e](https://github.com/haddowg/json-api-symfony/commit/64b113e8ba4e52686a8eb2c1971f22971e7f6523))
* authorize a relationship independently of its parent ([#76](https://github.com/haddowg/json-api-symfony/issues/76)) ([23b279e](https://github.com/haddowg/json-api-symfony/commit/23b279ebe6ff8836cea10e2a8f9f5fc450cabcae))
* bridge resource/operation/relation description overrides into OpenAPI ([#68](https://github.com/haddowg/json-api-symfony/issues/68)) ([764687a](https://github.com/haddowg/json-api-symfony/commit/764687afb47178dc3e805f3950372c3575f1e756))
* cache and deprecation headers, strict query parameters, and write-only attributes ([79aeb59](https://github.com/haddowg/json-api-symfony/commit/79aeb59db7b854347ea0a7a2778743b23e286c58))
* complete the Validator bridge with When and date constraints ([#10](https://github.com/haddowg/json-api-symfony/issues/10)) ([ffdf0c2](https://github.com/haddowg/json-api-symfony/commit/ffdf0c22ebdb168ca435f70d16c4e4c0a52fd136))
* compose a JSON:API type from independent optional capabilities ([1d86f1a](https://github.com/haddowg/json-api-symfony/commit/1d86f1a365b9e07d69731c06f4b02f590a583a80))
* composite-attribute showcase resource in the example app ([#108](https://github.com/haddowg/json-api-symfony/issues/108)) ([c918a6d](https://github.com/haddowg/json-api-symfony/commit/c918a6d220fd3024b244db91d6f005857e8f0c7f))
* countable relations and the relationship-queries profile ([6e953d3](https://github.com/haddowg/json-api-symfony/commit/6e953d35800ffe018e44dd74156558c77daac604))
* cursor (keyset) pagination on pivot-related and linkage endpoints ([#111](https://github.com/haddowg/json-api-symfony/issues/111)) ([bf1efff](https://github.com/haddowg/json-api-symfony/commit/bf1efffd719a96b001b1060bdc299e38422a808a))
* cursor (keyset) pagination on related-collection endpoints ([#110](https://github.com/haddowg/json-api-symfony/issues/110)) ([fc4e6fe](https://github.com/haddowg/json-api-symfony/commit/fc4e6fe683d6a39a7da79296e4aef72eb5ba43e7))
* custom-encoded and policy-sourced resource ids ([0cdcdb7](https://github.com/haddowg/json-api-symfony/commit/0cdcdb73192c6802635ec2d89ec3f9e1ef0786a0))
* **data-provider:** honour declared filter defaults on collection reads ([#4](https://github.com/haddowg/json-api-symfony/issues/4)) ([d191277](https://github.com/haddowg/json-api-symfony/commit/d191277724c7b448f5efc0dc9513e1a5e06a10f5))
* declare article relationships and render linkage on reads ([#18](https://github.com/haddowg/json-api-symfony/issues/18)) ([7134b63](https://github.com/haddowg/json-api-symfony/commit/7134b6302d8d365a89274348968803ffec501b87))
* default-register the built-in profiles and make the set configurable ([#125](https://github.com/haddowg/json-api-symfony/issues/125)) ([a5294c2](https://github.com/haddowg/json-api-symfony/commit/a5294c229b5e7e738e2fe49280f704423ee2ecaa))
* **doctrine:** name the arm seam when a custom filter/sort has no arm ([#94](https://github.com/haddowg/json-api-symfony/issues/94)) ([5015854](https://github.com/haddowg/json-api-symfony/commit/50158544bf630c0991231e02be608a576cbed83b))
* **doctrine:** query customization via a tagged extension seam ([#3](https://github.com/haddowg/json-api-symfony/issues/3)) ([add7e46](https://github.com/haddowg/json-api-symfony/commit/add7e46ea71e25e174878619d802e0ce82d6ed33))
* emit request-host-absolute links by default ([#57](https://github.com/haddowg/json-api-symfony/issues/57)) ([5e1c8fa](https://github.com/haddowg/json-api-symfony/commit/5e1c8fa861d60a639d825282c02b2b9fa750a84d))
* **example:** full-text q filter + includable artist albums ([#81](https://github.com/haddowg/json-api-symfony/issues/81)) ([ec9b7dc](https://github.com/haddowg/json-api-symfony/commit/ec9b7dce633c24577130d8c94de911ee711eee45))
* **example:** persist writes across requests in the served demo ([#83](https://github.com/haddowg/json-api-symfony/issues/83)) ([307180f](https://github.com/haddowg/json-api-symfony/commit/307180fc01e18e35da7ed80287d8135b4fe4375a))
* **example:** richer served catalogue via a demo-only seed ([#84](https://github.com/haddowg/json-api-symfony/issues/84)) ([31595bb](https://github.com/haddowg/json-api-symfony/commit/31595bb3ba1761f238d1fc8cc9af54985bf2407f))
* execute CompareField cross-field rules document-first ([#15](https://github.com/haddowg/json-api-symfony/issues/15)) ([17b5f9a](https://github.com/haddowg/json-api-symfony/commit/17b5f9a888c8c009cba2753e07d252530579c1f0))
* execute the request-aware visibility, writability and relationship-authz predicates ([#48](https://github.com/haddowg/json-api-symfony/issues/48)) ([391f67b](https://github.com/haddowg/json-api-symfony/commit/391f67b852e3e326ca59da9d8d6f02187860742d))
* execute WhereAll/WhereAny filter groups on the Doctrine provider ([#121](https://github.com/haddowg/json-api-symfony/issues/121)) ([3148e21](https://github.com/haddowg/json-api-symfony/commit/3148e212142377e9590ff1902bdb3d6f6c611229))
* expose a custom action as a resource link (asLink) ([#67](https://github.com/haddowg/json-api-symfony/issues/67)) ([66cd8f4](https://github.com/haddowg/json-api-symfony/commit/66cd8f40aba5635c4665f85d032736ee28aab890))
* fail the build on polymorphic, Doctrine-column, and pivot misconfigurations ([#63](https://github.com/haddowg/json-api-symfony/issues/63)) ([2e0304a](https://github.com/haddowg/json-api-symfony/commit/2e0304a85dda36a3be11162394e90b3fb20c00ce))
* fail the build when a routed type is unservable, and project every sortable ([#62](https://github.com/haddowg/json-api-symfony/issues/62)) ([227a752](https://github.com/haddowg/json-api-symfony/commit/227a75215f769f40293a753b36f37041a37e3f86))
* filter value validation, merge-before-validate writes, and a browser test utility ([dd1f597](https://github.com/haddowg/json-api-symfony/commit/dd1f597660bafa16bd208ab69df0bb4a465c1c3b))
* flattened on() attributes and correct relationship windowing ([#50](https://github.com/haddowg/json-api-symfony/issues/50)) ([b163fb7](https://github.com/haddowg/json-api-symfony/commit/b163fb7dc0df8723a8a19ff0133772e63d96e977))
* full read queries — filtering, sorting, and pagination on both providers ([#2](https://github.com/haddowg/json-api-symfony/issues/2)) ([511c00a](https://github.com/haddowg/json-api-symfony/commit/511c00a27659b7daac0539a58718ad44ebae6db2))
* gate ?withCount behind the Relationship Counts profile ([#35](https://github.com/haddowg/json-api-symfony/issues/35)) ([b31214e](https://github.com/haddowg/json-api-symfony/commit/b31214e039efb48cea8cff1b7c0a3fb0199b06d8))
* gate the collection read with a securityList key ([#75](https://github.com/haddowg/json-api-symfony/issues/75)) ([bbc32f2](https://github.com/haddowg/json-api-symfony/commit/bbc32f21c785555727b7cf89f1dde086c33a9952))
* include preloading, default sort, a page-size cap, and include safeguards ([f7944f0](https://github.com/haddowg/json-api-symfony/commit/f7944f0a0517cac18cd6cc4033f4c7063e0a2938))
* let custom filters and sorts run on the Doctrine provider ([#47](https://github.com/haddowg/json-api-symfony/issues/47)) ([0a357b0](https://github.com/haddowg/json-api-symfony/commit/0a357b0832bc3e3ab396abda0d1860b6182ccbe2))
* lifecycle events and resource authorization ([11eb58e](https://github.com/haddowg/json-api-symfony/commit/11eb58e242ee7d0a915ecbd3964ea1d9fde87870))
* localize the error catalogue via the Symfony translator ([#119](https://github.com/haddowg/json-api-symfony/issues/119)) ([f7efdbe](https://github.com/haddowg/json-api-symfony/commit/f7efdbea7565af7d1d781263c5eeae59b0c7f3a4))
* native Symfony constraint and self-applying Doctrine filter carriers ([#101](https://github.com/haddowg/json-api-symfony/issues/101)) ([01b85b9](https://github.com/haddowg/json-api-symfony/commit/01b85b9927d354b436e3629f5ac995c69e8482b9))
* **openapi:** advertise the served document via links.describedby ([#88](https://github.com/haddowg/json-api-symfony/issues/88)) ([7dba24f](https://github.com/haddowg/json-api-symfony/commit/7dba24f2e001ccac4a89b59237fff23ca577bfc1))
* **openapi:** serve the JSON Schemas over HTTP ([#78](https://github.com/haddowg/json-api-symfony/issues/78)) ([b52145a](https://github.com/haddowg/json-api-symfony/commit/b52145a92a8679ea90b375545ff7317cd47d0e61))
* **openapi:** type a relation's pivot data in the linkage meta ([#77](https://github.com/haddowg/json-api-symfony/issues/77)) ([213dfaa](https://github.com/haddowg/json-api-symfony/commit/213dfaa71f0a348a9ec8056a8de73892eb885f33))
* optional opis JSON-Schema validation of write bodies ([#8](https://github.com/haddowg/json-api-symfony/issues/8)) ([bf36b20](https://github.com/haddowg/json-api-symfony/commit/bf36b20e0080322f5a1097ae35180f5d7bffe9c6))
* per-operation OpenAPI response declarations ([#117](https://github.com/haddowg/json-api-symfony/issues/117)) ([dc7d64f](https://github.com/haddowg/json-api-symfony/commit/dc7d64f61964cfd1d61ae39c35d3337027ba62e3))
* post-hydration entity-validation seam + UniqueEntity ([#16](https://github.com/haddowg/json-api-symfony/issues/16)) ([2480b3b](https://github.com/haddowg/json-api-symfony/commit/2480b3baccef6d9e5c861eb8921696448d3194f0))
* push convenience filters down to Doctrine and document them ([#45](https://github.com/haddowg/json-api-symfony/issues/45)) ([aca4369](https://github.com/haddowg/json-api-symfony/commit/aca4369fc8179bd7ac91ef1cb3f0cffa66f53b97))
* reject unknown sparse-fieldset members under the strict gate ([#49](https://github.com/haddowg/json-api-symfony/issues/49)) ([f224e07](https://github.com/haddowg/json-api-symfony/commit/f224e0717deffa8724ae1f0108017169fa527e20))
* related and relationship read endpoints with compound includes ([#21](https://github.com/haddowg/json-api-symfony/issues/21)) ([6c2210d](https://github.com/haddowg/json-api-symfony/commit/6c2210d0015fa6f3b7d392bbcf008f8c48e538c2))
* relationship mutation endpoints over a DataPersister relationship seam ([#22](https://github.com/haddowg/json-api-symfony/issues/22)) ([f6d9575](https://github.com/haddowg/json-api-symfony/commit/f6d9575f3facaa786b414c747a9c24860dd789b4))
* relationship mutation, writable pivot fields, and self links ([f584588](https://github.com/haddowg/json-api-symfony/commit/f584588942184558696fb9723e0b8c8532635280))
* relationship-existence filters (WhereHas/WhereDoesntHave) on both providers ([#24](https://github.com/haddowg/json-api-symfony/issues/24)) ([2bd502a](https://github.com/haddowg/json-api-symfony/commit/2bd502abeb2586a50126eb0d6dad5aaf183b0df7))
* render cursor pagination on batched includes ([#123](https://github.com/haddowg/json-api-symfony/issues/123)) ([9edd401](https://github.com/haddowg/json-api-symfony/commit/9edd401dd2a070292fd548b02c9e724d22c5387c))
* resource writes (POST/PATCH/DELETE) over a DataPersister SPI ([#6](https://github.com/haddowg/json-api-symfony/issues/6)) ([7fc8f4a](https://github.com/haddowg/json-api-symfony/commit/7fc8f4a3b124b69aa0c1979d7dd1d2934bc7c9c9))
* serve JSON:API read endpoints via Symfony kernel listeners ([#1](https://github.com/haddowg/json-api-symfony/issues/1)) ([356dc70](https://github.com/haddowg/json-api-symfony/commit/356dc707e1217a1fb2ff99a92559b150d5630f6d))
* serve, view and validate generated OpenAPI 3.1 documents ([#43](https://github.com/haddowg/json-api-symfony/issues/43)) ([d1646cc](https://github.com/haddowg/json-api-symfony/commit/d1646cc3218b568a06d48ccaf452e8096648c6f5))
* set relationships on whole-resource writes via the persister seam ([#23](https://github.com/haddowg/json-api-symfony/issues/23)) ([89e311b](https://github.com/haddowg/json-api-symfony/commit/89e311bc4ee6eb78d2f9efa490652ee1d6036b6e))
* singular-filtered collapse and adoption of the hardened core seams ([d7d37cf](https://github.com/haddowg/json-api-symfony/commit/d7d37cfe55aea1145761529a4dd1826c0c5286fa))
* storage-aware relationship linkage load-state (Doctrine + in-memory) ([#20](https://github.com/haddowg/json-api-symfony/issues/20)) ([13f35c5](https://github.com/haddowg/json-api-symfony/commit/13f35c5644b376be478ca60e4b2510d540519599))
* support client-selectable pagination menus ([#122](https://github.com/haddowg/json-api-symfony/issues/122)) ([25fbf0a](https://github.com/haddowg/json-api-symfony/commit/25fbf0a49b55fa899b260ee92506f49205358f59))
* support filter, sort and pagination on a pivot to-many relationship endpoint ([#72](https://github.com/haddowg/json-api-symfony/issues/72)) ([c41c807](https://github.com/haddowg/json-api-symfony/commit/c41c8074e718756d339378f80ae7179df06b32d8))
* support filter, sort and pagination on the to-many relationship endpoint ([#71](https://github.com/haddowg/json-api-symfony/issues/71)) ([e5865dd](https://github.com/haddowg/json-api-symfony/commit/e5865dd457009556cf24957b0cca553fa3374d54))
* support multiple named JSON:API servers ([a7ee461](https://github.com/haddowg/json-api-symfony/commit/a7ee461b3172f5949391cbfaa443ceb301f57a30))
* support Symfony 8 ([#95](https://github.com/haddowg/json-api-symfony/issues/95)) ([ab43bdf](https://github.com/haddowg/json-api-symfony/commit/ab43bdf8fa0c64aefb09736b9f5e6bb7d681ee25))
* Symfony Validator bridge for create/update validation ([#7](https://github.com/haddowg/json-api-symfony/issues/7)) ([7a5378f](https://github.com/haddowg/json-api-symfony/commit/7a5378fad742a049aa5ac3aca3d6ba330558904d))
* translate Sequentially and AtLeastOneOf to Symfony composites ([#14](https://github.com/haddowg/json-api-symfony/issues/14)) ([5dc8f37](https://github.com/haddowg/json-api-symfony/commit/5dc8f3781131eda82cdc0d10c057b6426f414317))
* typed constraint-translator extension, drop the Custom bridge ([#13](https://github.com/haddowg/json-api-symfony/issues/13)) ([41786c9](https://github.com/haddowg/json-api-symfony/commit/41786c940fbb9ffb209a134b9bf61479086f2f03))
* validate composite attribute types (Obj, OneOf, Shape) ([#106](https://github.com/haddowg/json-api-symfony/issues/106)) ([c583fc2](https://github.com/haddowg/json-api-symfony/commit/c583fc2e7b7922d9b98c02fa8542535debfac379))
* validate nested structured-attribute child constraints ([#25](https://github.com/haddowg/json-api-symfony/issues/25)) ([ecf9721](https://github.com/haddowg/json-api-symfony/commit/ecf9721b463ce7ee6c37b4a2111b00c4e8540143))


### Bug Fixes

* count a windowed include only when the pagination counts ([#41](https://github.com/haddowg/json-api-symfony/issues/41)) ([21115a0](https://github.com/haddowg/json-api-symfony/commit/21115a054d4a15494a6defe2fa3a1a13669cc026))
* drop the dead include-preload on relationship endpoints ([#87](https://github.com/haddowg/json-api-symfony/issues/87)) ([acae621](https://github.com/haddowg/json-api-symfony/commit/acae621a7290d62ff4e55bf719395b38f0068bb9))
* **example:** make the Docker build resilient to GitHub rate limits ([#82](https://github.com/haddowg/json-api-symfony/issues/82)) ([d1b07e6](https://github.com/haddowg/json-api-symfony/commit/d1b07e616fe869854fc156ff73f45ebb4fb8d441))
* keep dev dependencies and the windowed batch within the supported version range ([5133a4c](https://github.com/haddowg/json-api-symfony/commit/5133a4c74f603e6c71155d4ebde0503fb094062a))
* **openapi:** advertise pivot sorts and the require-client-id policy ([#73](https://github.com/haddowg/json-api-symfony/issues/73)) ([1791397](https://github.com/haddowg/json-api-symfony/commit/1791397f666d65a406b4418e6ed360b246f62a96))
* **openapi:** prune include paths to types unserializable on the server ([#90](https://github.com/haddowg/json-api-symfony/issues/90)) ([7199d3b](https://github.com/haddowg/json-api-symfony/commit/7199d3be31e7fc6b4a5d8618599f96b7e11520f9))
* read belongsToMany pivot writes from meta.pivot (symmetric with reads) ([#80](https://github.com/haddowg/json-api-symfony/issues/80)) ([4b55b70](https://github.com/haddowg/json-api-symfony/commit/4b55b70ee1e58ecf4ee14e10d95692f9055857b2))
* reject a filter on a to-many relationship endpoint instead of ignoring it ([#70](https://github.com/haddowg/json-api-symfony/issues/70)) ([1467622](https://github.com/haddowg/json-api-symfony/commit/146762202f852f2476165e56a9b9967c3d8a7798))
* reject a relationship mutation with an unaccepted linkage type ([#60](https://github.com/haddowg/json-api-symfony/issues/60)) ([afd9e8d](https://github.com/haddowg/json-api-symfony/commit/afd9e8da75fd7f3e663ed1a650a64a26bbfec506))
* render belongsToMany pivot meta on a primary-resource document linkage ([#79](https://github.com/haddowg/json-api-symfony/issues/79)) ([bcb984e](https://github.com/haddowg/json-api-symfony/commit/bcb984ea40c7947f3853aa2cb2f6d7fa07c8efcd))
* report relationship-queries path errors against the family the client used ([#34](https://github.com/haddowg/json-api-symfony/issues/34)) ([9ce831c](https://github.com/haddowg/json-api-symfony/commit/9ce831cd23ffa648940d87eac56271c10bc9cc64))
* skip include batching when the relation column cannot take the write-back ([#96](https://github.com/haddowg/json-api-symfony/issues/96)) ([ca66cdb](https://github.com/haddowg/json-api-symfony/commit/ca66cdbed71c364f60a250a8f303a1324eb0b197))


### Performance Improvements

* collapse cursor-resolved includes to a single window query ([#126](https://github.com/haddowg/json-api-symfony/issues/126)) ([797eb8a](https://github.com/haddowg/json-api-symfony/commit/797eb8ab2ee43bb35126d6c0a4a9a80103181b85))


### Miscellaneous Chores

* add the release-please workflow ([#99](https://github.com/haddowg/json-api-symfony/issues/99)) ([75da881](https://github.com/haddowg/json-api-symfony/commit/75da88141b18352e843f2ec3e2427a1f5125e347))
* build the docs with mkdocs --strict as a pre-commit hook ([#53](https://github.com/haddowg/json-api-symfony/issues/53)) ([bc3c7f4](https://github.com/haddowg/json-api-symfony/commit/bc3c7f40cccc0f1e8b73d97e227cd3a39ce47b3a))
* **example:** correct translator.enabled default in config reference ([#128](https://github.com/haddowg/json-api-symfony/issues/128)) ([5f762fd](https://github.com/haddowg/json-api-symfony/commit/5f762fdd2607f980ad50a2dbc7aea18b2d553086))
* exclude non-runtime files from the Composer dist archive ([#134](https://github.com/haddowg/json-api-symfony/issues/134)) ([fcd0ec7](https://github.com/haddowg/json-api-symfony/commit/fcd0ec7f8fc9066c1a762080155baf1ebe8bb7ec))
* remove design artifacts and prepare docs for release ([#133](https://github.com/haddowg/json-api-symfony/issues/133)) ([e072434](https://github.com/haddowg/json-api-symfony/commit/e0724341be5520b61a444f9639de08354616f1b8))
* scaffold json-api Symfony bundle ([56a51d6](https://github.com/haddowg/json-api-symfony/commit/56a51d6860f1f4c9350aff2870aab8d76b90c2a3))
* tag releases as v-prefixed versions for Packagist ([#135](https://github.com/haddowg/json-api-symfony/issues/135)) ([d5a232a](https://github.com/haddowg/json-api-symfony/commit/d5a232ae0c1e4111ae84d355fcdd932357664565))
* untrack __pycache__ and gitignore it ([#112](https://github.com/haddowg/json-api-symfony/issues/112)) ([5854da4](https://github.com/haddowg/json-api-symfony/commit/5854da49d8619cc75afeed2ae325924f163c528b))


### Code Refactoring

* rename relationship linkage methods to data ([#36](https://github.com/haddowg/json-api-symfony/issues/36)) ([bf1eca9](https://github.com/haddowg/json-api-symfony/commit/bf1eca9b20a141514df5346107c513b06b53041f))
* track the core v1 surface changes and tidy the OpenAPI metadata layer ([#52](https://github.com/haddowg/json-api-symfony/issues/52)) ([72691cb](https://github.com/haddowg/json-api-symfony/commit/72691cbaaa41b645f88b11e5779a30f9e101f11e))


### Build System

* depend on the published core release (^1.0) ([#136](https://github.com/haddowg/json-api-symfony/issues/136)) ([b2a9bb2](https://github.com/haddowg/json-api-symfony/commit/b2a9bb20fa9963bcf3252b27d5de2f76f264661f))
