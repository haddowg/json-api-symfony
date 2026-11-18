# The CRUD handler fills a document's declared meta, and debug mode checks it

Core lets a type declare each operation's top-level meta and types it in the generated document. The handler fills the members their extractUsing() hooks supply after its After hooks have run, so a value a hook sets with withMeta() wins, and only debug mode checks the rendered meta in the view listener, so a mismatch fails loudly in development and costs nothing in production. A #[AsJsonApiAction] cannot declare its response meta, since attribute arguments cannot hold field builders.
