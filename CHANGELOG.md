# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.13.0 — 2026-11-05

First release (FE-55, ADR-028). The transactional outbox for Zana applications. Events are
saved in the same transaction as the change that caused them. They are delivered through the
event dispatcher after the commit, at least once, with retries and failure marking. The inbox
lets a consumer handle each message once. Tested on SQLite, MySQL and PostgreSQL. Requires PHP
8.5 or later.

Documentation: [the outbox guide](https://github.com/mjnable/zana-docs/blob/main/guides/outbox.md).
