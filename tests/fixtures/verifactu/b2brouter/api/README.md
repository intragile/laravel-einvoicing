# B2Brouter Spanish sandbox API shapes

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

Responses of the B2Brouter API, captured once in its sandbox on 2026-11-23 and copied byte for byte from the core repository (`intragile/php-einvoicing`, `tests/fixtures/spain/b2brouter/api/` at commit `4c4503a5538a3f7e3a0f5cbb46c8bba60bcbc5da`; the files were last changed there in `e84d14be31215b825e4f251f38960272be4e32b3`). The core README describes the capture and the redaction: every account id is `999999`, e-mail addresses are `redacted@example.invalid`, URL query strings are dropped, and the API key never appears.

`tests/Support/FakeB2BrouterVerifactuApi.php` uses them as templates. It keeps every field and replaces the ids, the invoice number, the state, the tax report ids and, for a tax report, `fingerprint`, `previous_id`, `qr` and `xml_base64`, so that each answer carries one of the record fixtures in the parent folder.

| File | Source |
| --- | --- |
| `import-201.json` | The import response (HTTP 201) of `POST /accounts/{account}/invoices/import` |
| `invoice.json` | `GET /invoices/{id}`, first read |
| `tax-report.json` | `GET /tax_reports/{id}` once `registered` |

## Checksums

SHA-256 of each file; they match the core copies.

```
b91a6c2d58b25f12d0bdeea19683642a91ac9d872508ee9db1689e47d5845976  import-201.json
b91a6c2d58b25f12d0bdeea19683642a91ac9d872508ee9db1689e47d5845976  invoice.json
3680493c32c1b2f829ec4a4086de80432d8d06383ae44992e7843524f819339e  tax-report.json
```

Check them with `shasum -a 256 *.json` in this folder.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
