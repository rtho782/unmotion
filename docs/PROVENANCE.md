# Recovery provenance

The source baseline was recovered from:

```text
C:\Users\rich\Downloads\unmotion-0.3.0-beta7.plg
```

The PLG's SHA-256 exactly matches the hash recorded in the prior release conversation:

```text
20ace170707eaed65e55a7b2418e6e86fe5e98fbb48c1b5f2d5f509889e55656
```

The PLG embeds `unmotion-0.3.0_beta7-noarch-1.txz`. Its recovered checksums are:

```text
MD5     4bf2dad8dd80c4a87546a9c55fb9f518
SHA-256 cbcacc6c8b3c1a9df4e4ce4b7020a9806ddaf00aff91fd8527b97d05e30c34f6
```

`src/rootfs/` is a direct extraction of that TXZ. No executable beta7 source file was rewritten during repository creation. Documentation, tests and build helpers were newly added and are not claimed as beta7 artifacts.

The user subsequently supplied the separately published source tarball. Its SHA-256 is:

```text
a23e9685502c2b7d6d81d1cd2e672ed7614ceebca55631908213f12ec259fc0a
```

Its `source/` directory is byte-identical to the root filesystem extracted from the PLG's TXZ, and its retained TXZ is also byte-identical. The original README and two layout-specific build scripts are preserved as `docs/README-0.3.0-beta7-original.md`, `release/0.3.0-beta7/build.sh.original` and `release/0.3.0-beta7/build-standalone-plg.sh.original`. The complete bundle and separately published checksum file were not needed for recovery.

The historical archive is retained under `release/0.3.0-beta7/` so future work can always compare current source to the shipped baseline.
