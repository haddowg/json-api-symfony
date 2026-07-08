# Localizable error catalogue via a translator-backed message resolver

Core made every error's `title`/`detail` a template resolvable per stable `code`
(core ADR 0128). The bundle binds that seam to the Symfony translator: a
`TranslatorErrorMessageResolver` looks up `<CODE>.title` / `<CODE>.detail` in the
`jsonapi_errors` translation domain, returns the (localized) template or `null` when
the key is absent, and the `ServerFactory` threads it onto every server's `Server`.

It is registered only when the concrete `symfony/translation` component is installed
(gated on the `Translator` class, not merely the always-present contracts, so the
`translator` service it depends on actually exists); without it the seam resolves
`null` and core renders its inline English copy, byte-identical to today. No
parameters are passed to `trans()`, so a translated template keeps its `{placeholder}`
tokens for core to interpolate from the error's context — locale negotiation stays the
framework's job. Because core applies the resolver uniformly to every rendered error,
the validator's `VALIDATION_FAILED` `422` title localizes through the same path.
