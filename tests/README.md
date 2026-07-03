# Post Runtime Engine — Tests

PHPUnit test infrastructure modeled on Form Runtime Engine's `tests/` layout.
Established 2026-05 as the first deliverable of `POST_RUNTIME_AUDIT.md`
Critical #1 (test scaffolding).

## Quick start

From the plugin root:

```bash
# One-time install
composer install

# Run all unit tests
composer test
# or directly:
./vendor/bin/phpunit --testsuite Unit

# With coverage report (text)
composer test:coverage
```

PHP 7.4+ required (matches the plugin minimum).

## What's tested

| Test class | Covers | Why |
|---|---|---|
| `Unit/ValidatorTest.php` | `PCPTPages_Validator` constants + CPT/grouping/item validation | The contract surface — every `critical_rules` entry in CONNECTOR_SPEC.md should have a test below. |
| `Unit/CPTRegistryTest.php` | `PCPTPages_CPT_Registry` CRUD + validation rejection + connector-version stamping | The data layer for CPT definitions; round-trip + edge cases. |
| `Unit/GroupingRegistryTest.php` | `PCPTPages_Grouping_Registry` per-CPT scoping + define/remove | The architectural decision that groupings are per-CPT depends on this not regressing. |
| `Unit/PostDataTest.php` | `PCPTPages_Post_Data` read-modify-write semantics | `update_grouping` MUST NOT touch other groupings — that's the contract behind the connector's `set_post_groupings` vs `update_post` distinction. |

Coverage as of initial scaffold: ~25% on the data layer. Target before v1.0
ship: **>80%** per `CLAUDE.md` post-launch maintenance constraints.

## What's NOT tested yet

These are explicit gaps the next test-writing pass should fill:

- `PCPTPages_Source_Resolver` (manual / child_posts / taxonomy_match resolution)
- `PCPTPages_Renderer` and the four layout-variant outputs
- `PCPTPages_Connector_API` — most-impactful next addition; the connector is the
  external surface and its responses need pinning down. Look at FRE's
  `tests/Unit/ConnectorPreflightTest.php` as the model.
- `PCPTPages_Capabilities` — capability grant/revoke on activation/uninstall.
- `PCPTPages_Icon_Library` — icon catalogue integrity (no dupes, all IDs sanitized).

## How tests are organized

```
tests/
├── README.md                       # this file
├── bootstrap.php                   # PHPUnit bootstrap — defines plugin
│                                     constants, loads the autoloader, and
│                                     pulls in Brain\Monkey for WP function
│                                     mocking.
├── kitchen-sink.php                # Pre-existing visual fixture for design
│                                     QA. NOT a unit test.
├── smoke-phase1.php                # Pre-existing CLI smoke tests. NOT
├── smoke-phase3.php                #   discovered by PHPUnit.
└── Unit/                           # PHPUnit-discovered test classes.
    ├── UnitTestCase.php            # Base class with WP function mocks +
    │                                 in-memory option / post-meta /
    │                                 transient stores. Inherit from this
    │                                 in every new unit test.
    ├── Mocks/
    │   └── WP_Error.php            # Minimal WP_Error stand-in for unit
    │                                 tests that don't load WordPress.
    ├── ValidatorTest.php
    ├── CPTRegistryTest.php
    ├── GroupingRegistryTest.php
    └── PostDataTest.php
```

The `kitchen-sink.php` and `smoke-phase*.php` files are the pre-existing
manual-QA scripts. They're not discovered by PHPUnit (no `Test.php` suffix)
and they require a real WordPress runtime, so they keep working alongside
the new unit tests without conflict.

## Writing a new test

1. Create `tests/Unit/MyClassTest.php` with namespace `PRE\Tests\Unit`.
2. Extend `UnitTestCase` (`extends UnitTestCase`).
3. In `set_up()`, call `parent::set_up()` first, then `require_once` the
   class file under test and instantiate it.
4. The base class provides `$this->options`, `$this->post_meta`, and
   `$this->transients` as reset-per-test in-memory stores. Mocked
   `get_option` / `update_option` / `get_post_meta` / `update_post_meta`
   read and write these directly.
5. Use `Brain\Monkey\Functions\when()` to add per-test WP function mocks
   for anything the base doesn't cover (e.g. `wp_insert_post`,
   `register_post_type`, `get_terms`). Use `Functions\expect()` if you
   need to assert that a function was called.

## Why Brain\Monkey + Yoast Polyfills (matches FRE)

- **Brain\Monkey** mocks WordPress functions without requiring a real WP
  install. Unit tests run in milliseconds and don't need a database.
- **Yoast PHPUnit Polyfills** smooths over PHPUnit version differences so
  the same tests work on PHP 7.4 + PHPUnit 9 and on PHP 8.x + PHPUnit 10+.
- Same toolchain as FRE, so contributors who know one plugin's tests can
  immediately work on the other.

## Future work

- **Integration tests** (Phase 0b per the audit doc): wire WP test
  framework via `bin/install-wp-tests.sh` (copy from FRE), add
  `tests/Integration/` directory. Tests that need real WP behavior
  (template loading, post type registration, taxonomy queries) live here.
- **CI:** add a GitHub Actions workflow that runs `composer test` on every
  PR. Block merging on test failure.
- **Coverage gate:** once coverage crosses 60%, add a coverage check to
  the CI workflow that fails if coverage drops below the previous run.
