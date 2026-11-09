---
name: wire-debug
description: "Debug the gRPC wire when a KurrentDB interaction fails in an unhelpful way ('Exception was thrown by handler', parse crashes, missing trailers). Captures raw HTTP/2 bytes through a TCP tap proxy, renders frames/HPACK headers, and byte-diffs the event against the official Go client to find the request-construction divergence. Use when any wire-level mismatch appears or before changing encoder logic."
---

# Wire debugging — byte-diff against the official client

When a parse error or handler exception appears, the encoding assumption is
the suspect: **compare the bytes**, don't reason from the schema text. This
repo keeps its debugging toolkit inside `spike/` (throwaway by design, but
maintained while protoc stubs are pending).

## Toolkit

| Tool | What it does |
|---|---|
| `spike/netdump/main.go` | TCP tap proxy: listens on :2114, forwards to kurrentdb:2113, mirrors each direction to `/dump/*.bin` |
| `spike/dump/h2dump.py` | HTTP/2 frame dump of a capture: SETTINGS/HEADERS (with HPACK decode)/DATA (gRPC length + body hex) |
| `spike/goprobe/` | The official Go client (v1.4.2) as the known-good byte reference |

## Procedure

1. **Start the tap** (any compose network where both a kurrentdb and the
   probe clients live):

   ```sh
   docker run -d --name netdump --network <net> \
     -v "$PWD/spike/dump":/dump -v "$PWD/spike/netdump":/app \
     golang:1.25-alpine /app/netdump
   ```

2. **Point both clients at the tap** — whatever fails (ours) and the Go probe:
   - ours: `KURRENTDB_URI=kurrentdb://netdump:2114?Tls=false`
   - Go: `KURRENTDB_CONN=kurrentdb://netdump:2114?tls=false`
3. **Render:**

   ```sh
   python3 spike/dump/h2dump.py spike/dump/client2server-conn*.bin
   # response side: spike/dump/server2client-conn*.bin
   ```

4. **Diff the failing and working exchanges at two levels:**
   a. **Frame structure**: headers received? DATA frame count and sizes? Was
      END_STREAM on the right side? (Client-streaming = several DATA frames +
      empty END_STREAM frame.)
   b. **gRPC body bytes** (the `body=` hex per DATA frame): walk field tags
      against the official client's body. The historical catches: Options vs
      ProposedMessage tag mix-up, `shared.UUID` nesting depth,
      `StreamIdentifier.stream_name` at field 3 (bytes, not field 1 string),
      count values encoded with force.
5. **Record the finding** in `kurrentdb-php-client-handoff.md` §5.9 the same
   session, and (if it regenerated a rule) in `CODING_STANDARDS.md` §8–9.

## Known wire gotchas (already learned — check these first)

- Clients send **no `content-length`** on client-streaming calls; body streams
  with one DATA chunk per gRPC frame, then half-close.
- `grpc-status` normally arrives in **trailers** (error cases may merge into
  headers as a "trailers-only" response).
- `ReadReq` **requires** `uuid_option` (field 9); omitting it breaks on 26.x.
- Appends carry `requires-leader: true`.
- `ReadResp` zero-scalar omissions (proto3): revision 0 arrives *absent*.
- Server echoes `WrongExpectedVersion` in the **body** with grpc-status still 0.
- `created` metadata is server-generated (ticks) — clients send only
  `type` + `content-type`.
- Extra HTTP headers (`accept`, `accept-encoding`) don't affect the outcome;
  don't spend time diffing those.
