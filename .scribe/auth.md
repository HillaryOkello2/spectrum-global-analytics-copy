# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Obtain a token from <code>POST /api/v1/auth/login</code>. Send it as <code>Authorization: Bearer {token}</code>. Subscriber tokens can only reach Subscriber endpoints; Admin tokens reach Admin endpoints.
