# Woo Product Categorizer AI

A WooCommerce extension that categorises a whole catalogue with an LLM. It proposes a category tree
from a sample of the products, lets a person review and edit it, creates the terms, and then assigns
every product to one leaf and its ancestors.

- Text domain / slug: `woo-product-categorizer-ai`
- PHP namespace: `WooProductCategorizerAi\` (PSR-4 → `includes/`)
- Global prefix: `wpcai` / `woo_product_categorizer_ai` — constants, hooks, options and meta keys.
  WPCS rejects prefixes shorter than four characters.

This plugin is a deliberate structural sibling of **woo-kontor-sync-pro**, by the same author and
for the same shop. When a question here has no answer, that plugin's answer is the answer.

## Why this exists

The shop has ~4,400 products and, before this plugin, exactly one product category: *Uncategorized*.
Categorising that by hand is not realistic. Everything below follows from the size of that number —
a run makes roughly 176 requests, so anything that fails a whole run on one bad response will fail
every run.

## Minimum requirements

The plugin deliberately targets the current release of everything. There is no
backwards-compatibility budget: do not add shims, polyfills or version branches for older
WordPress, WooCommerce or PHP.

| Requirement | Floor | Enforced by |
|---|---|---|
| WordPress | 7.0 (current latest) | `Requires at least` header; WordPress blocks activation below it |
| PHP | 8.2 | `Requires PHP` header, `composer.json`, PHPCompatibilityWP `testVersion` |
| WooCommerce | 11.0 (current latest) | `Requires Plugins` header plus a runtime `version_compare` check |

**There is no HPOS gate**, unlike the sibling. This plugin never reads or writes an order, so which
order store the shop runs is none of its business. The `FeaturesUtil::declare_compatibility()` calls
stay, because a plugin that does not declare is listed as incompatible and would push shop owners
away from enabling HPOS for no reason.

**There are no recurring schedules.** Every job is started by hand from the settings screen. None of
the sibling's reconcile-the-queue-against-the-settings machinery exists here, and it should not be
added back — an interval setting for "categorise the catalogue" would be a way to spend money by
accident.

## Environment

Development runs against the same **Local by WP Engine** site as the sibling, *testshop*:

| | |
|---|---|
| Site root | `~/Local Sites/testshop/app/public` |
| URL | http://testshop.local |
| Stack | WordPress 7.0.2, WooCommerce 11.0.0, PHP 8.2.29, MySQL 8.4.0 |
| Plugin path in site | `wp-content/plugins/woo-product-categorizer-ai` → symlink to this repo |
| Catalogue | 4,386 products (2,945 published, 1,441 draft), 61 brands, German/Swiss-German |

**A bare `wp` command does not work.** wp-config.php sets `DB_HOST` to `localhost` and Local serves
MySQL over a socket the Homebrew PHP cannot see. Always use the wrapper:

```bash
./bin/wp plugin list
```

## Commands

```bash
composer lint           # phpcs against the WooCommerce standard
composer lint:fix       # phpcbf — fix what can be fixed automatically
composer test           # PHPUnit against the WordPress test library
composer check          # everything CI checks: validate, version, lint, test
./bin/wp <args>         # wp-cli against the Local site
```

`bin/install-wp-tests.sh` provisions the `woo_categorizer_tests` database once, before the first
`composer test`.

## Coding standards

`phpcs.xml.dist` (ruleset `WooCommerce-Core`) is the authority — when this document and the sniffs
disagree, the sniffs win. A PostToolUse hook runs `phpcbf` then `phpcs` on every PHP file written or
edited in this repo and blocks on remaining violations. If the hook reports that phpcbf reformatted
a file, re-read it before editing again.

- Tabs for indentation, not spaces.
- Yoda conditions: `if ( 'value' === $variable )`.
- Spaces inside parentheses: `function foo( $bar )`, `if ( $baz )`, `array( 'key' => 'value' )`.
- `snake_case` for functions and variables, `PascalCase` for classes, `UPPER_SNAKE_CASE` for constants.
- Every file, class, method and property carries a docblock. Inline comments are full sentences
  ending in a period.
- Every user-facing string is translated with the `woo-product-categorizer-ai` text domain, and uses
  `printf`-style placeholders rather than concatenation.
- Class files follow PSR-4 (`includes/Provider/OpenAiProvider.php`), not WordPress's `class-*.php`
  convention — the ruleset already excludes `includes/` from `WordPress.Files.FileName`.
- **Comment the reasoning, not the code.** Where a decision is not obvious, say which failure mode it
  prevents. That is the dominant habit in both this plugin and the sibling, and it is what makes them
  editable a year later.

## Security — not negotiable

- **Sanitise on input**: `sanitize_text_field()`, `absint()`, `sanitize_textarea_field()`,
  `wp_unslash()` on every `$_POST` / `$_GET` value. Never read `$_REQUEST`.
- **Escape on output**: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()` — at the point of
  output, not at assignment.
- **Authorise every state change**: `check_admin_referer()` / `check_ajax_referer()` *and*
  `current_user_can()`. A nonce alone is not authorisation.
- **Never log credentials, and never log a request body.** The key is a credential; the body carries
  the whole catalogue.
- **Never commit a real credential**, including as a test fixture. Fixtures must be synthetic values
  that reproduce the *shape* of the real thing — mixed case, punctuation, non-ASCII, a percent
  octet — and nothing more.
- **Redirect with a code, never a message.** `admin_post` handlers append something like
  `wpcai_notice=draft_saved`, and the screen looks it up in a map. Nothing user-supplied is echoed.

## WooCommerce specifics

- Product queries go through `WP_Query`/`wc_get_products()`, term writes through
  `wp_set_object_terms()` and `wp_insert_term()`.
- Custom meta uses the `_wpcai_` prefix so it stays out of the visible custom fields UI.
- **`wp_defer_term_counting( true )` around any batch of term writes.** Every
  `wp_set_object_terms()` on a hierarchical taxonomy walks the ancestors to recount them; across
  4,386 products that is the single largest avoidable cost in the plugin. Defer per batch, not per
  run, so a crashed action does not leave counting suspended site-wide.
- **Never call `wp_set_object_terms( $id, array(), 'product_cat' )`.** WooCommerce re-adds the
  default category on the next save, so an empty write is both pointless and misleading. If there is
  nothing to write, leave the product alone.

## The provider layer

Everything above `Provider\` speaks in `instructions` / `input` / `schema` / `effort` and never in a
vendor's field names. OpenAI ships first; a second provider is a new class plus one line in
`Providers::all()`.

### Facts established by probing the live OpenAI API

These are not guesses, and getting any of them wrong produces silently wrong behaviour rather than an
error:

- Use the **Responses API** (`POST /v1/responses`), not Chat Completions. Structured output is
  `text.format = { type: 'json_schema', name, strict: true, schema }`.
- **A truncated response arrives as HTTP 200 with `status: "incomplete"`**, not as an error. Every
  status and transport check passes, and the code then looks for a message element that is not
  there. Check `status` before touching `output`.
- The answer is `output[]`'s first element with `type === 'message'`, then `content[0].text`, which
  is a JSON *string* that still has to be decoded.
- **Retrying a truncated request unchanged is guaranteed to truncate again.** That is the one case
  where a retry legitimately changes the request: `max_output_tokens` grows by 1.5× each attempt.
- **Prompt caching is the cost lever, and it is fragile.** Measured: 2,816 of 3,164 input tokens came
  back cached on a repeat call. It only works while the *prefix* is byte-identical, so the taxonomy
  lives in `instructions`, is rendered once at the start of a run and frozen in the run-options
  transient. Rendering it per batch would still work and would silently cost the cache — which is why
  `ProviderTest` asserts on the request body.
- **A JSON-schema `enum` constrains the shape but not the judgement.** Observed: the model returned
  a valid category id for the wrong product. The enum is a cost and accuracy optimisation. Server-side
  validation of every returned id runs unconditionally, whether or not the enum was sent.
- `reasoning.effort` of `low` suits assignment; the tree proposal needs `medium` and was measured at
  **65 seconds and 8,199 reasoning tokens**. That is why the proposal is a background job with its
  own action, and why `REQUEST_TIMEOUT` is 120.
- `GET /v1/models` returns the whole account catalogue — audio, image, embedding, realtime, codex —
  so the model picker has to filter it.

## The category tree

- **The draft is stored flat**, one row per node carrying its own opaque key and its parent's key.
  Nested data cannot round-trip through `$_POST` without either an unreviewable JSON blob or index
  arithmetic that breaks when a row is deleted. Keys are minted server-side and never derived from
  the name, so a rename does not orphan the children.
- **The parent always comes from the stored draft, never from the POST.** Re-parenting is not
  offered.
- **Slugs are derived from the full path**, not the name. The model genuinely reuses leaf names
  across branches — "Deko" appeared under three different parents in testing. WooCommerce permits
  same-name terms under different parents, but slugs are global: `wp_insert_term()` silently appends
  `-2`, and which term gets the suffix depends on insertion order, so a re-run reshuffles them.
  `Wohnen › Deko` becomes `wohnen-deko`.
- **A rename never changes a slug.** Same rule as the sibling's brand terms: a stale slug beside a
  new name works perfectly well, and changing it breaks every URL already pointing at the archive.
- **Terms are never deleted.** Not by a draft that dropped them, not by uninstall. They may hold
  products or have been curated by hand.

## Jobs

Three job keys — `taxonomy`, `assign`, `revert` — over ten Action Scheduler hooks, all in the
`woo-product-categorizer-ai` group.

- **A batch failing must not fail the run.** Only conditions certain to recur take a run down: a
  rejected key, a model the account cannot use, a missing taxonomy. Everything else costs one batch.
- **Every chained action carries its run id** and returns early when it is not the current one. An
  action already executing cannot be cancelled and queues its own successor, so without the fence a
  superseded run keeps walking the catalogue underneath a newer one.
- **Paging is keyset, not offset.** `AND ID > %d`, ordered by ID. An offset walk over 4,386 rows both
  degrades and silently skips a product whenever one is published mid-run.
- **The run's options are frozen in a transient at the start.** Saving the settings mid-run then
  cannot change the rules half way through, and the instructions string stays byte-identical.
- **Whoever cancels the work owns closing the status behind it.** `Deactivator` calls
  `Status::abandon()` *before* `Scheduler::unschedule_all()`, because cancelling the queue destroys
  the chain that would have reported the outcome.

## Dependency versions — do not "upgrade" these

Carried over from the sibling, where both pins were established by trying the newer version and
watching it break.

- **PHP_CodeSniffer stays on 3.x.** 4.0 is released, but `wp-coding-standards/wpcs` 3.4.1 requires
  `^3.13.5`. Requiring `woocommerce/woocommerce-sniffs ^2.0` resolves the whole standards stack
  correctly; do not pin PHPCS directly.
- **PHPUnit stays on 9.x.** WordPress's own test library calls
  `PHPUnit\Util\Test::parseTestMethodAnnotations()` and `$this->getName( false )`, both removed in
  PHPUnit 10. On 11.x every test errors out before it runs. This is a WordPress constraint, not a
  polyfills one.
- `config.platform.php` is pinned to `8.2.29` so Composer resolves for the site's runtime rather
  than the host's PHP 8.5.
