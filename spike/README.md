# Spike — raw gRPC over amphp against KurrentDB

Throwaway code. Goal: prove the pure-PHP route before any interface work
(`docs/product-spec.md` § Milestones). Hand-rolled protobuf encoding — no
protoc, no stubs, no extensions. Everything runs in Docker:

- `kurrentdb` service: `kurrentplatform/kurrentdb` (Docker Hub), insecure,
  gRPC on port 2113.
- `php` service: PHP 8.4 CLI + Composer, this directory mounted at `/srv`.

## Steps

```sh
cd spike
docker compose up -d --build          # 1. server (steps 1 in README), php env
docker compose exec php composer install   # only first time
docker compose exec php php spike.php      # 2–5: append → trailers → read
```

## Status (2026-11-08)

**All checks pass.** Milestone steps 2–5 of `docs/product-spec.md` validated,
including the slow-consumer backpressure test:

```sh
docker compose exec php php spike.php        # append → trailers → read
docker compose exec php php backpressure.php # slow consumer on Read($all)
```

Wire-level debugging tools kept in the repo:
- `netdump/` — TCP wire-tap proxy over which any client can be byte-dumped.
- `dump/h2dump.py` — HTTP/2 frame + HPACK dump for the captures.
- `goprobe/` — the official Go client as the reference behavior.

Facts learned (mirror into the handoff):
- `ReadReq.Options` **requires** `uuid_option` (field 9).
- Appends are expected to carry `requires-leader: true` request metadata.
- `StreamIdentifier.stream_name` is field **3** (bytes), not field 1.
- `shared.UUID`: structured=1 (Structured{most,least}), string=2.
- gRPC client-streaming: no `content-length`; one chunk per frame + half-close.
- `created` metadata is server-generated — clients send only `type` +
  `content-type`.
- `WrongExpectedVersion` arrives in the body; gRPC status stays 0.

## What spike.php does

1. Hand-encodes `AppendReq` (client-streaming: Options frame + event frame,
   then end-of-stream) and sends it via amphp/http-client 5.x as a
   POST to `/event_store.client.streams.Streams/Append`, length-prefixed
   gRPC frames, `content-type: application/grpc`.
2. Reads the single `AppendResp`, decodes Success (current revision +
   commit/prepare position) or `WrongExpectedVersion`.
3. Asserts `grpc-status: 0` comes from **trailers**
   (`$response->getTrailers()`), falling back to response headers for the
   edge case — the make-or-break question (README § Milestones step 4).
4. Does the same for `Read` (server-streaming): `ReadReq` (count=10,
   no_filter), parses the `ReadResp` event frames (id, revision, data).
5. Verifies the appended event's JSON round-trips.

Anything printed in green means the corresponding product-spec milestone
step passed. Deleting code here and rewriting properly is expected.

## Notes

- `lib/protobuf.php` is a ~100 line protobuf wire encoder/decoder
  (varint/length-delimited only), written for learning, not for production.
- `lib/messages.php` encodes only what the spike needs:
  `AppendReq.{Options, ProposedMessage}` and `ReadReq.Options` plus minimal
  parsing of `AppendResp` / `ReadResp` event content.
- Server image is `kurrentplatform/kurrentdb:latest`. On Apple Silicon, if
  `latest` ships amd64-only, Docker Desktop runs it via Rosetta.
