# AEAT VERI*FACTU response fixtures

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

Byte-for-byte copies of the core fixtures in `php-einvoicing/tests/fixtures/spain/aeat/`, taken with `git show` at core commit `4c4503a7c932ae34199437a6d95e6c1aa10b47bc` (branch `feat/plan-07c`; the files were last changed in `5f0ec94`). The core `/tests` folder is export-ignored, so a Packagist install of the core never carries them. See the core README for what each file holds. They are SOAP responses constructed from the AEAT schemas, not AEAT output, and every NIF, CSV and request id in them is fictitious.

`tests/Support/FakeAeatApi.php` answers submissions with these bodies, substituting the `IDFactura`, the operation and the error code of each line per test.

`tests/Support/AeatCertificates.php` is a copy of the core `tests/Support/AeatCertificates.php` at the same commit. Its namespace line is the only change, to `IntrAgile\EInvoicing\Laravel\Tests\Support` (source sha256 `3921a514cd357a64ce909d3e9d21760d1e246fe59cd4933a3f01dfa3eeed13d6`, copy sha256 `8a87872d4428507e5e8a335854bb9127e4f0024747f8e2c86fef173488894681`).

| File | sha256 (source and copy) |
| --- | --- |
| `consulta-con-datos.xml` | `22718d68d8929503a127895d4202060fe46187ac48ec4124e93045fdc950c1a5` |
| `consulta-sin-datos.xml` | `3d2fe081ca072d3aa2553a2515d8c19ccf95dfa3d1bd4accd80e68c173485a38` |
| `correcto.xml` | `2d72a9f03da73bd772db69014f0604026df0700973da9f33e9cc14397c3c62b8` |
| `duplicado-anulada.xml` | `3d35bde87e5944c09e5839d7ffecb64778906557cc3746cf2713fd48a44f912c` |
| `duplicado-correcta.xml` | `8514e3b1cd2201188df4300b4e3b2c018351cba933ff5c783d7fbe9784671f50` |
| `fault-client-4104.xml` | `b6791f81cecb5925841e45a96ae32dea0348ba57d04bb0b75e63fd6eb4d6752b` |
| `fault-client-4112.xml` | `83bcde949c96f1611818c2dd5f6f6ebc6fcc42e7fa144ce6106af853a76d6307` |
| `fault-server.xml` | `93505e645c24b51b28298791b94bc7d42cf0fab621147f612a6d63216abc90b0` |
| `html-502.html` | `3baf6280f31bb5171223e354f989540ec3d0168fe265aaf0eb887741c9787044` |
| `incorrecto.xml` | `3d70d0609e18f6b6ca2d60f9b3609f0e76b6fb7f7b46440ec82234574881d101` |
| `parcialmente-correcto.xml` | `cc6bedc19dca532f8a0805dca9fb0510d9d88499dcbabc65ca8c9e31ffb1ae46` |

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
