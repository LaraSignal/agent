# Changelog

## Unreleased
- 

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
