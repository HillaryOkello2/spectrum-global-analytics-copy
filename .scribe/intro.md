# Introduction

REST API for the Spectrum Global Analytics subscription intelligence platform. All endpoints are namespaced under /api/v1 and return JSON.

<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>

    This is the backend API contract for the Spectrum Global Analytics platform, consumed by three frontends: the Public Website, the Subscriber Portal, and the Admin Portal.

    **Authentication.** Public endpoints (catalogue browsing, tiers, register/login) need no token. All Subscriber and Admin endpoints require a Bearer token obtained from `POST /api/v1/auth/login` (or returned directly on Freemium `POST /api/v1/auth/register`). Send it as `Authorization: Bearer {token}`.

    **Conventions.** Resources are identified by opaque `publicId` UUIDs (never numeric ids). Response bodies use camelCase keys and are wrapped in a `data` envelope; list endpoints add pagination `meta`/`links`. Validation errors return `422` with a `message` and `errors` map. Domain errors (e.g. quota exhausted, invalid workflow transition) return a `message`, a machine-readable `code`, and sometimes a `meta` block.

    <aside>Code examples appear on the right (or inline on mobile); switch language with the tabs at the top.</aside>

