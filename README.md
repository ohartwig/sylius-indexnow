<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# IndexNow for Sylius

Tells search engines within minutes which products, taxons and homepages of a
Sylius shop were added, changed or removed — through
[IndexNow](https://www.indexnow.org/documentation), which Bing, Yandex, Seznam,
Naver, Yep and Amazon share among themselves. Bing's index is what ChatGPT
search and Copilot read, so a new product can be found there the same day.

For Sylius 2. Built on the framework-agnostic
[koh/indexnow](https://github.com/ohartwig/indexnow).

## Install

```bash
composer require koh/sylius-indexnow
```

```php
// config/bundles.php
Koh\IndexNow\Sylius\KohIndexNowSyliusBundle::class => ['all' => true],
```

```yaml
# config/routes/koh_indexnow.yaml — the key file, at the origin root
koh_indexnow:
    resource: '@KohIndexNowSyliusBundle/config/routes.php'
```

```bash
bin/console doctrine:migrations:migrate   # two small tables, shipped with the bundle
```

Then run the worker every few minutes, from cron or a Kubernetes CronJob:

```bash
bin/console koh:indexnow:run
```

That is the whole setup. Every enabled channel announces on its hostname:

- **The key is derived per host** from `kernel.secret` (HMAC-SHA256). Nothing
  to store, the same on every replica, different per host.
- **The key file** is served at `/<key>.txt` by the bundle's route.
- **The whole catalogue is announced once**, the first time a channel host is
  active — the go-live. A database row remembers it, so redeploys and new
  pods do not announce everything again.

## What never gets announced

Only the **`prod`** kernel environment sends anything; that is not
configurable. Hosts are checked by the library's host policy, which refuses
`.localhost`, `.test`, `.invalid`, `.internal`, IP addresses and hosts with a
`dev`, `staging`, `preview` (and similar) label — `shop.dev.example.com` stays
silent even in `prod`. On a host that does not announce, the key file does not
exist either.

## What gets announced

| Change | Announced |
|---|---|
| A product, its translation, variant, price, image or taxon assignment | The product page in every channel it belongs to and every channel locale it has a slug in, plus its main taxon's listing if that taxon is enabled |
| A taxon or its translation | The taxon page in every channel whose menu tree contains it |
| A product or taxon is deleted | Its URLs, resolved before the deletion, if it was enabled |
| The go-live | Homepages, enabled products and enabled taxons |

Changes come from Doctrine, so the admin, imports, the API and fixtures are all
covered. Disabling an enabled product or taxon is announced — the engine has to
re-crawl to learn it is gone. A product or taxon that was never public is not
announced: one created disabled, as drafts are, stays silent while it is edited
or deleted, and is announced once it is enabled. A translation is required: the
fallback locale's slug under another locale would be a different page.

## Delivery

Changes are queued (`koh_indexnow_queue`) and sent by `koh:indexnow:run`, one
request per host. Accepted rows are removed; 429, 5xx, network errors and 403
(key file not reachable *yet*) wait with exponential backoff from one minute to
six hours, honouring `Retry-After`, and are given up after eight attempts; 400
and 422 are removed and logged, and the command exits with status 1.

HTTP goes through the application's `http_client` with a ten-second timeout, a
twenty-second ceiling and no redirects.

There is deliberately no Messenger message: a queue without a consumer loses
messages, and a synchronous transport would put a search engine's latency
into the admin's save.

## Commands

```bash
bin/console koh:indexnow:status       # per channel host: active or why not, key file URL, queue
bin/console koh:indexnow:run          # the worker
bin/console koh:indexnow:submit-all   # queue the catalogue again, all or the given channels
```

## Configuration

Everything is optional.

```yaml
koh_indexnow:
    enabled: true
    key: ''                    # empty: derived per host from kernel.secret
    channels: []               # channel codes; empty: all enabled channels
    denied_host_suffixes: []   # further hosts never announced
    include_homepage: true
    include_main_taxon: true
    routes:                    # only if the shop replaced Sylius' routes
        product: sylius_shop_product_show
        taxon: sylius_shop_product_index
        homepage: sylius_shop_homepage
```

## Independence

An independent implementation, written from the IndexNow specification.

## Licence

MIT. See [LICENSE](LICENSE).
