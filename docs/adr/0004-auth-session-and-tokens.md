# ADR 0004: Sanctum sessions for the web app, tokens for API clients

Status: accepted (implemented in Phase 1)

## Decision

The React app is served from the same domain as the API and authenticates with Sanctum's session cookie plus CSRF protection. Mobile and other API clients pass `device_name` and receive a personal access token. Both use the same `/api/v1` endpoints.

## Why

Cookies marked HttpOnly keep tokens out of JavaScript in the browser, which limits the damage from XSS. Tokens suit mobile apps, which will store them with `flutter_secure_storage`. One API means one set of authorization checks.
