# Contracts split from day one, in a single repo

We publish two Composer packages from one solo repo via subtree-split tags:
`m4t1t0/kurrentdb-contracts` (interface, value objects, typed exceptions)
and `m4t1t0/kurrentdb-client` (the amphp implementation). This deviates
from the standard "one package now, extract later" step: we anticipate the
FrankenPHP implementation and userland mocks needing to depend on a tiny,
implementation-free artifact, and wanted the dependency boundary to exist
before the interface freezes.

Considered options: single package with later extraction (rejected — freeze
now, churn later); two separate repos (rejected — splits the solo workflow and
synchronicity of changes).

Consequences: every interface-affecting change now carries per-package version
tagging overhead, and the two packages must stay version-aligned (client
declares its required contracts package explicitly).
