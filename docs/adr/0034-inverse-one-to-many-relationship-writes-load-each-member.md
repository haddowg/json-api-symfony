# Inverse one-to-many relationship writes load each member

Re-pointing the children of an inverse one-to-many (the foreign key on the child) sets each child's owning association, which initialises its proxy, so such a write costs one `SELECT` per linkage id. That cost is accepted because re-pointing through the ORM keeps the unit of work, the inverse-collection sync and the children's lifecycle and cascade events, all of which a bulk `UPDATE … WHERE id IN (…)` would bypass. An application needing O(1) bulk re-pointing writes a custom persister.
