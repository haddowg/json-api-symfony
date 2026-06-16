"""MkDocs build hooks for the published documentation site.

Two rewrites at build time, leaving the GitHub-friendly source Markdown untouched:

1. The docs link into this repository with relative paths (``../examples/…``,
   ``../src/…``); those targets are not served, so rewrite them to GitHub ``blob``
   URLs.
2. The docs link to the core library's documentation with absolute GitHub ``blob``
   URLs (``…/json-api/blob/main/docs/<page>.md``); rewrite those to the core
   documentation site so a reader stays in rendered docs. Links to core *source*
   (``…/blob/main/src|examples/…``) are left as GitHub links.
"""

import re

_BUNDLE_BLOB = "https://github.com/haddowg/json-api-symfony/blob/main/"
_CORE_DOCS = "https://github.com/haddowg/json-api/blob/main/docs/"
_CORE_PAGES = "https://haddowg.github.io/json-api/"

# ](../…/<repo path outside docs/>)  ->  bundle GitHub blob URL
_REPO_LINK = re.compile(
    r"\]\((?:\.\./)+((?:examples|src|tests|config|composer|phpunit|LICENSE|CONTRIBUTING)[^)]*)\)"
)

# …/json-api/blob/main/docs/<page>.md[#anchor]  ->  the core documentation site
_CORE_DOC = re.compile(re.escape(_CORE_DOCS) + r"([a-z0-9-]+)\.md(#[^)\s]*)?")


def _core_doc(match: "re.Match[str]") -> str:
    page, anchor = match.group(1), match.group(2) or ""
    # MkDocs renders index.md at the site root, not /index/.
    path = "" if page == "index" else f"{page}/"
    return f"{_CORE_PAGES}{path}{anchor}"


def on_page_markdown(markdown, **kwargs):
    markdown = _REPO_LINK.sub(rf"]({_BUNDLE_BLOB}\1)", markdown)
    markdown = _CORE_DOC.sub(_core_doc, markdown)
    return markdown
