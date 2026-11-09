# MIT for our code; Apache-2.0 NOTICE attached only to generated stubs

Our client code ships under MIT. The protobuf stubs are generated from the
official Go client's `protos` package (repo LICENSE verified 2026-11-07 to be
Apache-2.0, © Kurrent, Inc), and those generated files keep Apache-2.0 headers
plus NOTICE attribution; the obligations therefore stay with the derived files
instead of spreading to the whole package.

Considered options: Apache-2.0 throughout (single uniform license, more
attribution surface); a source-available Kurrent-license-style scheme
(rejected: restricts the exact audience this project exists to serve).

Consequences: MIT is reputational-hard to reverse, so re-licensing a future
adult package will require explicit coordination; contributors assign under the
package license unless stated otherwise.
