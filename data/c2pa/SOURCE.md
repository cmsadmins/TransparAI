# C2PA trust lists

The two PEM bundles in this folder are the trust lists of the C2PA Conformance Program, unchanged:

| File | Content |
|---|---|
| C2PA-TRUST-LIST.pem | certificate authorities whose certificates may sign Content Credentials (claim signers) |
| C2PA-TSA-TRUST-LIST.pem | time stamp authorities whose RFC 3161 time stamps are accepted |

Source: https://github.com/c2pa-org/conformance-public/tree/main/trust-list
(fetched 2026-10-02, upstream sync of 2026-08-14).

License: Creative Commons Attribution 4.0 International (CC BY 4.0),
https://creativecommons.org/licenses/by/4.0/. Copyright the Coalition for Content Provenance and
Authenticity (C2PA). TransparAI bundles the lists unchanged and uses them only to decide whether a
certificate chain ends at a listed authority.

Refresh before a release (workspace root):

```
curl -sL -o dev/data/c2pa/C2PA-TRUST-LIST.pem https://raw.githubusercontent.com/c2pa-org/conformance-public/main/trust-list/C2PA-TRUST-LIST.pem
curl -sL -o dev/data/c2pa/C2PA-TSA-TRUST-LIST.pem https://raw.githubusercontent.com/c2pa-org/conformance-public/main/trust-list/C2PA-TSA-TRUST-LIST.pem
```

Site owners can add their own anchors (a company CA, a newsroom's signing CA) through the filters
`transparai_c2pa_trust_anchors` and `transparai_c2pa_tsa_anchors`, which receive and return an array
of PEM certificates.
