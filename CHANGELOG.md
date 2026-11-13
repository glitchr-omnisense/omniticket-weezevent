# Changelog

Every version of omniticket/weezevent, newest first ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
[Semantic Versioning](https://semver.org/)). A release's notes are its section here: the release
workflow refuses a tag without one.

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omniticket` `^1.0` with no stability flag, so `composer require omniticket/weezevent` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

Omniticket's Weezevent, under any PHP host: an organizer's events with their rates (categories flattened), sessions and sales period, the participants of an event (incrementally), and a ticket's barcode checked at the door - read only, through Weezevent's API (api.weezevent.com).
