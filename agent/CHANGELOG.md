# Changelog

## Unreleased
-

## 1.10.0
- Added privacy-safe authentication request context with route, controller, method, path, hashed IP address, and user agent details.
- Added optional Laravel Passport access and refresh token lifecycle telemetry with user, OAuth client, and hashed credential identifiers without requiring Passport as a dependency.
- Added sanitized redirect destinations to request telemetry while removing OAuth codes, state values, and other query parameters from redirect headers.
- Fixed queued notifications on Laravel 10 by disabling only `JobQueued` telemetry when queue and delay metadata are unavailable.

## 1.9.0
- Fixed recursive self-monitoring of the configured ingestion endpoint that could saturate CPU and cause HTTP 504 responses for LaraSignal and connected applications.

## 1.8.0
- Added first-class span, deployment, authentication, security, queue health, transaction, storage, and runtime telemetry with Activity analytics and configuration switches.
- Added automatic authentication lifecycle, authorization denial, rate-limit, queue timeout/busy/failover, worker lifecycle, database transaction/health, typed cache outcome, HTTP connection failure, and mail duration/failure capture.
- Added privacy-safe storage measurement, runtime gauge, and external broadcast provider metric APIs.
- Added first-class process heartbeats with missed-heartbeat visibility and a schedulable `larasignal:heartbeat` command.
- Added broadcast publication and Reverb lifecycle telemetry with provider, connection, channel, outcome, failure, queue, attempt, and duration details.
- Added safe structured stack frames to exception telemetry and increased the configurable exception message limit to 64 KiB so issue investigations retain complete error details.

## 1.7.0
- Added independent environment switches for request, job, command, scheduled task, exception, query, notification, mail, cache, outgoing request, custom event, and user telemetry.

## 1.6.0
- Fixed framework exceptions raised outside request middleware, including missing application key failures during HTTP termination, not being sent before the process exits.

## 1.5.0
- Added scheduled-task identity, cron expression, next run, timezone, outcome, exit code, safety flags, environments, memory, exception, and duration telemetry.
- Added command exit code, redacted arguments and options, peak memory, completion status, and execution duration telemetry for command investigations.
- Added queue connection, queue name, attempt, job ID, outcome, exception, release backoff, and execution duration telemetry for job investigations.
- Added request route, path, query, redacted headers, middleware, controller, response size, and peak memory telemetry for request investigation views.

## 1.4.0
- Fixed `larasignal:test` sending buffered bootstrap telemetry so each run delivers only one verification event.
- Added `larasignal:test --all` to send representative telemetry for every activity category.
- Added notification capture and log severity metadata so those activity pages receive filterable events.

## 1.3.0

- Fixed recursive telemetry recording during database and authentication lookups that could exhaust PHP memory and return HTTP 500 responses.

## 1.2.0

- Added built-in AI Agentic Coding Skill (`.agents/skills/larasignal-development/SKILL.md`) and Cursor rules (`.cursor/rules/larasignal.mdc`) publishing via `vendor:publish --tag=larasignal-skill`.

## 1.1.0

- Added `larasignal:help` CLI command for interactive agent usage and environment reference.
- Added `larasignal:run` background worker daemon (`--sleep=3`, `--once`) with signal handling.
- Added zero-latency asynchronous disk spooling mode (`LARASIGNAL_ASYNC=true`).
- Fixed `.env` installation formatting in `InstallCommand` to write contiguous key-value pairs without blank lines.
- Added automatic terminating lifecycle telemetry flushing (`app()->terminating()`) for request and job execution.

## 1.0.0

- Initial privacy-first Laravel telemetry agent.
