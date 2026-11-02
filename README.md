# Codeva Watch client

`codeva/laravel-watch` is the Laravel client for Codeva Watch. It records requests, queries, jobs, commands, scheduled tasks, cache events, outgoing requests, mail, notifications, logs and exceptions, and ships them through a local agent to your Watch server. It also serves a health endpoint and sends scheduler heartbeats.

Requires PHP 8.3+ and Laravel 12 or 13.

## Install

Until the package is on Packagist, add its GitHub repository to the app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/codeva-oy/laravel-watch" }
]
```

```bash
composer require codeva/laravel-watch
php artisan watch:install --token=cw_your_project_token --url=https://watch.example.com
```

`watch:install` publishes `config/watch.php` and writes `WATCH_TOKEN`, `WATCH_SERVER_URL`, a generated `WATCH_HEALTH_SECRET`, and `LOG_STACK` with `watch` appended. It keeps values that are already set, and asks for the token and URL when they are not given. Logs reach Watch through the stack, so `LOG_CHANNEL` must be `stack`.

Then run the agent for your platform, below, and check the whole path:

```bash
php artisan watch:test    # sends a test exception and waits for the agent to reach the server
php artisan watch:status  # agent buffer, spool and last successful send
php artisan watch:sync-schedule  # run on every deploy, see Heartbeats
```

## Running the agent

The app hands records to one agent process per app, over TCP. If the agent is down the app skips sending for 30 seconds and carries on; if the Watch server is down the agent spools to `storage/watch-spool` and retries.

### Forge, Ploi or any Linux VM

Run one agent per app on each app server. If several apps share a server, give each its own port with `WATCH_AGENT_ADDRESS` and `WATCH_AGENT_LISTEN`. The default for both is `127.0.0.1:2408`, so a single app needs no setting.

On Forge or Ploi, add a daemon with the command `php artisan watch:agent` and your site as its directory.

Supervisor, as `/etc/supervisor/conf.d/watch-agent.conf`:

```ini
[program:watch-agent]
command=php /home/forge/example.com/artisan watch:agent
directory=/home/forge/example.com
user=forge
autostart=true
autorestart=true
startsecs=3
stopsignal=TERM
stopwaitsecs=15
stdout_logfile=/var/log/watch-agent.log
redirect_stderr=true
```

systemd, as `/etc/systemd/system/watch-agent.service`:

```ini
[Unit]
Description=Codeva Watch agent
After=network.target

[Service]
User=forge
WorkingDirectory=/home/forge/example.com
ExecStart=/usr/bin/php artisan watch:agent
Restart=always
RestartSec=3
KillSignal=SIGTERM
TimeoutStopSec=15

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now watch-agent
```

On SIGTERM the agent writes what it holds to the spool, and sends it after restarting. It also stops on `php artisan queue:restart` (within 5 seconds), so a deploy script that already restarts queues puts the agent on the new release too; its process manager starts it again. An older agent keeps working with a newer app, as the wire protocol is versioned.

### Laravel Cloud

1. Open the environment and add a **Custom worker** background process to **every** App and Worker cluster.
2. Set its command to `php artisan watch:agent`.
3. Add `WATCH_TOKEN`, `WATCH_SERVER_URL` and `WATCH_HEALTH_SECRET` to the environment variables and deploy.

No address setting is needed: the process shares `127.0.0.1` with the app. Deploy ids are picked up from `LARAVEL_CLOUD_DEPLOY_UUID`.

### Laravel Vapor

Lambda cannot host a long-running process, so run the agent on a small always-on VM, such as a Forge worker server or a t3.micro, in the same region and the same Vapor network. Run one agent per Vapor app.

1. Put a release of the app on the VM, with `.env` holding `WATCH_TOKEN` and `WATCH_SERVER_URL`, and set the agent to listen on the private address:

   ```env
   WATCH_AGENT_LISTEN=0.0.0.0:2408
   ```

2. Run `php artisan watch:agent` under Supervisor or systemd as above.
3. Allow port 2408 from the Vapor network range only, for example `10.0.0.0/21`. Bound to anything other than loopback, the agent requires the full token HMAC on every frame and logs a warning at startup.
4. Attach the app to that network in `vapor.yml` and point it at the VM:

   ```yaml
   environments:
       production:
           network: vapor-network-123
   ```

   ```env
   WATCH_AGENT_ADDRESS=10.0.1.20:2408
   ```

Deploys are picked up from `VAPOR_COMMIT_HASH`, and `server` reports the Lambda function. Jobs flush per job in `vapor:work`.

### Docker and Kubernetes

Run a sidecar from the same image, with `php artisan watch:agent` as its command:

```yaml
services:
    app:
        image: example/app
        environment:
            WATCH_TOKEN: ${WATCH_TOKEN}
            WATCH_SERVER_URL: ${WATCH_SERVER_URL}
            WATCH_AGENT_ADDRESS: watch-agent:2408

    watch-agent:
        image: example/app
        command: php artisan watch:agent
        environment:
            WATCH_TOKEN: ${WATCH_TOKEN}
            WATCH_SERVER_URL: ${WATCH_SERVER_URL}
            WATCH_AGENT_LISTEN: 0.0.0.0:2408
        stop_grace_period: 15s
        volumes:
            - watch-spool:/var/www/html/storage/watch-spool

volumes:
    watch-spool:
```

In Kubernetes, add a second container to the pod with the same command and `WATCH_AGENT_LISTEN=127.0.0.1:2408`; the containers share `localhost`, so the app needs no address setting. Give the spool a volume if it has to survive a pod restart.

### Octane

Run the agent as for the host above. Nothing else is needed: records flush on `RequestTerminated`.

### No reachable agent

For shared hosting or a quick trial, post directly to the server:

```env
WATCH_TRANSPORT=http
```

Batches are gzipped and posted after the response is sent, with a 1 second timeout and a circuit breaker. There is no spool, so anything sent while the server is down is lost. To go through the queue instead, as Vapor does by default, set `WATCH_HTTP_QUEUE=true`; exceptions in CLI processes are still sent directly.

To keep the agent but use http when it is down, set `WATCH_FALLBACK_HTTP=true` and leave the transport on `agent`.

## Health endpoint

`GET /watch/health` returns the JSON that Oh Dear and spatie/laravel-health use. It answers 404 unless the request has `X-Watch-Health-Secret` set to `WATCH_HEALTH_SECRET`.

If `spatie/laravel-health` is installed with checks registered, those run. Otherwise it runs built-in checks for the database, cache, queue size, failed jobs, disk space, debug mode and the scheduler. Thresholds are in the `health` section of `config/watch.php`.

## Heartbeats

Every scheduled task sends queued started, finished and failed pings, so a queue worker has to be running. A slow Watch server never delays the scheduler. With the `sync` queue driver a ping runs in the scheduler process instead, with a 5 second timeout.

Run this on every deploy, so the server knows what to expect and when a run is missing:

```bash
php artisan watch:sync-schedule
```

Tasks get 5 minutes of grace (`WATCH_SCHEDULE_GRACE`). To set it per task:

```php
Schedule::command('reports:send')->daily()->watchGrace(30);
```

## Sampling

Requests, commands, jobs, scheduled tasks and exceptions each have a rate, 1.0 by default: `WATCH_SAMPLE_REQUESTS`, `_COMMANDS`, `_JOBS`, `_SCHEDULED_TASKS` and `_EXCEPTIONS`. Records carry the rate they were sampled at. An exception in an unsampled request still sends the exception and a minimal request record.

Override it per route or at runtime:

```php
use Codeva\Watch\Http\Middleware\Sample;

Route::get('/health', HealthController::class)->middleware(Sample::never());
Route::post('/checkout', CheckoutController::class)->middleware(Sample::always());
Route::get('/feed', FeedController::class)->middleware(Sample::rate(0.1));

Watch::sample(0.5);
```

Jobs follow whoever dispatched them, then `WATCH_SAMPLE_JOBS` on top.

## Grouping exceptions

Exceptions group by class, first application frame and message, with numbers, UUIDs, hex ids, emails and quoted strings normalised. To override it for a class and its subclasses:

```php
Watch::groupUsing(PaymentFailed::class, fn (PaymentFailed $e) => $e->gateway);
```

Return `null` to use the default.

## Privacy

Nothing identifying leaves the app by default. Every record passes through one gate, `Codeva\Watch\Transport\RecordShaper`, before it is buffered:

- No IP addresses, headers, cookies, sessions, request bodies, mail subjects or addresses, or log context values. Logs send context keys only.
- No query bindings, including in the message of a `QueryException`.
- User ids are hashed with your token; guests are empty.
- Cache keys are hashed. Request URLs lose their query string, and outgoing requests send only host and a path with ids replaced.
- Traces hold at most 50 frames, without arguments. Source lines are included only for your own code; turn that off with `WATCH_CAPTURE_SOURCE_CODE=false`.

Exception and log **messages** are sent as written, capped at 4 KB, so do not put personal data in them.

## Configuration

All settings are in `config/watch.php`, driven by env:

| Variable | Default | |
| --- | --- | --- |
| `WATCH_ENABLED` | `true` | |
| `WATCH_TOKEN` | | Project token |
| `WATCH_SERVER_URL` | | |
| `WATCH_TRANSPORT` | `agent` | `agent`, `http` or `null` |
| `WATCH_AGENT_ADDRESS` | `127.0.0.1:2408` | Where the app sends; a Unix socket path also works |
| `WATCH_AGENT_LISTEN` | `127.0.0.1:2408` | Where the agent binds |
| `WATCH_FALLBACK_HTTP` | `false` | |
| `WATCH_HTTP_QUEUE` | `true` on Vapor | |
| `WATCH_DEPLOY` | detected | Otherwise from `LARAVEL_CLOUD_DEPLOY_UUID`, `FORGE_DEPLOY_COMMIT` or `VAPOR_COMMIT_HASH` |
| `WATCH_SERVER_NAME` | hostname | |
| `WATCH_SAMPLE_*` | `1.0` | See Sampling |
| `WATCH_IGNORE_CACHE`, `_QUERIES`, `_MAIL`, `_NOTIFICATIONS`, `_OUTGOING`, `_LOGS` | `false` | |
| `WATCH_LOG_LEVEL` | `warning` | |
| `WATCH_CAPTURE_SOURCE_CODE` | `true` | |
| `WATCH_CAPTURE_PAYLOAD` | `false` | |
| `WATCH_REDACT_FIELDS`, `WATCH_REDACT_HEADERS` | See config | |
| `WATCH_HEALTH_SECRET` | | Without it `/watch/health` is a 404 |

## License

MIT, see [LICENSE.md](LICENSE.md). `codeva/laravel-watch` is forked from laraowl/client, itself derived from [laravel/nightwatch](https://github.com/laravel/nightwatch). Their notices are kept in [LICENSE-laraowl.md](LICENSE-laraowl.md) and [LICENSE-nightwatch.md](LICENSE-nightwatch.md). The transport and truncation design borrow patterns from [spatie/flare-client-php](https://github.com/spatie/flare-client-php).
