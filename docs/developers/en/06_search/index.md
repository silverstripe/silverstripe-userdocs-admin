---
title: Search
summary: How the documentation search index is built, stored and configured
---

# Search

The search index is built with [TNTSearch](https://github.com/teamtnt/tntsearch). By default, the index is stored in SQLite files in the `userdocs-admin/indexes/` directory inside your temporary folder. To store it somewhere else, see [index storage](./index_storage).

## Search index building

The search index is built the first time someone opens the search panel or searches. It is built separately for each locale, the first time a user of that locale searches. Building can take a few seconds for a large set of documentation, so the first search can be slow.

Flushing clears the search index, and it is built again on the next search. The index is not updated when documentation content changes, so flush after you deploy documentation changes to make them searchable.

The list of built indexes is stored in the `Psr\SimpleCache\CacheInterface.userdocssearch` cache. With the default SQLite storage, the index files are stored in each server's temporary folder. If your site runs on more than one server, each server builds its own index the first time it is searched, even if the `userdocssearch` cache is shared between servers. To build the index only once, store it in [Redis or MySQL](./index_storage) so all servers share it.

### Building the index before the first search

To build the search index before anyone searches, for example as part of your deployment, run the `userdocs-search-index` build task:

```bash
vendor/bin/sake tasks:userdocs-search-index
```

The task builds the index for every locale that has documentation. It clears the existing index first. Use the `--no-clear` option to keep the existing index and only build the indexes that are missing, for example on each server of a site that uses the default SQLite storage.

> [!WARNING]
> With the default SQLite storage, the index and the `userdocssearch` cache are stored in the temporary folder, which can be different for each system user. Run the task as the same user as your web server, otherwise the website doesn't use the index that the task builds.

[CHILDREN]
