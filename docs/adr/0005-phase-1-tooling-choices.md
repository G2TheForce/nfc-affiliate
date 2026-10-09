# ADR 0005: Phase 1 tooling choices

Status: accepted

- **PHPUnit instead of Pest.** The Phase 0 plan said Pest. Laravel 12 ships PHPUnit, and it covers everything needed without another dependency.
- **React 19 and React Router 7.** React Router 6 has open security advisories (npm audit, moderate), so v7 is used.
- **Email verification uses a signed GET link** (`/api/v1/auth/verify-email/{id}/{hash}`) instead of the `POST /auth/verify-email` example in the spec. A link clicked from an email is a GET request. The signature, expiry and hash check give the same protection.
- **Queue and cache use the database driver.** For about 100 affiliates this avoids running Redis. Switching later is a configuration change.
