# Changelog

## [2.14.1](https://github.com/haddowg/json-api-symfony/compare/v2.14.0...v2.14.1) (2026-11-13)


### Bug Fixes

* require core 2.18.1 so relatedQuery filters keep their enum types ([#375](https://github.com/haddowg/json-api-symfony/issues/375)) ([2f652c3](https://github.com/haddowg/json-api-symfony/commit/2f652c3e31fd462ec24f47b77415a1a5af84f169))

## [2.14.0](https://github.com/haddowg/json-api-symfony/compare/v2.13.0...v2.14.0) (2026-11-13)


### Features

* adopt haddowg/json-api 2.18 ([#373](https://github.com/haddowg/json-api-symfony/issues/373)) ([0a7ded3](https://github.com/haddowg/json-api-symfony/commit/0a7ded3a32161ac5640e2cb46e494680f69db1c7))

## [2.13.0](https://github.com/haddowg/json-api-symfony/compare/v2.12.1...v2.13.0) (2026-11-13)


### Features

* read relatedQuery wherever core 2.17 documents it ([#371](https://github.com/haddowg/json-api-symfony/issues/371)) ([9451ac8](https://github.com/haddowg/json-api-symfony/commit/9451ac80cedcede941177e8adaac9a1a9242b73d))

## [2.12.1](https://github.com/haddowg/json-api-symfony/compare/v2.12.0...v2.12.1) (2026-11-12)


### Bug Fixes

* resolve the metadata's data-layer facts lazily ([#369](https://github.com/haddowg/json-api-symfony/issues/369)) ([b54be35](https://github.com/haddowg/json-api-symfony/commit/b54be353813aae70aa5ad6ec7a107ddefaeb77b7))

## [2.12.0](https://github.com/haddowg/json-api-symfony/compare/v2.11.0...v2.12.0) (2026-11-12)


### Features

* offer each error code only where the bundle can raise it ([#367](https://github.com/haddowg/json-api-symfony/issues/367)) ([1981d52](https://github.com/haddowg/json-api-symfony/commit/1981d52d581f9e15ffa16c047c0ed493fc2cfb00))

## [2.11.0](https://github.com/haddowg/json-api-symfony/compare/v2.10.0...v2.11.0) (2026-11-12)


### Features

* adopt haddowg/json-api 2.15 ([#365](https://github.com/haddowg/json-api-symfony/issues/365)) ([40e7202](https://github.com/haddowg/json-api-symfony/commit/40e7202bbc348b0e061fe49edcb54179d41a5c1a))

## [2.10.0](https://github.com/haddowg/json-api-symfony/compare/v2.9.0...v2.10.0) (2026-11-12)


### Features

* describe sorts, dropped defaults and WhereHasMatching in OpenAPI ([38b49fc](https://github.com/haddowg/json-api-symfony/commit/38b49fc580617ff1ecaec9e43b36db7d3b65e4eb))


### Bug Fixes

* batch a pivot relation's related reads across a page ([#363](https://github.com/haddowg/json-api-symfony/issues/363)) ([71434dd](https://github.com/haddowg/json-api-symfony/commit/71434dd47766f2602277c9687bac62d02ec12340))
* count each member's relations on a mixed-type response ([38b49fc](https://github.com/haddowg/json-api-symfony/commit/38b49fc580617ff1ecaec9e43b36db7d3b65e4eb))
* count the target of a to-one related endpoint ([#361](https://github.com/haddowg/json-api-symfony/issues/361)) ([3a9a402](https://github.com/haddowg/json-api-symfony/commit/3a9a40291555f437c5b752aeaaa7cd8e2d8895ce))

## [2.9.0](https://github.com/haddowg/json-api-symfony/compare/v2.8.0...v2.9.0) (2026-11-11)


### Features

* let a relation drop its related type's filter defaults ([#359](https://github.com/haddowg/json-api-symfony/issues/359)) ([02b8380](https://github.com/haddowg/json-api-symfony/commit/02b8380e79778fefd2707fa0fdafa512fa246a06))


### Bug Fixes

* batch a withData to-many's linkage across a page ([#358](https://github.com/haddowg/json-api-symfony/issues/358)) ([dd0d808](https://github.com/haddowg/json-api-symfony/commit/dd0d8081ff871126641a8ef45fcbd4db7216d9b2))

## [2.8.0](https://github.com/haddowg/json-api-symfony/compare/v2.7.2...v2.8.0) (2026-11-11)


### Features

* batch reads through a related type's own provider ([#356](https://github.com/haddowg/json-api-symfony/issues/356)) ([e870371](https://github.com/haddowg/json-api-symfony/commit/e87037124f5b82b255b78e6288f85eb7c9c1ec9e))

## [2.7.2](https://github.com/haddowg/json-api-symfony/compare/v2.7.1...v2.7.2) (2026-11-10)


### Bug Fixes

* scope the linkage and includes of an action's response ([#353](https://github.com/haddowg/json-api-symfony/issues/353)) ([a157b74](https://github.com/haddowg/json-api-symfony/commit/a157b745737a092fe30b4346facdf88cec77a394))

## [2.7.1](https://github.com/haddowg/json-api-symfony/compare/v2.7.0...v2.7.1) (2026-11-10)


### Bug Fixes

* never render a provider-read to-many's linkage off the model ([#351](https://github.com/haddowg/json-api-symfony/issues/351)) ([9baaa88](https://github.com/haddowg/json-api-symfony/commit/9baaa881542c698fa20f0d119f57f1c509ef0059))

## [2.7.0](https://github.com/haddowg/json-api-symfony/compare/v2.6.2...v2.7.0) (2026-11-10)


### Features

* filter through a relationship path by a list of related ids ([#347](https://github.com/haddowg/json-api-symfony/issues/347)) ([3e44515](https://github.com/haddowg/json-api-symfony/commit/3e445154bbed601472d34db4de15b827ffe36809))


### Bug Fixes

* correlate relationship filters to the counted member ([#348](https://github.com/haddowg/json-api-symfony/issues/348)) ([3ab34f3](https://github.com/haddowg/json-api-symfony/commit/3ab34f34f387fa53fcff2f42aea03f6cfb8c9227))
* keep enum fields as their case in the example and fixtures ([#350](https://github.com/haddowg/json-api-symfony/issues/350)) ([f0ca80e](https://github.com/haddowg/json-api-symfony/commit/f0ca80eeab3d187552060a38ccc38ec6fa02b85d))

## [2.6.2](https://github.com/haddowg/json-api-symfony/compare/v2.6.1...v2.6.2) (2026-11-10)


### Bug Fixes

* render withCount=_self_ totals on cursor pages ([#345](https://github.com/haddowg/json-api-symfony/issues/345)) ([0c63bd5](https://github.com/haddowg/json-api-symfony/commit/0c63bd5e7475e6d9aa5b11dce6a1eec837e3cf3f))

## [2.6.1](https://github.com/haddowg/json-api-symfony/compare/v2.6.0...v2.6.1) (2026-11-10)


### Bug Fixes

* **deps:** require haddowg/json-api 2.10 ([#343](https://github.com/haddowg/json-api-symfony/issues/343)) ([9e2fbd0](https://github.com/haddowg/json-api-symfony/commit/9e2fbd0305e80bce08366144fcdeda364f24f90f))

## [2.6.0](https://github.com/haddowg/json-api-symfony/compare/v2.5.1...v2.6.0) (2026-11-10)


### Features

* validate and store Union fields ([#341](https://github.com/haddowg/json-api-symfony/issues/341)) ([912c653](https://github.com/haddowg/json-api-symfony/commit/912c6538715fb4bd78dd95600ec452a383d55243))

## [2.5.1](https://github.com/haddowg/json-api-symfony/compare/v2.5.0...v2.5.1) (2026-11-10)


### Bug Fixes

* read a pivot's current members through its association rows ([#339](https://github.com/haddowg/json-api-symfony/issues/339)) ([a788bc8](https://github.com/haddowg/json-api-symfony/commit/a788bc86996ba2d295149306a7c446ee05b2e615))

## [2.5.0](https://github.com/haddowg/json-api-symfony/compare/v2.4.2...v2.5.0) (2026-11-10)


### Features

* apply a to-many's default read order on every read path ([#335](https://github.com/haddowg/json-api-symfony/issues/335)) ([ecc74e9](https://github.com/haddowg/json-api-symfony/commit/ecc74e920c94c735b24f92d41eac1eff26aebed2))
* give an identified MorphToMany pivot fields on its link rows ([#337](https://github.com/haddowg/json-api-symfony/issues/337)) ([8ea610e](https://github.com/haddowg/json-api-symfony/commit/8ea610e75a2dd40ad0fef7faf889df837220185f))
* guard to-many membership with cannotChangeMembership ([#336](https://github.com/haddowg/json-api-symfony/issues/336)) ([c6152dc](https://github.com/haddowg/json-api-symfony/commit/c6152dccd6c7f7b3ba2ea0dc501d4f0768754d1d))
* write a positioned to-many's positions from its linkage ([#338](https://github.com/haddowg/json-api-symfony/issues/338)) ([779e4e2](https://github.com/haddowg/json-api-symfony/commit/779e4e2d6d2d4434cf6e562ac8c8d6edd1011faa))


### Bug Fixes

* **example:** drop the read-only catalogueNumber from releaseInfo write examples ([#332](https://github.com/haddowg/json-api-symfony/issues/332)) ([2b89c78](https://github.com/haddowg/json-api-symfony/commit/2b89c78c5908107cd88e10f95dccab1361c54761))

## [2.4.2](https://github.com/haddowg/json-api-symfony/compare/v2.4.1...v2.4.2) (2026-11-09)


### Bug Fixes

* **validation:** reject write values whose JSON type does not match the field ([#331](https://github.com/haddowg/json-api-symfony/issues/331)) ([2c7ee94](https://github.com/haddowg/json-api-symfony/commit/2c7ee949fff906a5579d99255ee93019d612340a))

## [2.4.1](https://github.com/haddowg/json-api-symfony/compare/v2.4.0...v2.4.1) (2026-11-09)


### Bug Fixes

* **validation:** require the core release that validates write-hooked values ([#329](https://github.com/haddowg/json-api-symfony/issues/329)) ([fea4e3a](https://github.com/haddowg/json-api-symfony/commit/fea4e3aaec7633118f2dfc2c8420fa2591a28be7))

## [2.4.0](https://github.com/haddowg/json-api-symfony/compare/v2.3.2...v2.4.0) (2026-11-09)


### ⚠ BREAKING CHANGES

* **actions:** require an inputType for document input ([#325](https://github.com/haddowg/json-api-symfony/issues/325))

### Features

* **actions:** require an inputType for document input ([#325](https://github.com/haddowg/json-api-symfony/issues/325)) ([c724758](https://github.com/haddowg/json-api-symfony/commit/c724758097d213b51e187cd3c8597be3cc5e121a))
* **doctrine:** serve computed pivot fields without an association entity ([#326](https://github.com/haddowg/json-api-symfony/issues/326)) ([06501a6](https://github.com/haddowg/json-api-symfony/commit/06501a668acb281386a20d765340d2553dbd7b97))
* **openapi:** type the identifier meta a relation declares ([#324](https://github.com/haddowg/json-api-symfony/issues/324)) ([1df2125](https://github.com/haddowg/json-api-symfony/commit/1df2125f11edb22275b76a7928dc6d79e8639204))
* **resources:** add relatedOnly types ([#322](https://github.com/haddowg/json-api-symfony/issues/322)) ([49a6d9e](https://github.com/haddowg/json-api-symfony/commit/49a6d9eb9fdac252a1c4b7686e90ec1300bfb83e))
* support list filters and filter families ([#323](https://github.com/haddowg/json-api-symfony/issues/323)) ([7e09a1c](https://github.com/haddowg/json-api-symfony/commit/7e09a1ca359df2cf28ef59eb1f8cc81c2c0bf64a))
* validate the members of open objects ([#327](https://github.com/haddowg/json-api-symfony/issues/327)) ([d0072f2](https://github.com/haddowg/json-api-symfony/commit/d0072f2b15e5c77b774934e14453d390603e8f0e))

## [2.3.2](https://github.com/haddowg/json-api-symfony/compare/v2.3.1...v2.3.2) (2026-11-09)


### Bug Fixes

* **actions:** hide action links the token lacks scopes for ([#319](https://github.com/haddowg/json-api-symfony/issues/319)) ([a201635](https://github.com/haddowg/json-api-symfony/commit/a20163583f9a6c948474e3787abe6c216c4b8d83))
* **doctrine:** write relationships through reflection ([#320](https://github.com/haddowg/json-api-symfony/issues/320)) ([e869194](https://github.com/haddowg/json-api-symfony/commit/e869194f737e8bf097afc1cfc791d4eb7711a17d))
* **validation:** enforce to-many minItems and maxItems ([#318](https://github.com/haddowg/json-api-symfony/issues/318)) ([7ebe080](https://github.com/haddowg/json-api-symfony/commit/7ebe080907536eaea22e9632f7340af3caf8b6b7))

## [2.3.1](https://github.com/haddowg/json-api-symfony/compare/v2.3.0...v2.3.1) (2026-11-08)


### Bug Fixes

* **doctrine:** read relations whose related id is an association ([#316](https://github.com/haddowg/json-api-symfony/issues/316)) ([d34d938](https://github.com/haddowg/json-api-symfony/commit/d34d938bfce570a1e365ac9da204c94798b53027))

## [2.3.0](https://github.com/haddowg/json-api-symfony/compare/v2.2.1...v2.3.0) (2026-11-08)


### Features

* let an action declare that it answers 201 Created ([#314](https://github.com/haddowg/json-api-symfony/issues/314)) ([36b9559](https://github.com/haddowg/json-api-symfony/commit/36b95593636d81a54427cc31edffdf39dbd55a01))

## [2.2.1](https://github.com/haddowg/json-api-symfony/compare/v2.2.0...v2.2.1) (2026-11-08)


### Bug Fixes

* **atomic:** refuse an href the operation cannot target ([#312](https://github.com/haddowg/json-api-symfony/issues/312)) ([33da5fa](https://github.com/haddowg/json-api-symfony/commit/33da5fad0073d7c3c2465331c15c204d6a50927d))

## [2.2.0](https://github.com/haddowg/json-api-symfony/compare/v2.1.1...v2.2.0) (2026-11-08)


### Features

* let collection actions take their type's filters ([#310](https://github.com/haddowg/json-api-symfony/issues/310)) ([967dd19](https://github.com/haddowg/json-api-symfony/commit/967dd1984f54be9b1ba247913309b85595b75df5))

## [2.1.1](https://github.com/haddowg/json-api-symfony/compare/v2.1.0...v2.1.1) (2026-11-07)


### Miscellaneous Chores

* release 2.1.1 ([#307](https://github.com/haddowg/json-api-symfony/issues/307)) ([d7b9983](https://github.com/haddowg/json-api-symfony/commit/d7b99832ab178b1fc54d0d569846d2e3576bfb47))

## [2.1.0](https://github.com/haddowg/json-api-symfony/compare/v2.0.0...v2.1.0) (2026-11-07)


### Features

* document and test error codes scoped to the operations that raise them ([#304](https://github.com/haddowg/json-api-symfony/issues/304)) ([3127fb9](https://github.com/haddowg/json-api-symfony/commit/3127fb93851d77ab724b02851ec9db23155a5b69))

## [2.0.0](https://github.com/haddowg/json-api-symfony/compare/v1.31.0...v2.0.0) (2026-11-07)


### ⚠ BREAKING CHANGES

* the bundle now requires haddowg/json-api ^2.0. An undeclared attribute in a write body is rejected rather than ignored, and a OneOf that relied on the implicit `type` discriminator must name it.

### Features

* require haddowg/json-api 2.0 ([#302](https://github.com/haddowg/json-api-symfony/issues/302)) ([0ded816](https://github.com/haddowg/json-api-symfony/commit/0ded8169b646c716678de08e5b3ffe3c4e98c817))

## [1.31.0](https://github.com/haddowg/json-api-symfony/compare/v1.30.0...v1.31.0) (2026-11-06)


### Features

* mark deprecated types, operations and actions in openapi ([#300](https://github.com/haddowg/json-api-symfony/issues/300)) ([5f6b244](https://github.com/haddowg/json-api-symfony/commit/5f6b244a24fa07357edc5f4778d5ec8bece3a72d))

## [1.30.0](https://github.com/haddowg/json-api-symfony/compare/v1.29.0...v1.30.0) (2026-11-06)


### Features

* serve the OpenAPI document as YAML ([#296](https://github.com/haddowg/json-api-symfony/issues/296)) ([3a78568](https://github.com/haddowg/json-api-symfony/commit/3a78568132f74e2707bf1493f3d5184a0b448587))


### Bug Fixes

* let an authored composite constraint skip the translator ([#298](https://github.com/haddowg/json-api-symfony/issues/298)) ([6ad3719](https://github.com/haddowg/json-api-symfony/commit/6ad3719e4355c82cd135b1d17fa561fb59df7123))

## [1.29.0](https://github.com/haddowg/json-api-symfony/compare/v1.28.4...v1.29.0) (2026-11-06)


### Features

* accept core operation names in operationExtensions ([#294](https://github.com/haddowg/json-api-symfony/issues/294)) ([e0fd2d4](https://github.com/haddowg/json-api-symfony/commit/e0fd2d4ab82b6ca79969e7e495a0fcda14dcac46))

## [1.28.4](https://github.com/haddowg/json-api-symfony/compare/v1.28.3...v1.28.4) (2026-11-05)


### Bug Fixes

* title a 429 error Too Many Requests ([#292](https://github.com/haddowg/json-api-symfony/issues/292)) ([ef2fc2b](https://github.com/haddowg/json-api-symfony/commit/ef2fc2be5a13c82830f4f896d1875bf0b600b8e2))

## [1.28.3](https://github.com/haddowg/json-api-symfony/compare/v1.28.2...v1.28.3) (2026-11-05)


### Bug Fixes

* accept integer header values from http exceptions ([#290](https://github.com/haddowg/json-api-symfony/issues/290)) ([8e47e39](https://github.com/haddowg/json-api-symfony/commit/8e47e399970eed0bf33ca9ac82afecc97c2335b1))

## [1.28.2](https://github.com/haddowg/json-api-symfony/compare/v1.28.1...v1.28.2) (2026-11-05)


### Bug Fixes

* keep the firewall entry point's headers on json:api 401s ([#288](https://github.com/haddowg/json-api-symfony/issues/288)) ([1af1ed2](https://github.com/haddowg/json-api-symfony/commit/1af1ed23af22ad6e7e0afffd85e3ab42b78da546))

## [1.28.1](https://github.com/haddowg/json-api-symfony/compare/v1.28.0...v1.28.1) (2026-11-03)


### Bug Fixes

* render json-stored dates inside composite attributes ([#286](https://github.com/haddowg/json-api-symfony/issues/286)) ([3736bb4](https://github.com/haddowg/json-api-symfony/commit/3736bb4bb83968eacf331290b227c8c75a6e9bb7))

## [1.28.0](https://github.com/haddowg/json-api-symfony/compare/v1.27.0...v1.28.0) (2026-11-03)


### Features

* validate each element of a structured list ([#284](https://github.com/haddowg/json-api-symfony/issues/284)) ([8551381](https://github.com/haddowg/json-api-symfony/commit/855138128cd24a2746dcc05111ec4fb008b7a7a6))

## [1.27.0](https://github.com/haddowg/json-api-symfony/compare/v1.26.0...v1.27.0) (2026-11-03)


### Features

* support sort families and custom sorts on cursor pages ([#282](https://github.com/haddowg/json-api-symfony/issues/282)) ([0adf75c](https://github.com/haddowg/json-api-symfony/commit/0adf75ce0d900c39642bea9a1dc049c96fb11888))

## [1.26.0](https://github.com/haddowg/json-api-symfony/compare/v1.25.2...v1.26.0) (2026-11-02)


### Features

* honour excluded include paths in the bundle ([#280](https://github.com/haddowg/json-api-symfony/issues/280)) ([a5fe946](https://github.com/haddowg/json-api-symfony/commit/a5fe946341c481fc766a4e5efa4a1862ad699692))

## [1.25.2](https://github.com/haddowg/json-api-symfony/compare/v1.25.1...v1.25.2) (2026-11-02)


### Bug Fixes

* bind a cursor key as the date class its column type expects ([#278](https://github.com/haddowg/json-api-symfony/issues/278)) ([fa47cb8](https://github.com/haddowg/json-api-symfony/commit/fa47cb8b9eb737440c99373271463a0203436d5f))

## [1.25.1](https://github.com/haddowg/json-api-symfony/compare/v1.25.0...v1.25.1) (2026-11-02)


### Bug Fixes

* compare and store client date-times in the default timezone ([#275](https://github.com/haddowg/json-api-symfony/issues/275)) ([87fc97c](https://github.com/haddowg/json-api-symfony/commit/87fc97cdfe40c6d89c9d0819afd9c85f043512ce))

## [1.25.0](https://github.com/haddowg/json-api-symfony/compare/v1.24.0...v1.25.0) (2026-11-01)


### Features

* expose the resolved paginator on the after-fetch-collection event ([#273](https://github.com/haddowg/json-api-symfony/issues/273)) ([c50a56c](https://github.com/haddowg/json-api-symfony/commit/c50a56cb9a74a74966758219f49b229b8fcfde81))

## [1.24.0](https://github.com/haddowg/json-api-symfony/compare/v1.23.1...v1.24.0) (2026-10-31)


### Features

* follow core's discriminated resource unions and typed included ([#271](https://github.com/haddowg/json-api-symfony/issues/271)) ([e1eabd4](https://github.com/haddowg/json-api-symfony/commit/e1eabd46681679992cc0b763358cf3dbaae6bf5e))
* run entity-level constraints after relationship writes ([#269](https://github.com/haddowg/json-api-symfony/issues/269)) ([ec748a6](https://github.com/haddowg/json-api-symfony/commit/ec748a66271db9031359900b5fd246987c161fd6))
* serve an identified MorphToMany from its stored pairs ([#272](https://github.com/haddowg/json-api-symfony/issues/272)) ([e19a639](https://github.com/haddowg/json-api-symfony/commit/e19a639586dfed09c0d3f004762b0dfdd714ac0b))

## [1.23.1](https://github.com/haddowg/json-api-symfony/compare/v1.23.0...v1.23.1) (2026-10-31)


### Bug Fixes

* serve a computed polymorphic to-many when no related type is scoped ([#267](https://github.com/haddowg/json-api-symfony/issues/267)) ([cbdecaf](https://github.com/haddowg/json-api-symfony/commit/cbdecaf9dcf4f8a9e3f6484d5b74904ab3d1faa3))

## [1.23.0](https://github.com/haddowg/json-api-symfony/compare/v1.22.0...v1.23.0) (2026-10-28)


### Features

* let each server declare its own response-header defaults ([#264](https://github.com/haddowg/json-api-symfony/issues/264)) ([72d6f7a](https://github.com/haddowg/json-api-symfony/commit/72d6f7a04dd43b57c73033e4c52e135656722c39))

## [1.22.0](https://github.com/haddowg/json-api-symfony/compare/v1.21.0...v1.22.0) (2026-10-28)


### Features

* load identified MorphTo targets by id through each type's provider ([#262](https://github.com/haddowg/json-api-symfony/issues/262)) ([3c7f1ec](https://github.com/haddowg/json-api-symfony/commit/3c7f1eca24f67be7f7b282545830a9443b8c3fe9))

## [1.21.0](https://github.com/haddowg/json-api-symfony/compare/v1.20.1...v1.21.0) (2026-10-28)


### Features

* run Doctrine extensions on the include hydration query ([#260](https://github.com/haddowg/json-api-symfony/issues/260)) ([51dc778](https://github.com/haddowg/json-api-symfony/commit/51dc778e047317eb26040f5fda24b4d9109359bc))

## [1.20.1](https://github.com/haddowg/json-api-symfony/compare/v1.20.0...v1.20.1) (2026-10-27)


### Bug Fixes

* declare the atomic ext on an error only when the request named it ([#258](https://github.com/haddowg/json-api-symfony/issues/258)) ([e3bd1b3](https://github.com/haddowg/json-api-symfony/commit/e3bd1b38e2fcac66aa375a4651292ae2bc989629))

## [1.20.0](https://github.com/haddowg/json-api-symfony/compare/v1.19.1...v1.20.0) (2026-10-27)


### Features

* add a --compact option to the export commands ([#255](https://github.com/haddowg/json-api-symfony/issues/255)) ([c201675](https://github.com/haddowg/json-api-symfony/commit/c20167567aff0fba64e82a6ea133da2e8fbbd9ff))

## [1.19.1](https://github.com/haddowg/json-api-symfony/compare/v1.19.0...v1.19.1) (2026-10-27)


### Bug Fixes

* render a 405 on a JSON:API path as a JSON:API error ([#253](https://github.com/haddowg/json-api-symfony/issues/253)) ([a14b771](https://github.com/haddowg/json-api-symfony/commit/a14b771c733a4588dcaceb3705448eb4a4f74d52))

## [1.19.0](https://github.com/haddowg/json-api-symfony/compare/v1.18.1...v1.19.0) (2026-10-27)


### Features

* render a resource's conditional action links ([#251](https://github.com/haddowg/json-api-symfony/issues/251)) ([8d3b3aa](https://github.com/haddowg/json-api-symfony/commit/8d3b3aa1ac51c073400ecbe38ace76d55df677a9))

## [1.18.1](https://github.com/haddowg/json-api-symfony/compare/v1.18.0...v1.18.1) (2026-10-27)


### Bug Fixes

* build action links on the server's link base ([#249](https://github.com/haddowg/json-api-symfony/issues/249)) ([844aa10](https://github.com/haddowg/json-api-symfony/commit/844aa109547a9855153684c94a493303c93351ab))

## [1.18.0](https://github.com/haddowg/json-api-symfony/compare/v1.17.2...v1.18.0) (2026-10-27)


### Features

* let a custom action answer with a URL ([#247](https://github.com/haddowg/json-api-symfony/issues/247)) ([af4cd4d](https://github.com/haddowg/json-api-symfony/commit/af4cd4d5b2929c5c074fa3ee8f9cf290cc8bd1ba))

## [1.17.2](https://github.com/haddowg/json-api-symfony/compare/v1.17.1...v1.17.2) (2026-10-27)


### Bug Fixes

* list no include paths through a single-type morph relation ([#245](https://github.com/haddowg/json-api-symfony/issues/245)) ([b50ee62](https://github.com/haddowg/json-api-symfony/commit/b50ee624ecba94855e861e7f06550c8b3816f7ef))

## [1.17.1](https://github.com/haddowg/json-api-symfony/compare/v1.17.0...v1.17.1) (2026-10-27)


### Bug Fixes

* treat a single-type morph relation as polymorphic at runtime ([#243](https://github.com/haddowg/json-api-symfony/issues/243)) ([aad9303](https://github.com/haddowg/json-api-symfony/commit/aad93034378eb1f1b0795b4ed217d2e1ba7b765e))

## [1.17.0](https://github.com/haddowg/json-api-symfony/compare/v1.16.0...v1.17.0) (2026-10-27)


### Features

* enforce OAuth scopes per operation and add an operation catalogue ([#241](https://github.com/haddowg/json-api-symfony/issues/241)) ([f463ccf](https://github.com/haddowg/json-api-symfony/commit/f463ccf2fcbfda2305ff2decb9e1012b20bc09e1))

## [1.16.0](https://github.com/haddowg/json-api-symfony/compare/v1.15.0...v1.16.0) (2026-10-26)


### Features

* wire action safety, extensions and operation id checks ([#239](https://github.com/haddowg/json-api-symfony/issues/239)) ([e63f55d](https://github.com/haddowg/json-api-symfony/commit/e63f55d94338fadaeaa7105071487c0cb450ed85))

## [1.15.0](https://github.com/haddowg/json-api-symfony/compare/v1.14.0...v1.15.0) (2026-10-26)


### Features

* wire declared resource links into the bundle ([#237](https://github.com/haddowg/json-api-symfony/issues/237)) ([9fde56a](https://github.com/haddowg/json-api-symfony/commit/9fde56a953054037cb5fb056ccabf37f2c3fe42e))

## [1.14.0](https://github.com/haddowg/json-api-symfony/compare/v1.13.0...v1.14.0) (2026-10-26)


### Features

* let examples show always-counted pages and declared linkage ids ([#235](https://github.com/haddowg/json-api-symfony/issues/235)) ([78b49b8](https://github.com/haddowg/json-api-symfony/commit/78b49b88f331432f5058138bf0c3120d556d4781))

## [1.13.0](https://github.com/haddowg/json-api-symfony/compare/v1.12.0...v1.13.0) (2026-10-26)


### Features

* declare and report generated OpenAPI operation examples ([#233](https://github.com/haddowg/json-api-symfony/issues/233)) ([6ade4a2](https://github.com/haddowg/json-api-symfony/commit/6ade4a210b106b464996e3b5972de119b3692494))

## [1.12.0](https://github.com/haddowg/json-api-symfony/compare/v1.11.1...v1.12.0) (2026-10-26)


### Features

* add summary and description to custom actions ([9e8543d](https://github.com/haddowg/json-api-symfony/commit/9e8543d38c5097bb26bc84fb8e50d7ad4dfe3b3b))
* let a custom action answer with a raw response body ([#232](https://github.com/haddowg/json-api-symfony/issues/232)) ([7b7005f](https://github.com/haddowg/json-api-symfony/commit/7b7005fa3019513b172abcb96a1e3d4542a12156))
* let a resource instantiate and delete its own entities ([#227](https://github.com/haddowg/json-api-symfony/issues/227)) ([f733c18](https://github.com/haddowg/json-api-symfony/commit/f733c18e7d0dc5c3e52ce282e1b52b622ce4d2e4))
* let a server reject read-only attributes in write bodies ([#230](https://github.com/haddowg/json-api-symfony/issues/230)) ([a03d843](https://github.com/haddowg/json-api-symfony/commit/a03d843f1912d3cef036ed9f4ff963b90dcca290))
* pass UniqueEntity options through and add resource-level entity constraints ([#228](https://github.com/haddowg/json-api-symfony/issues/228)) ([5a680d0](https://github.com/haddowg/json-api-symfony/commit/5a680d0b70bab3244e8d88a995662ff5ca4bc7db))
* require haddowg/json-api 1.11 ([#226](https://github.com/haddowg/json-api-symfony/issues/226)) ([9e661e4](https://github.com/haddowg/json-api-symfony/commit/9e661e4e0b9041e3c4e594a8405950fd6eed95a2))


### Bug Fixes

* bind Doctrine ids through their mapped type ([#223](https://github.com/haddowg/json-api-symfony/issues/223)) ([5287c28](https://github.com/haddowg/json-api-symfony/commit/5287c285d3a350e58ea52116d5825cbc82bc4852))
* require the JSON:API Content-Type on writes and negotiate action bodies ([1544187](https://github.com/haddowg/json-api-symfony/commit/1544187eeb5e4006303e42c0995cc9143229edc4))
* write a relation with fillUsing through its hook ([#225](https://github.com/haddowg/json-api-symfony/issues/225)) ([9668e74](https://github.com/haddowg/json-api-symfony/commit/9668e7412db10d344c4cb87d170e77384a0feda3))

## [1.11.1](https://github.com/haddowg/json-api-symfony/compare/v1.11.0...v1.11.1) (2026-10-25)


### Bug Fixes

* point the stale container error at the cache that needs clearing ([#221](https://github.com/haddowg/json-api-symfony/issues/221)) ([b1a94a8](https://github.com/haddowg/json-api-symfony/commit/b1a94a8e35845683adae27a0e259c83b1677529d))

## [1.11.0](https://github.com/haddowg/json-api-symfony/compare/v1.10.0...v1.11.0) (2026-10-25)


### Features

* refuse to boot a container compiled against other package versions ([#217](https://github.com/haddowg/json-api-symfony/issues/217)) ([acc664d](https://github.com/haddowg/json-api-symfony/commit/acc664d0380ee6c637c11cf1f7f765d1aa245d43))


### Bug Fixes

* key resource security by server and type ([#215](https://github.com/haddowg/json-api-symfony/issues/215)) ([6c4db9c](https://github.com/haddowg/json-api-symfony/commit/6c4db9c5d57d12c614b2197ca694a0c453fb2c66))
* key response headers and action tags by server and type ([#218](https://github.com/haddowg/json-api-symfony/issues/218)) ([ddaebef](https://github.com/haddowg/json-api-symfony/commit/ddaebef750ae7dcab27c69d3424b7aef08c9c5c0))
* key standalone capabilities and OpenAPI declarations by server ([#219](https://github.com/haddowg/json-api-symfony/issues/219)) ([a542b5e](https://github.com/haddowg/json-api-symfony/commit/a542b5e197e11b1c9d492d29ce21ecf167299c49))
* resolve id encoding from each server's resource class ([#220](https://github.com/haddowg/json-api-symfony/issues/220)) ([795d991](https://github.com/haddowg/json-api-symfony/commit/795d991f96ccd294766644c32efee5c5cea22114))

## [1.10.0](https://github.com/haddowg/json-api-symfony/compare/v1.9.2...v1.10.0) (2026-10-25)


### Features

* authorize the relation data core renders and flattened writes ([#204](https://github.com/haddowg/json-api-symfony/issues/204)) ([4b10d60](https://github.com/haddowg/json-api-symfony/commit/4b10d609cffc53f004ec0268e2f07a2e0b47751f))
* let a relation declare its own read permission ([#209](https://github.com/haddowg/json-api-symfony/issues/209)) ([102e38e](https://github.com/haddowg/json-api-symfony/commit/102e38eb1be8ca4f8b53948d366c819652f40d9b))


### Bug Fixes

* answer 404 at the endpoints of a relation hidden from the caller ([#202](https://github.com/haddowg/json-api-symfony/issues/202)) ([2b2982e](https://github.com/haddowg/json-api-symfony/commit/2b2982e63b1809e983de5b24a89fa4e6582476c3))
* apply a relatedQuery to a related endpoint's resources as on a primary one ([#212](https://github.com/haddowg/json-api-symfony/issues/212)) ([876eed6](https://github.com/haddowg/json-api-symfony/commit/876eed6494d7c5578f137211d6af80be0bad9266))
* check the update gate on the changed parent at the relationship endpoints ([#206](https://github.com/haddowg/json-api-symfony/issues/206)) ([7224ab4](https://github.com/haddowg/json-api-symfony/commit/7224ab44a17e60f7c8f23d73e1bc2289ee936cde))
* keep relationship counts to the rows a caller can page through ([#208](https://github.com/haddowg/json-api-symfony/issues/208)) ([1c8156a](https://github.com/haddowg/json-api-symfony/commit/1c8156a683e66a0074090d731d6ed4fb3ed60f43))
* narrow a computed relation's rows to its related type's scope ([#210](https://github.com/haddowg/json-api-symfony/issues/210)) ([25d8c8a](https://github.com/haddowg/json-api-symfony/commit/25d8c8a8ee12dc62ef0acad4394ca5a37e94d360))
* narrow a polymorphic to-many's members per member type to their scope ([#213](https://github.com/haddowg/json-api-symfony/issues/213)) ([07f7f44](https://github.com/haddowg/json-api-symfony/commit/07f7f440298e217020b51b6170b0f15f7dfd8d9e))
* refuse to detach a child whose foreign key is not null ([#207](https://github.com/haddowg/json-api-symfony/issues/207)) ([d6c2b99](https://github.com/haddowg/json-api-symfony/commit/d6c2b99c0e9ea621458607e277e0df969b84c441))
* require core 1.9 so read-only relations refuse relationship writes ([#201](https://github.com/haddowg/json-api-symfony/issues/201)) ([2950f92](https://github.com/haddowg/json-api-symfony/commit/2950f92a8bce1c5bd9e2fd4314b87fa8a7ee4dd4))
* show another type's resources under that type's own read gates ([#205](https://github.com/haddowg/json-api-symfony/issues/205)) ([cbec097](https://github.com/haddowg/json-api-symfony/commit/cbec09703d2ba437f5554a167b79a89ee395fb4c))

## [1.9.2](https://github.com/haddowg/json-api-symfony/compare/v1.9.1...v1.9.2) (2026-10-24)


### Bug Fixes

* apply a relation's mutate gate to relationships set in a write body ([#197](https://github.com/haddowg/json-api-symfony/issues/197)) ([806aac8](https://github.com/haddowg/json-api-symfony/commit/806aac86bdd7784e413451c096872c3322150ab5))
* check the update gate against the stored resource ([#196](https://github.com/haddowg/json-api-symfony/issues/196)) ([cab09e4](https://github.com/haddowg/json-api-symfony/commit/cab09e45c024a6fd0f8cbc0bd002047ca42427f5))
* limit an atomic batch to the types and operations its server exposes ([#200](https://github.com/haddowg/json-api-symfony/issues/200)) ([4b6b790](https://github.com/haddowg/json-api-symfony/commit/4b6b79051ec5c5571d4487c7b0a0d01e791b9f7d))
* **openapi:** only warn about a missing docs route for servers that serve API routes ([#193](https://github.com/haddowg/json-api-symfony/issues/193)) ([a29fcd2](https://github.com/haddowg/json-api-symfony/commit/a29fcd23813e8bfa28a6fdabb42e0a3404c96046))
* reject a relationship identifier whose type the relation doesn't accept ([#199](https://github.com/haddowg/json-api-symfony/issues/199)) ([3793d8c](https://github.com/haddowg/json-api-symfony/commit/3793d8cacb3c72862bf2ebe3062e6c22fa1d17ed))
* route relationship mutations only for types that expose update ([#195](https://github.com/haddowg/json-api-symfony/issues/195)) ([928d358](https://github.com/haddowg/json-api-symfony/commit/928d358a4bd363cf6f43d776602131f9f82c2960))
* scope inverse to-many writes to the members each parent owns ([#198](https://github.com/haddowg/json-api-symfony/issues/198)) ([d0c0cc6](https://github.com/haddowg/json-api-symfony/commit/d0c0cc60d3b8746d55c1bc1fbf071302853f47b9))

## [1.9.1](https://github.com/haddowg/json-api-symfony/compare/v1.9.0...v1.9.1) (2026-10-24)


### Bug Fixes

* **openapi:** find a custom docs route for describedby and warn when none serves the document ([#191](https://github.com/haddowg/json-api-symfony/issues/191)) ([a475da1](https://github.com/haddowg/json-api-symfony/commit/a475da1b7e6055a6803275f3211966839dc4caff))

## [1.9.0](https://github.com/haddowg/json-api-symfony/compare/v1.8.1...v1.9.0) (2026-10-24)


### Features

* **openapi:** default the ReDoc viewer to 3.0.1 with telemetry off ([ab43349](https://github.com/haddowg/json-api-symfony/commit/ab4334954981d3ed3ee5412fae46e048484e0957))
* **openapi:** let a resource declare its singular noun ([ab43349](https://github.com/haddowg/json-api-symfony/commit/ab4334954981d3ed3ee5412fae46e048484e0957))


### Bug Fixes

* **testing:** follow an allOf-wrapped reference when relaxing a fieldset ([ab43349](https://github.com/haddowg/json-api-symfony/commit/ab4334954981d3ed3ee5412fae46e048484e0957))

## [1.8.1](https://github.com/haddowg/json-api-symfony/compare/v1.8.0...v1.8.1) (2026-10-24)


### Bug Fixes

* **example:** reject an empty artist slug ([#186](https://github.com/haddowg/json-api-symfony/issues/186)) ([43b6dd8](https://github.com/haddowg/json-api-symfony/commit/43b6dd876db145d718fa65891d701fd753fe7141))


### Miscellaneous Chores

* **example:** serve publicOwner at a renamed URI segment ([#185](https://github.com/haddowg/json-api-symfony/issues/185)) ([5174069](https://github.com/haddowg/json-api-symfony/commit/5174069aed432afe0abada89a1c12bc37dd7cee5))

## [1.8.0](https://github.com/haddowg/json-api-symfony/compare/v1.7.1...v1.8.0) (2026-10-24)


### Features

* evaluate a pivot field's hidden predicate per related member ([5489928](https://github.com/haddowg/json-api-symfony/commit/5489928dade5c63e7b1cd68573d72577b2d327bc))
* **openapi:** render every readable pivot field to match the response pivot schema ([5489928](https://github.com/haddowg/json-api-symfony/commit/5489928dade5c63e7b1cd68573d72577b2d327bc))
* **routing:** route a relation by its URI field name ([5489928](https://github.com/haddowg/json-api-symfony/commit/5489928dade5c63e7b1cd68573d72577b2d327bc))

## [1.7.1](https://github.com/haddowg/json-api-symfony/compare/v1.7.0...v1.7.1) (2026-10-24)


### Bug Fixes

* leave write-only pivot fields out of responses ([#181](https://github.com/haddowg/json-api-symfony/issues/181)) ([360bbd8](https://github.com/haddowg/json-api-symfony/commit/360bbd871a396a12c07994cbbf34a51dfbeeeb6d))

## [1.7.0](https://github.com/haddowg/json-api-symfony/compare/v1.6.1...v1.7.0) (2026-10-24)


### Features

* **openapi:** declare vendor extensions on a standalone serializer ([#180](https://github.com/haddowg/json-api-symfony/issues/180)) ([17d361d](https://github.com/haddowg/json-api-symfony/commit/17d361dfe9209adbbede0939aff5c17360432115))


### Bug Fixes

* **example:** derive an artist's slug from its name when none is sent ([#178](https://github.com/haddowg/json-api-symfony/issues/178)) ([9eaf298](https://github.com/haddowg/json-api-symfony/commit/9eaf298c8df18e7f30e4b8cb09fc9399dca32751))
* report an empty value once when a required field declares a minimum ([#177](https://github.com/haddowg/json-api-symfony/issues/177)) ([08a6439](https://github.com/haddowg/json-api-symfony/commit/08a64397b58b03d76f134409e4000a6d72767296))

## [1.6.1](https://github.com/haddowg/json-api-symfony/compare/v1.6.0...v1.6.1) (2026-10-24)


### Bug Fixes

* **openapi:** keep the configured Cache-Control when the session was used ([#175](https://github.com/haddowg/json-api-symfony/issues/175)) ([477be10](https://github.com/haddowg/json-api-symfony/commit/477be1005430ce0b96ed6b486e165b0031e53985))

## [1.6.0](https://github.com/haddowg/json-api-symfony/compare/v1.5.1...v1.6.0) (2026-10-24)


### Features

* describe atomic results by their operation and drop the describedby listener ([1919a1f](https://github.com/haddowg/json-api-symfony/commit/1919a1f22b40eadbb82b7dd7163082ccb5d062dd))
* **openapi:** customise the served document per request ([#170](https://github.com/haddowg/json-api-symfony/issues/170)) ([bec7075](https://github.com/haddowg/json-api-symfony/commit/bec707520b338c9f22d155597d673fa2c6271c90))
* **openapi:** serve decorated documents through core's serve helpers ([#174](https://github.com/haddowg/json-api-symfony/issues/174)) ([66204fd](https://github.com/haddowg/json-api-symfony/commit/66204fd7537341b1027bd7127cdaa1ed51135abb))
* point built links at their operation in the served OpenAPI document ([1919a1f](https://github.com/haddowg/json-api-symfony/commit/1919a1f22b40eadbb82b7dd7163082ccb5d062dd))

## [1.5.1](https://github.com/haddowg/json-api-symfony/compare/v1.5.0...v1.5.1) (2026-10-24)


### Bug Fixes

* **example:** keep entity defaults when the persister skips the constructor ([#168](https://github.com/haddowg/json-api-symfony/issues/168)) ([6939152](https://github.com/haddowg/json-api-symfony/commit/69391520a089b37ddfac23b92f00cf36374ce501))
* fail the build when entity: is declared with no entity manager ([#167](https://github.com/haddowg/json-api-symfony/issues/167)) ([a62366f](https://github.com/haddowg/json-api-symfony/commit/a62366faf7caa3ad239388ff8ada931326d96c7c))
* refuse related ids on in-memory models instead of rendering empty identifiers ([#169](https://github.com/haddowg/json-api-symfony/issues/169)) ([85472c8](https://github.com/haddowg/json-api-symfony/commit/85472c81bc2d0f28c40d62626e2e6c27beda4fe5))


### Miscellaneous Chores

* **example:** require a track number on create ([#165](https://github.com/haddowg/json-api-symfony/issues/165)) ([d6d7586](https://github.com/haddowg/json-api-symfony/commit/d6d7586a55187d785e593fd96912d807a40e9cc6))

## [1.5.0](https://github.com/haddowg/json-api-symfony/compare/v1.4.2...v1.5.0) (2026-10-24)


### Features

* leave write-only relations out of reads and the OpenAPI read side ([#163](https://github.com/haddowg/json-api-symfony/issues/163)) ([5c35ee2](https://github.com/haddowg/json-api-symfony/commit/5c35ee285ee93682b9456b668496ed339009dc67))
* **openapi:** declare vendor extensions on #[AsJsonApiResource] ([0634964](https://github.com/haddowg/json-api-symfony/commit/063496400a6a9cf27591759524a2b7bd9bc94409))


### Bug Fixes

* fail the container build when declared resource security can't be enforced ([#164](https://github.com/haddowg/json-api-symfony/issues/164)) ([2eb9d8f](https://github.com/haddowg/json-api-symfony/commit/2eb9d8f921889d55f035918482d85bfb4503a373))
* let a required boolean or number accept false and zero ([#157](https://github.com/haddowg/json-api-symfony/issues/157)) ([f82158e](https://github.com/haddowg/json-api-symfony/commit/f82158ee0859404a6459a05450540adfc38fa400))
* **openapi:** document the paginator a related endpoint serves ([#161](https://github.com/haddowg/json-api-symfony/issues/161)) ([4b53cb2](https://github.com/haddowg/json-api-symfony/commit/4b53cb2ef3f9260bae7b42f819285a410b2accca))
* **testing:** resolve expectResource() by the model's entity class ([#162](https://github.com/haddowg/json-api-symfony/issues/162)) ([722bdc5](https://github.com/haddowg/json-api-symfony/commit/722bdc52834a9d95d93730c3f4f2b3bc87ee3da5))


### Miscellaneous Chores

* **example:** mark server-filled read attributes guaranteed ([#159](https://github.com/haddowg/json-api-symfony/issues/159)) ([e4e2abd](https://github.com/haddowg/json-api-symfony/commit/e4e2abd388d04893924d1062789614efb4df238b))

## [1.4.2](https://github.com/haddowg/json-api-symfony/compare/v1.4.1...v1.4.2) (2026-10-23)


### Bug Fixes

* render loaded includes out of band instead of writing them onto entities ([7684597](https://github.com/haddowg/json-api-symfony/commit/76845972665b5b540368822d57bdf725ec66ef2f))
* resolve written linkage through the related endpoint's scope ([7684597](https://github.com/haddowg/json-api-symfony/commit/76845972665b5b540368822d57bdf725ec66ef2f))
* scope included and linkage data like the related endpoint ([7684597](https://github.com/haddowg/json-api-symfony/commit/76845972665b5b540368822d57bdf725ec66ef2f))
* scope on() hops below the relations a create sets ([7684597](https://github.com/haddowg/json-api-symfony/commit/76845972665b5b540368822d57bdf725ec66ef2f))


### Miscellaneous Chores

* **deps:** require haddowg/json-api ^1.4 ([7684597](https://github.com/haddowg/json-api-symfony/commit/76845972665b5b540368822d57bdf725ec66ef2f))

## [1.4.1](https://github.com/haddowg/json-api-symfony/compare/v1.4.0...v1.4.1) (2026-10-23)


### Bug Fixes

* fold stored empty maps into update validation as arrays ([c5df608](https://github.com/haddowg/json-api-symfony/commit/c5df608dab2fbc3564c970fc3e03909b5fa3c0b2))
* keep empty maps as objects in atomic results ([c5df608](https://github.com/haddowg/json-api-symfony/commit/c5df608dab2fbc3564c970fc3e03909b5fa3c0b2))
* **testing:** fill in schema conformance failure messages ([#151](https://github.com/haddowg/json-api-symfony/issues/151)) ([3065a0a](https://github.com/haddowg/json-api-symfony/commit/3065a0a2862aa1b87f8f8780e386dc8702998bdc))
* validate attribute values against their schema with objects kept ([c5df608](https://github.com/haddowg/json-api-symfony/commit/c5df608dab2fbc3564c970fc3e03909b5fa3c0b2))


### Miscellaneous Chores

* **deps:** require haddowg/json-api ^1.2.1 ([c5df608](https://github.com/haddowg/json-api-symfony/commit/c5df608dab2fbc3564c970fc3e03909b5fa3c0b2))

## [1.4.0](https://github.com/haddowg/json-api-symfony/compare/v1.3.0...v1.4.0) (2026-10-22)


### Features

* add a JSON:API browser accessor that leaves createClient() alone ([f00158b](https://github.com/haddowg/json-api-symfony/commit/f00158bbec1d01d6026313bc556a0064fbf771a2))
* give authentication and authorization errors stable codes ([08420e2](https://github.com/haddowg/json-api-symfony/commit/08420e202466b64dc8e88751dc0e053cd8a0d421))
* render DateTime attributes in a configured output timezone ([08420e2](https://github.com/haddowg/json-api-symfony/commit/08420e202466b64dc8e88751dc0e053cd8a0d421))
* **testing:** validate included resources against their own type's schema ([f00158b](https://github.com/haddowg/json-api-symfony/commit/f00158bbec1d01d6026313bc556a0064fbf771a2))


### Bug Fixes

* give each server its own instance of a shared resource ([08420e2](https://github.com/haddowg/json-api-symfony/commit/08420e202466b64dc8e88751dc0e053cd8a0d421))
* let schema conformance accept sparse-fieldset responses ([f00158b](https://github.com/haddowg/json-api-symfony/commit/f00158bbec1d01d6026313bc556a0064fbf771a2))


### Miscellaneous Chores

* **deps:** require haddowg/json-api ^1.2 ([08420e2](https://github.com/haddowg/json-api-symfony/commit/08420e202466b64dc8e88751dc0e053cd8a0d421))

## [1.3.0](https://github.com/haddowg/json-api-symfony/compare/v1.2.0...v1.3.0) (2026-10-22)


### Features

* expose the matched JSON:API route on the request ([#145](https://github.com/haddowg/json-api-symfony/issues/145)) ([1bc9ace](https://github.com/haddowg/json-api-symfony/commit/1bc9ace132c11ea938451798f9f62cc5cd232432))

## [1.2.0](https://github.com/haddowg/json-api-symfony/compare/v1.1.0...v1.2.0) (2026-10-22)


### Features

* base an empty base_uri on the prefix a server is mounted under ([fed2c3c](https://github.com/haddowg/json-api-symfony/commit/fed2c3cd92a6564e089cfe6940bba1a07cf73b59))
* **openapi:** advertise a mounted server's prefix in servers ([fed2c3c](https://github.com/haddowg/json-api-symfony/commit/fed2c3cd92a6564e089cfe6940bba1a07cf73b59))


### Miscellaneous Chores

* **deps:** require haddowg/json-api ^1.1.1 ([fed2c3c](https://github.com/haddowg/json-api-symfony/commit/fed2c3cd92a6564e089cfe6940bba1a07cf73b59))

## [1.1.0](https://github.com/haddowg/json-api-symfony/compare/v1.0.0...v1.1.0) (2026-10-21)


### Features

* **openapi:** document the application's own error codes ([c6f608e](https://github.com/haddowg/json-api-symfony/commit/c6f608e4a14da7cc3e0765ab9919cf322f0078dd))
* support doctrine/orm 2.20 ([#141](https://github.com/haddowg/json-api-symfony/issues/141)) ([da942c2](https://github.com/haddowg/json-api-symfony/commit/da942c20e416909990288dd4c83a2703cfe615a8))


### Bug Fixes

* **openapi:** register every type a related endpoint returns ([c6f608e](https://github.com/haddowg/json-api-symfony/commit/c6f608e4a14da7cc3e0765ab9919cf322f0078dd))


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
