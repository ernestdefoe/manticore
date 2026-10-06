# Working in this repo

`ernestdefoe/manticore`: a free (MIT) Manticore Search driver for Flarum 2. It was
asked for on discuss.flarum.org as a lighter search engine for small servers, so
**keeping it light is the whole point.**

## Standing rules (cloud sessions do not load memory, so they live here)

- **Nothing ships unchecked.** Before any release of something new or big, run the
  full ship-check: N+1 (1 vs 20 items, guest AND admin), lean pass (forum.js gz size,
  forum.css, forum attributes), security audit (semgrep, gitleaks, npm audit, manual
  review of every route and query), then the all-enabled check on
  dev.ernestdefoe.online. Fix what it finds, one `fix:` commit per fix.
- **Lightweight and right the first time.** Same function with less code, never at
  the cost of correctness. Measure, don't assert. Add no load to other people's
  sites: short timeouts, no per-item queries, failures cached, nothing sent until a
  host is configured.
- **Raw SQL must be prefix-safe.** Use the query builder, or wrap columns with
  `getGrammar()->wrap()`. A bare `discussions.id` in `orderByRaw` breaks every forum
  that has a table prefix.
- **Manticore SQL:** table names come only from `Manticore::table()` (`[a-z0-9_]`).
  User text goes only through `Manticore::terms()` (letters, numbers and marks only,
  lowercased) and `quote()`. Ids are cast to int. Never interpolate anything else.
- **Results never leak.** Manticore only ranks ids. The searcher's `getQuery()`
  (`whereVisibleTo`) decides visibility, and `mostRelevantPost` is filtered through
  `Post::whereVisibleTo`.
- **Everything is translatable.** No hardcoded English in the UI; strings live in
  `locale/en.yml`.
- **Secrets are never serialized to the forum.** The forum attribute is only the
  list of resources this driver answers.
- **Conventional commit subjects** (`feat:`, `fix:`, `docs:`, `chore:`; `feat!:` or
  `BREAKING CHANGE` for a break). `.github/workflows/draft-release.yml` picks the
  version bump from the SUBJECT line. End every commit message with
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Never open a pull request.** Commit to `main` and push.
- **Rebuild `js/dist` before committing** (`cd js && npm ci && npm run build`).
  dist is committed and is what Flarum loads.

## Traps already hit

- Core observes the exact model class an indexer is registered for. A reply is a
  `CommentPost`, so `PostIndexer` is registered under `CommentPost` as well as `Post`.
  Under `Post` alone, no new post, edit or delete ever reached the index.
- `Extend\ServiceProvider` takes the class through `->register()`. A constructor
  argument is silently ignored.
- Mirroring core's search filters and mutators happens in the provider's `boot()`.
  At `register()` time it missed filters that extensions enabled later add.
- Flarum 2 has no `forum_url` setting. The default table prefix comes from
  `Config::url()`.
- Never put a wildcard (`term*`) inside an `OPTION fuzzy=1` query. Manticore's Buddy
  returned almost nothing for it. Fuzzy uses plain terms; the prefix form is only the
  fallback for servers without fuzzy.
- On a forum with a real queue (Horizon, Redis), indexing happens in the worker.
  Restart the workers after changing code (`php flarum horizon:terminate`), or they
  keep running the old classes.

## Testing on dev

dev.ernestdefoe.online: container `devflarum_app` on root@103.195.100.103, table
prefix `dev_`. Run php, composer and flarum as www-data, never as root. Never touch
`flarum_app` or `fbsfb2_app`.

## Releasing

Pushing to `main` drafts a release; **a human publishes it**. Cloud sessions cannot
tag or publish (403 by session type), so push and confirm the draft. Never publish,
register on Packagist or announce from a session unless Ernest asks.
