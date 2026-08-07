# Changelog

## 1.0.1

- Added `larasignal:help` CLI command for interactive agent usage and environment reference.
- Added `larasignal:run` background worker daemon (`--sleep=3`, `--once`) with signal handling.
- Added zero-latency asynchronous disk spooling mode (`LARASIGNAL_ASYNC=true`).
- Fixed `.env` installation formatting in `InstallCommand` to write contiguous key-value pairs without blank lines.
- Added automatic terminating lifecycle telemetry flushing (`app()->terminating()`) for request and job execution.
- Added built-in AI Agentic Coding Skill (`.agents/skills/larasignal/SKILL.md`) and Cursor rules (`.cursor/rules/larasignal.mdc`) publishing via `vendor:publish --tag=larasignal-skill`.

## 1.0.0

- Initial privacy-first Laravel telemetry agent.
