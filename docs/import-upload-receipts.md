# Durable receipts for automated file uploads

Existing /autoupload clients are unchanged. Clients that supply an optional import_id (16-64 letters, digits, underscores or hyphens) opt in to a durable receipt. Pair it with the existing shared secret and Firefly personal access token. Put the shared secret in the POST body rather than a URL.

A completed upload returns status imported, import_id, job_id, converted_count, duplicate_count and error_count. Failed conversions/submissions return HTTP 422 and status failed. A repeated identifier with the same payload returns the stored receipt; it does not re-import. A different payload or an upload still processing returns HTTP 409. Duplicate external IDs already present in Firefly are counted as expected skips, rather than failed new transactions. Other errors prevent a success receipt.

After an uncertain network result, POST /import-receipt with secret and import_id. HTTP 200 contains the stored receipt; HTTP 404 means its state is unknown and must be investigated, not blindly retried. A processing receipt survives process interruption and remains blocked until reviewed.

Persist storage/import-receipts across deployments. Its records contain only job identifiers, status, counts and a payload fingerprint, not transaction contents. Public receipt responses exclude the fingerprint. Administrative access to any UI remains the deployment's responsibility.
