# Examples

Runnable examples for `kyzegs/guzzle-rate-limit-middleware`.

| File | Shows |
|------|-------|
| [`basic.php`](basic.php) | Minimal setup and a per-API preset. |
| [`cross_process.php`](cross_process.php) | Persisting state across separate processes with `FilesystemStore`. |
| [`custom_handler.php`](custom_handler.php) | Wrapping the middleware with a custom `HandlerInterface` (metrics). |

Run any of them with `php examples/<file>.php` (they use Guzzle's `MockHandler`, so no real network calls are made).
