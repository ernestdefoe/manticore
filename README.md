# Manticore Search for Flarum

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE.md)

## The problem

Flarum's built-in search runs on your database. It misses typos, and MySQL's full-text
mode drops common words. Elasticsearch, OpenSearch and Typesense fix that, but they
need a lot of memory, which is a problem on a small VPS or shared server.

## What it does

A free search driver for **Flarum 2**, backed by [Manticore Search](https://manticoresearch.com),
the maintained successor to Sphinx. Measured on a test forum, the official container
used about 30 MB at start and about 100 MB once typo-tolerant searches were running
(fuzzy search is handled by Manticore's small Buddy helper).

- **Typo tolerance.** `meetp` finds *meetup*. This uses Manticore's fuzzy search (7.0
  and later). On older servers it falls back to prefix matching.
- **Discussions, posts and users**, each switched on separately.
- **Flarum still decides who sees what.** Manticore only returns ids in ranked order.
  Flarum loads the results through its usual visibility rules, so private discussions,
  hidden or unapproved posts and restricted tags stay hidden, and tag filters still work.
- **Light on the forum.** It talks to Manticore's HTTP API through the Guzzle client
  Flarum already ships, so it needs no `pdo_mysql` and runs on MySQL, PostgreSQL and
  SQLite forums alike. A search adds one database query, however many results come
  back. Text is indexed but not stored, so Manticore keeps no second copy of your posts.
  A reply writes one small row and never re-reads its thread.
- **Fails safe.** Every call has a short timeout. If Manticore can't be reached, search
  falls back to Flarum's database search, and the outage is remembered for 30 seconds so
  later pages don't wait on it. A failed index write is logged and never stops anyone
  posting.

## Installation

```bash
composer require ernestdefoe/manticore
```

Enable **Manticore Search** in the admin panel.

### Run Manticore

The smallest setup is one container. Keep port 9308 private, because Manticore's HTTP
API has no authentication of its own:

```bash
docker run -d --name manticore --restart unless-stopped \
  --memory 256m -p 127.0.0.1:9308:9308 \
  -v manticore-data:/var/lib/manticore \
  manticoresearch/manticore
```

If your forum runs in Docker, attach Manticore to the forum's network instead of
publishing a port, and use the container name as the host.

### Set up

1. **Admin → Manticore Search.** Enter the host (and the port if it isn't 9308), then save.
2. Click **Test connection**.
3. Click **Rebuild index**, or run `php flarum manticore:index`.
4. Choose which searches Manticore should answer, then save.

The **table prefix** keeps two forums on one Manticore separate. If you leave it blank,
it is taken from your forum's host name. **Username** and **password** are only needed
when Manticore sits behind a proxy that uses HTTP basic authentication. The password
stays on the server.

Search tabs answered by Manticore show a small *Powered by Manticore Search* note. The
browser only learns which tabs use Manticore; it never sees connection details.

### Console

```bash
php flarum manticore:index          # build or refresh every table
php flarum manticore:index --flush  # drop the tables
```

On a large forum, run a queue worker so indexing and rebuilds happen in the background.

## How it indexes

| Table | Holds | Used by |
| --- | --- | --- |
| `<prefix>_discussions` | title | discussion search |
| `<prefix>_posts` | comment text and `discussion_id` | post search, and discussion search grouped by discussion |
| `<prefix>_users` | username and display name (never email) | user search |

New, edited, hidden, restored and deleted content updates the index automatically.
Hidden posts and discussions are removed from the index, the same way Flarum's search
index pipeline treats them.

## Updating

```bash
composer require ernestdefoe/manticore:^0.1
php flarum cache:clear
```

## Licence

[MIT](./LICENSE.md) © Ernest Defoe
