# B2Brouter VERI*FACTU sandbox fixtures

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

Two VERI*FACTU `RegistroAlta` records that the B2Brouter Verifactu API generated in its sandbox on 2026-11-23, and the UBL invoice that the second one was generated from. The tests use them as records written by other software: a B2Brouter chain is stored from them without being generated here.

The records are copied byte for byte from the core repository (`intragile/php-einvoicing`, `tests/fixtures/spain/b2brouter/` at commit `ee6c86d5538a3f7e3a0f5cbb46c8bba60bcbc5da`). There the installation number `NumeroInstalacion`, which B2Brouter derives from the sandbox account id, was already replaced by `1-999999`, so no account id is committed. `SistemaInformatico` is not an input of the `Huella`, so each record still reproduces its own fingerprint. The invoice `es-ubl2.xml` is the UBL document imported into the sandbox for `PROBE-ES-UBL-0002`; it carries no account id.

The issuer NIF `B64829179` and the recipient NIF `A58818501` are fictitious test values with a valid CIF control digit; no real company or person appears in them. The `SistemaInformatico` block of the records names B2Brouter as the producing software, as B2Brouter wrote it.

`.gitattributes` marks `tests/fixtures/verifactu/` `-text`, so Git never rewrites these bytes.

| File | Invoice | Content | Huella |
| --- | --- | --- | --- |
| `record-1064490.xml` | PROBE-ES-0001 | F1, first record of the chain (`PrimerRegistro` S) | `1E823150439412AB8A572FBFD66D676CA366074F933949010EC70FDDC1D5FC73` |
| `record-1064493.xml` | PROBE-ES-UBL-0002 | F1 from the imported UBL invoice, chained to PROBE-ES-0001 | `D60F9FD2DDDD19313359612C52222CC74E0B7C6E3C0D64AFBC797F2CE54EF812` |
| `es-ubl2.xml` | PROBE-ES-UBL-0002 | The UBL 2.1 invoice imported into B2Brouter | |

## Checksums

SHA-256 of each file; the two records match the core copies.

```
7da540d597d96d476bf632ecaf91f68358ba44d02081b9c3c0e93e27bf7ab5ef  record-1064490.xml
371338381cb71ec48e9f9f91681b4aa16219dd758773e217bb17f7223d625e60  record-1064493.xml
adb54c126d9cda82b1c2320cca44585e8e4fcf4de73a0194aedf42d3213dcb64  es-ubl2.xml
```

Check them with `shasum -a 256 *.xml` in this folder.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
