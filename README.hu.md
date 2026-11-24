# Laravel E-Invoicing

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![License](https://img.shields.io/packagist/l/intragile/laravel-einvoicing.svg)](LICENSE)

Laravel-integráció az [`intragile/php-einvoicing`](https://github.com/intragile/php-einvoicing)
csomaghoz: EN 16931 szerinti számlák küldése és fogadása **Peppol** hálózaton, francia
e-számlázás a B2Brouteren át, lengyel **KSeF** FA(3) számlák és spanyol **VERI\*FACTU**
nyilvántartási rekordok, Laravel alkalmazásból.

[English documentation → README.md](README.md)

> **Állapot:** 1.2.0-s verzió, kiadva 2026-11-23-án. Mit és hogyan ellenőriztünk:
> [Mi van ellenőrizve](#mi-van-ellenőrizve) és [docs/VERIFICATION.md](docs/VERIFICATION.md) (angolul).

- **Konfigurált transportok.** Névvel ellátott kapcsolatok a `config/einvoicing.php`-ban; a
  beépített `recommand`, `b2brouter` és `einvoicebe` driver a [Recommand](https://recommand.eu),
  a [B2Brouter](https://www.b2brouter.net) és az [e-invoice.be](https://e-invoice.be) access
  pointhoz csatlakozik, saját drivert az `EInvoicing::extend()` regisztrál. A Storecove
  tervezett; lásd: [Driverek](#driverek).
- **Validáció minden küldés előtt**, alapból bekapcsolva: a Peppol BIS dokumentumokat a
  hivatalos szabályok ellenőrzik (egyetlen artisan parancs telepíti őket), az XRechnung
  dokumentumokat a KoSIT-szabályok.
- **Sorba állított küldés, védett állapotgéppel.** A küldést egy feltételes UPDATE foglalja le,
  így egy duplikált vagy megismételt job sosem küld kétszer. Ha egy küldés kimenetele nem
  ismert, a csomag **soha nem küldi újra magától**: egy operátor dönt róla.
- **Kézbesítési státusz** lekérdezéssel és webhookkal, minden lépésnél eseménnyel.
- **Bejövő dokumentumok** webhookból vagy lekérdezésből: letöltés, tárolás, egyszeri esemény,
  és csak ezután nyugtázás az access pointnál.
- **Aláírt webhookok**, pontosan a beérkezett kérésen ellenőrizve, a `web` middleware-csoport
  nélkül.
- **Lengyel KSeF:** sorba állított FA(3) küldés a KSeF-szám és a nyugta tárolásával,
  postafiók-lekérdezés a KSeF olvasási keretein belül, offline határidők és KSeF-tanúsítványok;
  lásd: [Lengyel KSeF](#lengyel-ksef).
- **Spanyol VERI\*FACTU:** kibocsátónként és telepítésenként előállított, láncolt és tárolt
  nyilvántartási rekordok, azonnali QR URL-lel; sorba állított beküldés az AEAT-nak a
  forgalomszabályozása szerint, vagy aszinkron alternatívaként a B2Brouter, a saját motorunkkal
  keresztellenőrizve; lásd: [Spanyol VERI\*FACTU](#spanyol-verifactu).
- `EInvoicing::fake()` a saját tesztekhez, `einvoicing:doctor` a telepítés ellenőrzéséhez.

> **Éles tesztelés driverenként.** A Recommandot a Peppol teszthálózaton teszteltük élesben, e
> csomagon keresztül is. A B2Brouter és az e-invoice.be a szolgáltatói sandbox ellen van élesben
> tesztelve (szimulált hálózat). Minden más offline tesztelt; lásd: [Driverek](#driverek) és
> [Mi van ellenőrizve](#mi-van-ellenőrizve).

## Követelmények

- PHP 8.3+ a `dom`, `libxml` és `mbstring` kiterjesztéssel
- Laravel 12 vagy 13
- Éles használathoz queue worker (lásd: [Queue](#queue))

## Csomagok

Ez a csomag a motor Laravel-integrációja. Három vékony belépő csomag viseli azokat a neveket, amelyekre az emberek keresnek; mindegyik Composer metacsomag (nincs kódja), amely ezt a csomagot, és ahol a használati eset megkívánja, a szabálycsomagját is behúzza:

| Csomag | Mire való | Behúzza |
|---|---|---|
| [`intragile/laravel-xrechnung`](https://github.com/intragile/laravel-xrechnung) | XRechnung 3.0 Németországhoz | ezt a csomagot, `intragile/einvoicing-rules-xrechnung` |
| [`intragile/laravel-zugferd`](https://github.com/intragile/laravel-zugferd) | ZUGFeRD 2.x és Factur-X 1.0 hibrid PDF-számlák | ezt a csomagot, `intragile/einvoicing-rules-facturx` |
| [`intragile/laravel-peppol`](https://github.com/intragile/laravel-peppol) | Küldés és fogadás Peppol hálózaton | ezt a csomagot |

Maga a motor az [`intragile/php-einvoicing`](https://github.com/intragile/php-einvoicing); a szabálycsomagok: `intragile/einvoicing-rules-en16931`, `intragile/einvoicing-rules-xrechnung` és `intragile/einvoicing-rules-facturx`.

## Driverek

Elérhető driver a Recommand, a B2Brouter és az e-invoice.be; a Storecove tervezett.

| Driver | Peppol tesztháló | Szolgáltatói sandbox | Állapot |
|---|---|---|---|
| Recommand (`recommand`) | igen (playground csapat `useTestNetwork` beállítással) | igen | élesben tesztelve a Peppol teszthálózaton |
| B2Brouter (`b2brouter`) | nem (a sandbox szimulálja a hálózatot) | igen, ingyenes | élesben tesztelve a szolgáltatói sandbox ellen (szimulált hálózat) |
| e-invoice.be (`einvoicebe`) | nem (a sandbox szimulálja a hálózatot) | igen, ingyenes | élesben tesztelve a szolgáltatói sandbox ellen (szimulált hálózat) |
| Storecove | nem ellenőrzött | kérésre (értékesítői kapcsolat) | tervezett |

A hálózatot csak szimuláló szolgáltatói sandbox elleni teszt a szolgáltató API-szerződését
igazolja, nem a Peppolon történő kézbesítést; a Peppol teszthálót csak a Recommand éri el. Az
e-invoice.be csak Peppol BIS Billing 3.0 UBL-t küld. A B2Brouter a továbbított dokumentumot a
saját számlamodelljéből állítja elő, így a kézbesített bájtok eltérhetnek a te XML-edtől. A webhook-aláírásokat a szolgáltatók dokumentált sémája szerint offline ellenőrizzük (az e-invoice.be-ét a közzétett tesztvektorral is); élesben csak a B2Brouter webhookjait teszteltük (2026-11-18, sandbox-kézbesítések a Laravel hídon visszajátszva). Hogy
melyik driver mit küld, hogyan képezi le az állapotokat, hogyan fogad és mit tud a webhookja, azt
a core [TRANSPORT.md](https://github.com/intragile/php-einvoicing/blob/main/docs/TRANSPORT.md)
leírása tartalmazza (angolul).

## Telepítés

```bash
composer require intragile/laravel-einvoicing
php artisan vendor:publish --tag=einvoicing-config     # opcionális: config/einvoicing.php
php artisan einvoicing:install-rules                   # a Peppol BIS validációs szabályok
php artisan migrate                                    # einvoicing_outbound_documents, einvoicing_inbound_documents, einvoicing_ksef_cursors
php artisan einvoicing:doctor                          # mindent ellenőriz, ami alább szerepel
```

> **Az első kiadásig** sem ez a csomag, sem az `intragile/php-einvoicing` nincs fent a
> Packagisten. Addig klónozd mindkét repót az alkalmazás mellé, és vedd fel őket path
> repositoryként az alkalmazás `composer.json`-jába:
>
> ```json
> "repositories": [
>     { "type": "path", "url": "../php-einvoicing", "options": { "versions": { "intragile/php-einvoicing": "1.2.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-en16931", "options": { "versions": { "intragile/einvoicing-rules-en16931": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-xrechnung", "options": { "versions": { "intragile/einvoicing-rules-xrechnung": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-facturx", "options": { "versions": { "intragile/einvoicing-rules-facturx": "1.0.0" } } },
>     { "type": "path", "url": "../laravel-einvoicing", "options": { "versions": { "intragile/laravel-einvoicing": "1.0.0" } } }
> ]
> ```
>
> majd kérd be így: `"intragile/laravel-einvoicing": "^1.0"`. A `versions` beállítás minden path
> csomagnak azt a verziót adja, amelyet a csomagok egymás közötti feltételei várnak.

A migrációkat a csomag maga tölti be. Ha saját példányt szeretnél belőlük az alkalmazásban:
`php artisan vendor:publish --tag=einvoicing-migrations`.

Az `einvoicing:install-rules` letölti a rögzített verziójú Peppol BIS Billing forrásokat, és a
`storage/einvoicing/peppol-rules` mappába (vagy az `EINVOICING_RULES_DIR` helyére) fordítja
őket. Hálózat nélkül add meg a letöltött `.sch` fájlok mappáját: `--from=DIR`. Az OpenPeppol nem
ad licencet a szabályaihoz, ezért azok a te gépeden települnek, a csomag nem tartalmazza őket.

### Beállítás

```dotenv
EINVOICING_TRANSPORT=recommand          # az alapértelmezett transport

RECOMMAND_API_KEY_ID=key_...
RECOMMAND_API_SECRET=secret_...
RECOMMAND_COMPANY_ID=c_...
RECOMMAND_WEBHOOK_SECRET=...            # csak ha webhookot fogadsz
# RECOMMAND_BASE_URL=https://app.recommand.eu

# EINVOICING_RULES_DIR=/path/to/peppol-rules
# EINVOICING_QUEUE_CONNECTION=redis
# EINVOICING_QUEUE=einvoicing
```

Minden access point egy bejegyzés az `einvoicing.transports` listában, a beépített `recommand`,
`b2brouter` vagy `einvoicebe` driverek egyikével. A publikált konfigurációs fájlban a
`recommand` bejegyzés aktív, a másik kettő kikommentezve szerepel, mert az `einvoicing:doctor`
minden felsorolt transportot ellenőriz. Vedd ki a kommentből azokat, amelyeket használsz, és az
`EINVOICING_TRANSPORT` mutasson az alapértelmezettre.

**B2Brouter** (`driver`: `b2brouter`):

```php
// config/einvoicing.php
return [
    // ...
    'transports' => [
        'b2brouter' => [
            'driver' => 'b2brouter',
            'api_key' => env('B2BROUTER_API_KEY'),
            'account_id' => env('B2BROUTER_ACCOUNT_ID'),
            'base_url' => env('B2BROUTER_BASE_URL', 'https://api.b2brouter.net'),
            'document_type_codes' => [],
            'webhook_secret' => env('B2BROUTER_WEBHOOK_SECRET'),
            'webhook_tolerance' => null,
        ],
    ],
];
```

```dotenv
B2BROUTER_API_KEY=...                   # a test_ kezdetű kulcs a sandboxba megy
B2BROUTER_ACCOUNT_ID=12345              # a küldő és fogadó fiók
B2BROUTER_WEBHOOK_SECRET=...            # csak ha webhookot fogadsz
# B2BROUTER_BASE_URL=https://api.b2brouter.net
```

A B2Brouter a `test_` kezdetű kulcsot a sandboxába irányítja, amely sosem éri el a Peppol
hálózatot; a doctor erre figyelmeztet. Az `account_id` pozitív egész szám. A
`document_type_codes` további Peppol dokumentumtípus-azonosítókat rendel B2Brouter
dokumentumtípus-kódokhoz; a Peppol BIS Billing 3.0 számlához és jóváíráshoz nem kell semmi. A
`webhook_tolerance` (másodperc) elutasítja azt az értesítést, amelynek aláírt időbélyege ennél
távolabb van a jelentől; alapból ki van kapcsolva (`null`), mert a B2Brouter nagyjából egy hétig
próbálja újra az értesítést.

**e-invoice.be** (`driver`: `einvoicebe`):

```php
// config/einvoicing.php
return [
    // ...
    'transports' => [
        'einvoicebe' => [
            'driver' => 'einvoicebe',
            'api_key' => env('EINVOICEBE_API_KEY'),
            'base_url' => env('EINVOICEBE_BASE_URL', 'https://api.e-invoice.be'),
            'tenant_id' => env('EINVOICEBE_TENANT_ID'),
            'inbound_lookback_days' => 7,
            'inbound_max_pages' => 10,
            'webhook_secret' => env('EINVOICEBE_WEBHOOK_SECRET'),
        ],
    ],
];
```

```dotenv
EINVOICEBE_API_KEY=...
EINVOICEBE_WEBHOOK_SECRET=...           # csak ha webhookot fogadsz
# EINVOICEBE_TENANT_ID=...              # más tenant webhook-értesítéseit eldobja
# EINVOICEBE_BASE_URL=https://api.e-invoice.be
```

Az e-invoice.be-nek nincs hívása egy fogadott dokumentum nyugtázására, ezért a nyugtázás csak
helyi: a dokumentum a beérkező mappájában marad, és minden lekérdezés az utolsó
`inbound_lookback_days` nap (1–365) dokumentumait listázza, legfeljebb `inbound_max_pages`
százas oldalon (1–100); a csomag minden dokumentumot egyszer tárol és tölt le. Ha a `tenant_id`
be van állítva, a más tenantot megnevező webhook-értesítéseket figyelmen kívül hagyja; nélküle
minden aláírt értesítést feldolgoz (üres `EINVOICEBE_TENANT_ID=` sor esetén is), és az
`einvoicing:doctor` ilyenkor, bekapcsolt webhooknál, egy info sorban jelzi ezt.

A `config/einvoicing.php` további részei: HTTP-időkorlátok és a válaszméret felső határa
(`http`), a validáció ki- és bekapcsolása (`validation.enabled`), a küldés újrapróbálása
(`sending.max_attempts`, `sending.backoff`), a státusz lekérdezése (`status.poll`,
`status.delays`, `status.max_attempts`), a bejövő dokumentumok nyugtázása és kísérleti kerete
(`inbound.acknowledge`, `inbound.max_fetch_attempts`) és a webhook-útvonal (`webhooks`). A
késleltetési listák utolsó eleme ismétlődik. A számok számot tartalmazó stringként is
megadhatók, ahogy a környezeti változók adják őket; minden mást a csomag a kulcsot megnevező
hibával elutasít. A `webhooks.enabled` a `"true"`/`"false"` és az `"1"`/`"0"` stringet is
elfogadja. Az API-kulcsok, valamint a Recommand API-kulcs azonosítója és titka egyaránt legalább
8 karakter legyenek.

A transport első használatkor épül fel, így a hiányzó hozzáférési adatok sosem akasztják meg az
alkalmazás indulását. A titkos kulcsok nem kerülnek kivételüzenetbe, a doctor kimenetébe,
eseménybe vagy sorba állított job adatai közé; a jobok csak a sor azonosítóját viszik magukkal.

## Küldés

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;

$message = EInvoicing::send($invoice, reference: 'INV-2026-0001');
```

A `send()` elfogad egy core `Invoice` modellt, UBL vagy CII XML-t stringként vagy egy
`OutboundDocument`-et. A címzést (küldő, címzett, folyamat) magából a számlából olvassa ki. A
dokumentumot `OutboundMessage`-ként, `queued` állapotban tárolja, a `SendDocument` jobot pedig
a nyitott adatbázis-tranzakció (ha van) commitja után indítja. A harmadik, opcionális
paraméterrel az alapértelmezettől eltérő transport adható meg.

**Add meg a számlán annak a folyamatvezérlését (process control), amit küldesz.** Peppolon csak
ezek a dokumentumok mennek ki, a gyökérelem és a specifikációazonosító (BT-24) pontos párosa
alapján:

- Peppol BIS Billing 3.0: UBL Invoice vagy CreditNote;
- XRechnung 3.0: UBL Invoice, UBL CreditNote vagy CII számla;
- XRechnung 3.0 Extension: UBL Invoice vagy CII számla.

Az XRechnung CVD-t és az elavult XRechnung UBL Credit Note Extensiont a csomag elutasítja. A core
írói folyamatvezérlés nélküli számlára `urn:cen.eu:en16931:2017` értéket írnak: ez sima
EN 16931, egyik fenti sem, ezért állítsd be:

```php
use IntrAgile\EInvoicing\Model\ProcessControl;
use IntrAgile\EInvoicing\Syntax\Syntax;
use IntrAgile\EInvoicing\Syntax\XRechnung;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Peppol\PeppolBis3;

// Peppol BIS Billing 3.0
new Invoice(
    // ...
    processControl: new ProcessControl(
        'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0',
        PeppolBis3::PROCESS,
    ),
);

// XRechnung 3.0, a send($invoice) UBL-ként küldi, CII-ként így:
$invoice = new Invoice(/* ... */ processControl: XRechnung::processControl());
EInvoicing::send(OutboundDocument::fromInvoice($invoice, syntax: Syntax::Cii));
```

Minden más dokumentumot már maga a `send()` elutasít: `UnsupportedDocument` kivételt dob az
okkal, és semmit sem tárol. Ez kikapcsolt validációnál is így van.

### Validáció

Bekapcsolt validációnál (ez az alapértelmezés) a `SendDocument` job még a sor lefoglalása előtt
validálja a dokumentumot: a Peppol BIS dokumentumokat a telepített Peppol-szabályokkal, az
XRechnung dokumentumokat az `intragile/einvoicing-rules-xrechnung` KoSIT-szabályaival (ezt a
csomag függőségként behúzza). Az érvénytelen dokumentum `failed` állapotba kerül `validation`
kategóriával és 0 `attempts` értékkel, és semmi sem hagyja el a szervert. Ha egy szabálykészlet
hiányzik, a sor `queued` marad, a job pedig az okkal együtt elbukik: telepítsd a szabályokat,
majd sorold be újra az `einvoicing:resolve ID --resend` paranccsal.

Saját szabályokhoz köss be egy validátort a containerbe; a job és az `EInvoicing::transport()`
ekkor ezt használja a Peppol-szabályok helyett:

```php
use IntrAgile\EInvoicing\Validation\DocumentValidator;

$this->app->singleton(DocumentValidator::class, fn () => new MyValidator());
```

Az alapértelmezett validátor létrehozáskor betölti a Peppol BIS szabályokat akkor is, ha csak
XRechnung dokumentumot küldesz, ezért az `einvoicing:install-rules` ilyenkor is kell. Kikapcsolt
validációnál (`validation.enabled` hamis) a bekötött `DocumentValidator` nem fut. Az
`EInvoicing::transport()` által már felépített transport azt a validátort tartja meg, amellyel
felépült, ezért a sajátodat az első hívás előtt kösd be, például egy service provider
`register()` metódusában.

Az `EInvoicing::transport()` minden dokumentumot validáló transportot ad vissza, az
`EInvoicing::transport($name, validated: false)` a nyers drivert. Ha magad validálnál egy
dokumentumot, például egy fogadottat, az `EInvoicing::xrechnung()->validate($xml)` és az
`EInvoicing::facturx()->validate($xml)` core `ValidationReport`-ot ad vissza.

### Állapotok

```
send() ─► queued ─► sending ─► pending ─► delivered
             ▲         │          └─────► failed
             │         ├─► failed
             └─retry───┤
                       └─► unconfirmed ─► (operátor) einvoicing:resolve
```

| Állapot | Jelentés | Teendő |
|---|---|---|
| `queued` | Eltárolva, küldő jobra vár | Nincs; egy worker felveszi |
| `sending` | Egy job lefoglalta; a kérés úton lehet | Nincs; továbblép, vagy a foglalás lejártakor `unconfirmed` lesz |
| `pending` | Az access point átvette; a címzetthez kézbesítés még nincs visszaigazolva | Nincs; a státuszlekérdezés és a webhook lezárja |
| `delivered` | Kézbesítve a címzettnek (végállapot) | |
| `failed` | Biztosan nem lett kézbesítve (végállapot); lásd `failure_category`, `failure_message`, `provider_code` | Javítsd az okot, majd szükség esetén küldd újra: `einvoicing:resolve ID --resend` |
| `unconfirmed` | A kimenetel ismeretlen: az access point **lehet**, hogy átvette a dokumentumot | Nézd meg az access pointban, majd `einvoicing:resolve` (lásd lent) |

Egy sikertelen küldést az alapján kezel a csomag, amit a hiba bizonyít:

- **Biztosan nem vették át** (validációs hiba, elutasított dokumentum, elérhetetlen címzett,
  hibás hozzáférési adatok): `failed`.
- **Biztonságosan újrapróbálható** (`TransportUnavailable`, `RateLimited`): vissza `queued`
  állapotba, és újraküldés a `Retry-After` vagy a `sending.backoff` szerinti idő után, legfeljebb
  `sending.max_attempts` alkalommal; utána `failed`, `transport` kategóriával.
- **Minden más** (nincs válasz, 503-tól eltérő 5xx, olvashatatlan 200-as válasz, bármilyen
  váratlan kivétel): `unconfirmed`. Ezt a csomag soha nem küldi újra magától, mert egy újabb
  küldés kétszer juttathatná el a számlát.

A küldő jobok `$tries` értéke 1: a queue sosem ismétel meg egy küldést. Ha egy worker küldés
közben leáll, a sor `sending` állapotban marad, majd egy késleltetett `ExpireSendingClaim` job
`unconfirmed` állapotba teszi.

### Egy `unconfirmed` küldés rendezése

Keresd meg a dokumentumot az access pointban, címzett és számlaszám alapján, majd:

- Recommand: a cég dokumentumai a felületen vagy az API-ban.
- B2Brouter: a fiók számlái; a dokumentum-azonosító a számla azonosítója (számjegyek). A B2Brouter
  API-dokumentációja szerint a fiókban már meglévő számlaszám újraküldését duplikátumként
  elutasítja.
- e-invoice.be: a piszkozatok és az elküldött dokumentumok; a dokumentum-azonosító `doc-…`. A még
  `DRAFT` állapotú dokumentumot feltöltötték, de nem küldték el: vagy küldd el az e-invoice.be-nél,
  és használd a `--sent` kapcsolót, vagy a `--resend` kapcsolót, amely új piszkozatot tölt fel.



```bash
php artisan einvoicing:resolve 42 --sent=<dokumentum-azonosító>   # átvették: pending, innentől lekérdezve
php artisan einvoicing:resolve 42 --resend                         # nem vették át: újra sorba állítja (megerősítést kér)
php artisan einvoicing:resolve 42 --resend --force                 # ugyanez kérdés nélkül, pl. szkriptből
```

A `--resend` `failed` és `queued` sorra is használható. A próbálkozásszámlálót sosem nullázza,
így egy `sending.max_attempts` után `failed` sor **egy** új próbát kap, várakozás nélkül; ha az
is elbukik, futtasd újra. Egy sor, amely a küldő job időkorlátjánál 30 másodperccel tovább
marad `sending` állapotban, elhagyottnak számít, és az `unconfirmed` sorhoz hasonlóan rendezhető
(ez `sync` kapcsolaton számít, ahol a foglalás lejárata nem ütemezhető). Ez csak akkor igaz, ha
a foglaló workert az időkorlátnál leállították, ehhez pedig `pcntl` kell (lásd:
[Queue](#queue)).

### Események

| Esemény | Mikor |
|---|---|
| `DocumentSent` | a transport átvette a dokumentumot, bármi is a kézbesítési állapota |
| `DocumentDelivered` | a dokumentum eljutott a címzetthez |
| `DocumentFailed` | a dokumentum biztosan nem lett kézbesítve |
| `DocumentUnconfirmed` | a küldés kimenetele ismeretlen; operátornak kell rendeznie |
| `DocumentReceived` | egy bejövő dokumentum eltárolva (`$event->message->xml`) |
| `WebhookReceived` | egy ehhez a szolgáltatói fiókhoz tartozó, aláírt webhook ellenőrizve, még a feldolgozása előtt (ismételt kézbesítésnél újra lefut; a `$event->event->deduplicationKey` azonosít egy kézbesítést) |

Mind az `IntrAgile\EInvoicing\Laravel\Events` névtérben van; a dokumentum-események a
`$message` tulajdonságban adják át az `OutboundMessage` vagy `InboundMessage` modellt.

```php
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;

Event::listen(function (DocumentDelivered $event) {
    Order::where('invoice_number', $event->message->reference)->update(['einvoice' => 'delivered']);
});
```

Amíg egy dokumentum `pending`, a csomag a `status.delays` szerint lekérdezi az állapotát,
legfeljebb `status.max_attempts` alkalommal; utána abbahagyja, és a sor `pending` marad, amíg
egy webhook le nem zárja. Ha egy státuszlekérdezés transporthibán kívül mást dob, annak a
dokumentumnak a lekérdezése végleg leáll; az okot a sikertelen job mutatja.

## Fogadás

A bejövő dokumentumok webhookon vagy lekérdezéssel érkeznek, és mindkét út ugyanoda fut ki. Az
`einvoicing_inbound_documents` tábla egy sora előbb `notified`, aztán `fetched` (az XML
eltárolva), végül `acknowledged` (az access point értesítve). A `DocumentReceived` dokumentumonként
egyszer fut le, az adatbázis-commit után, és a nyugtázás ezt követi.

```php
use IntrAgile\EInvoicing\Laravel\Events\DocumentReceived;
use IntrAgile\EInvoicing\Syntax\Ubl\UblReader;

Event::listen(function (DocumentReceived $event) {
    $invoice = (new UblReader)->read($event->message->xml)->invoice;
    // ...
});
```

**A `DocumentReceived` legfeljebb egyszer fut le.** Ha a folyamat a commit és az esemény között
leáll, vagy egy listener kivételt dob, a dokumentum ettől még nyugtázva lesz, és az esemény nem
ismétlődik. Azokat a listenereket, amelyeknek egyetlen dokumentumot sem szabad elszalasztaniuk,
tedd sorba (`ShouldQueue`), és egyeztess a táblából: minden eltárolt dokumentum egy `fetched`
vagy `acknowledged` sor az XML-lel együtt.

Az olvashatatlan dokumentum (DOCTYPE, hibás XML) `notified` állapotban marad a `last_error`
oszlopban rögzített okkal, és nem kerül nyugtázásra. Az újrapróbálható hibával elbukott
letöltést vagy nyugtázást (és az ismeretlen kimenetelű nyugtázást) a csomag a `sending.backoff`
szerint, legfeljebb `sending.max_attempts` alkalommal próbálja újra; minden más letöltési hiba,
és ami az utolsó kísérlet után is megmaradt, a következő lekérdezésre vár, amely minden
alkalommal még egy kísérletet indít, amíg az access point listázza a dokumentumot.

Minden elbukott letöltés és nyugtázás dokumentumonként egyetlen keretből fogy, ez az
`inbound.max_fetch_attempts` (alapértelmezés 10, 1 és 65535 között). Ha egy dokumentum elhasználta,
a csomag többé nem tölti le és nem nyugtázza automatikusan: a lekérdezés átugorja és elakadtként
jelzi, az `einvoicing:doctor` pedig figyelmeztet rá. Nézd meg a `last_error` értékét, hárítsd el
az okot (gyakran az access pointnál), majd futtasd a
`php artisan einvoicing:poll-inbound --include-capped` parancsot, amely minden elakadt, az access
point által még listázott dokumentumnak pontosan egy újabb kísérletet ad.

### Lekérdezés

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('einvoicing:poll-inbound')->everyFiveMinutes()->withoutOverlapping();
// több transportnál transportonként egy sor:
// Schedule::command('einvoicing:poll-inbound --transport=other')->everyFiveMinutes();
```

Minden lekérdezés rögzíti az új dokumentumokat, és sorba állítja a feldolgozásukat. Ha az access
point még listáz egy dokumentumot, amelynek a sora elakadt (`notified` vagy `fetched`), azt is
újra sorba állítja, így a lekérdezés a sikertelen job vagy `sync` kapcsolat miatt elakadt
dokumentumokat is helyrehozza. Webhook mellett is érdemes ütemezni.

### Webhookok

A csomag egyetlen útvonalat regisztrál minden transportnévhez:
`POST /einvoicing/webhooks/{transport}` (neve: `einvoicing.webhook`). Értesítést csak beépített
driverű (vagy `EInvoicing::extendWebhook()`-kal regisztrált parserű saját driverű),
`webhook_secret`-tel rendelkező transporthoz fogad el; minden más név `404`-et kap. Beállítás:

1. Futtasd a `php artisan einvoicing:doctor` parancsot. A `transport <név>: webhook` sor akkor
   írja ki a teljes URL-t (`https://<alkalmazásod>/einvoicing/webhooks/<név>`, ahol a `<név>` a
   transport kulcsa az `einvoicing.transports` alatt, nem a driver neve), ha a transportnak van
   webhook secretje, és a webhookok be vannak kapcsolva; különben figyelmeztetést ad arról, mi
   hiányzik. Egy hiányzó kötelező kulcs (Recommandnál `company_id`, B2Brouternél `account_id`)
   miatt a `config` sor jelez hibát.
2. Vedd fel ezt az URL-t a szolgáltatónál, és a tőle kapott secretet írd a transport
   `webhook_secret` beállításába:

   | Driver | Hol veszed fel | Események | Secret |
   |---|---|---|---|
   | `recommand` | webhookként a Recommandban | a dokumentumeseményei | a webhook aláíró `secret`-je → `RECOMMAND_WEBHOOK_SECRET` |
   | `b2brouter` | web hookként a B2Brouterben (API: `POST /web_hooks`) | `issued_invoice.state_change`, `received_invoice.created` | a web hook aláíró secretje (az API-ban `signing_secret`, a B2Brouter útmutatójában `signature_key`) → `B2BROUTER_WEBHOOK_SECRET` |
   | `einvoicebe` | webhookként az e-invoice.be API-n keresztül (`POST /api/webhooks/`) | `document.received`, `document.sent`, `document.sent.failed` | a létrehozó válasz `secret` mezője → `EINVOICEBE_WEBHOOK_SECRET` |

   Az értesítéseket a csomag a beállított fiókkal veti össze: Recommandnál a `company_id`,
   B2Brouternél az `account_id`, e-invoice.be-nél a `tenant_id`, ha meg van adva. A más fiókot
   megnevező értesítést figyelmen kívül hagyja; azt, amelyik nem nevez meg fiókot, vagy olyan
   transporthoz érkezik, amelynek nincs beállított fiókja, alkalmazza.
3. Más teendő nincs: az útvonal nem futtatja a `web` middleware-csoportot, így sem CSRF-token,
   sem session nem kell hozzá, és CSRF-kivételt sem kell felvenni.

A kéréstörzset pontosan úgy add át, ahogy megérkezett; minden driver parsere a saját
szolgáltatója módján ellenőrzi, a webhook secrettel kulcsolt HMAC-SHA256-tal: a Recommand a nyers
törzsön, a B2Brouter a `<t>.<nyers törzs>` szövegen (az aláírásfejléc időbélyege, egy pont, majd
a nyers törzs), az e-invoice.be pedig az esemény kanonikus JSON-alakján, nem a nyers törzsön.
A válaszok törzse üres: `401` hiányzó vagy hibás aláírásra, `404` webhook secret nélküli
transportra, minden másra `204`, a figyelmen kívül hagyott értesítésekre is
(másik szolgáltatói fiók, ismeretlen dokumentum, nem dokumentumra vonatkozó esemény), hogy az
access point ne próbálja újra őket. Az ismételt vagy rossz sorrendben érkező értesítések
ártalmatlanok: a bejövő dokumentum azonosítónként egyszer tárolódik, a `delivered` és `failed`
állapot pedig már nem változik. Az az értesítés, amely csak annyit mond, hogy a kézbesítés
állapota változott, de azt nem, hogy mire (az e-invoice.be `document.sent.failed` eseménye, vagy
egy nem Peppol csatornás Recommand-esemény), egy további státuszlekérdezési láncot indít minden
általa megnevezett `pending` dokumentumra. Ez a lánc a rendes lekérdezésekkel közös
`status_checks` számlálót használ, így együtt sem lépik túl a `status.max_attempts` keretet. Ha a
keret elfogyott, a `status.poll` ki van kapcsolva, vagy `sync` queue-kapcsolaton, nem történik
lekérdezés, és a sor `pending` marad, ahogy az utolsó rendes státuszlekérdezés után is. Az ilyen
sort az access pointnál ellenőrizd: az `einvoicing:resolve` nem kezeli a `pending` sort (az
`unconfirmed` és az elhagyott `sending` sort rendezi).

A B2Brouter `X-B2Brouter-Delivery-Id` fejlécét, amelyet a core `deduplicationKey`-ként ad át a
`WebhookReceived` listenereidnek (maga a csomag nem szűr rá ismétlést), a B2Brouter aláírása
nem fedi le, a `webhook_tolerance` pedig alapból ki van kapcsolva, így egy elfogott értesítés
később újra beküldhető. Az újrajátszás csak olyan állapotot ismételhet
meg, amelyet a B2Brouter valóban jelentett, a `delivered` és `failed` állapot pedig már nem
változik. Állítsd be a `webhook_tolerance` értékét, ha az újrajátszás fontosabb szempont a késői
újrapróbálásoknál (lásd a core TRANSPORT.md leírását). A webhookokat élesben nem teszteltük,
ehhez nyilvános HTTPS-végpont kellene; az aláírásokat a szolgáltatók dokumentált sémája szerint
offline ellenőrizzük (az e-invoice.be-nél a közzétett tesztvektorral is).

**Védd az útvonalat.** Saját sebességkorlátja és törzsméret-korlátja nincs. Adj meg throttle-t az
`einvoicing.webhooks.middleware` beállításban, például `['throttle:120,1']`, a kérés törzsének
méretét pedig korlátozd a webszerverben (nginxben `client_max_body_size`, Apache-ban
`LimitRequestBody`). Az előtag az `einvoicing.webhooks.prefix`; az
`einvoicing.webhooks.enabled = false` eltávolítja az útvonalat.

Ha egy kézbesítési státuszról szóló webhook még azelőtt érkezik, hogy a küldő job eltárolta
volna az access point dokumentum-azonosítóját, nem talál sort, és elvész. Valódi queue mellett a
státuszlekérdezés pótolja; `sync` kapcsolaton semmi, ezért ezeket a sorokat kézzel kell
ellenőrizni.

## Francia e-számlázás (B2Brouter)

Egy `b2brouter` transport francia plateforme agréée-ként is használható. Nevezd meg az
`einvoicing.france.transport` beállításban (`EINVOICING_FRANCE_TRANSPORT`); az
`einvoicing.transports` alatt `b2brouter` driverrel kell szerepelnie, és a kulcsát, account id-ját,
base URL-jét és az `einvoicing.http` beállításokat ugyanúgy használja, mint a Peppol-küldés. Az
`intragile/php-einvoicing` 1.1 vagy újabb kell hozzá.

> **Élőben (a B2Brouter sandboxban) tesztelve: a címtár-lekérdezés, a küldés, az életciklus, a
> vevői jóváhagyás és a fizetés; az elfogadott beküldés csak offline tesztelt.**

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\BuyerResponse;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\FrenchDocument;

$result = EInvoicing::france()->send(FrenchDocument::fromXml($ublOrCiiXml));
$status = EInvoicing::france()->lifecycle($result->documentId);   // PlatformStatus, CDV-kód: ->lifecycle
EInvoicing::france()->respond($receivedInvoiceId, BuyerResponse::approved());
EInvoicing::france()->recordPayment($issuedInvoiceId);           // teljes összeg kifizetve
$deposit = EInvoicing::france()->depositEReport($flux10Xml);      // EReportDeposit, ->ledgerId
```

Az `EInvoicing::france($transport)` és az `EInvoicing::annuaire($transport)` név szerint egy
másik B2Brouter transportot is elfogad; hiányzó vagy nem B2Brouter transportra
`ConfigurationException` jön, amely megnevezi az `einvoicing.france.transport` kulcsot. Ezek a
kliensek valódi kéréseket küldenek: az `EInvoicing::fake()` nem cseréli le őket, és semmi nem kerül
sorba vagy adatbázisba. A klienseket a csomag transportnévenként a folyamat teljes élettartamára
gyorsítótárazza, ezért a hosszan futó folyamatokat (Octane, queue workerek) konfigurációváltozás
után újra kell indítani. Az összes metódus leírása a core
[FRANCE.md](https://github.com/intragile/php-einvoicing/blob/main/docs/countries/FRANCE.md)
dokumentumának „Sending through a plateforme agréée (B2Brouter)” szakaszában van.

**Útválasztás.** A core `France\Routing\Annuaire` interfésze csak akkor kötődik az adott transport
élő `B2BrouterAnnuaire`-jához, ha az `einvoicing.france.transport` be van állítva; így a router a
konténerből feloldható (az alkalmazás saját `Annuaire`-kötése megmarad):

```php
use IntrAgile\EInvoicing\France\Routing\Router;

$decision = app(Router::class)->route($invoice);   // RoutingDecision: ->channel, ->needsDirectoryCheck
```

A hideg Annuaire-lekérdezést a csomag megismétli, amíg a B2Brouter még dolgozik rajta: legfeljebb
`einvoicing.france.annuaire_attempts` kérés (alapértelmezés 4), `annuaire_retry_delay`
másodpercenként (alapértelmezés 3), így a `route()` nagyjából kilenc másodpercig is blokkolhat; ez
csak az ismételt lekérdezés esete, egy beragadt lekérdezés egy HTTP-timeoutnyi időbe kerül
(`einvoicing.http`), mielőtt `Unknown` lesz. A SIREN-ben lévő szóközöket a csomag figyelmen kívül
hagyja. A helyi ellenőrzésen elbukó SIREN, a B2Brouter által `FR_ASSUJETTI_UNKNOWN`-nak jelölt cég,
az utolsó próbálkozás után is feldolgozás alatt álló lekérdezés és az elérhetetlen címtár
`Unknown`-t ad; a router ekkor megtartja
az e-számlát, és címtár-ellenőrzést kér. **A `route()` így is dobhat kivételt:**
`InvalidArgumentException`-t, ha a B2Brouter elutasítja a SIREN-t (HTTP 422), és
`AuthenticationFailed`-et, ha az API-kulcsot utasítja el (HTTP 401/403). Fogd el mindkettőt a
`route()` körül, ha egy elutasított lekérdezés nem állíthatja meg a folyamatot.

**Webhookok.** A B2Brouternél regisztráld az `issued_invoice.state_change`, a
`received_invoice.state_change` és a `ledger.state_change` eseményt is. A szokásos feldolgozás után
a webhook ezeket az eseményeket váltja ki:

| Esemény | Mikor |
|---|---|
| `LifecycleStatusChanged` | az értesítés francia életciklus-státuszt hoz: `$transport`, `$documentId` (a B2Brouter számla-id), `$status` (`LifecycleStatus`), `$received` (true a kapott számlánál, amelynek státusza a saját válaszod) |
| `EReportStateChanged` | egy beküldött e-reporting fájl állapota változott: `$transport`, `$ledgerId`; az állapotot az `EInvoicing::france($event->transport)->eReportStatus($event->ledgerId)` olvassa ki |

Mindkettő kézbesítésenként egyszer fut, így egy ismételt vagy késő értesítés újra kiváltja őket;
cselekvés előtt vesd össze azzal, amit már eltároltál. Akkor is lefutnak, ha az
`einvoicing.france.transport` nincs beállítva: a `LifecycleStatusChanged` nem francia B2Brouter
fióknál is kiváltódik, mert az életciklus-státusz a számla `data.state` mezőjéből származik. Egy
`ledger.state_change` webhook mostantól a `WebhookReceived` eseményig is eljut, `Other` típusú
eseményként, amelynek dokumentum-id-ja a ledger id.
Amikor egy rögzített fizetés `paid` állapotba viszi a számlát, a B2Brouter nem küld értesítést
(a sandboxban megfigyelve), így a `Collected` sosem érkezik `LifecycleStatusChanged`-ként: a
`recordPayment()` visszatérési értékéből vagy a `lifecycle()`-ből olvasd ki.

**Doctor.** Beállított `einvoicing.france.transport` mellett az `einvoicing:doctor` egy `france`
sort is kiír: a transportnak érvényes B2Brouter transportnak kell lennie, és a sor megmondja, hogy
a kulcsa sandbox (`test_`), staging (`stag_`) vagy éles kulcs. Kérést nem küld.

## Lengyel KSeF

Az `EInvoicing::ksef()` FA(3) számlákat küld a KSeF-be, a lengyel nemzeti e-számlázási rendszerbe
(KSeF API 2.0), queue-n keresztül, az `einvoicing:poll-inbound --transport=ksef` pedig fogadja a
neked kiállított számlákat. A sorok, állapotok és események ugyanazok, mint Peppolnál. Az
`intragile/php-einvoicing` 1.2-es vagy újabb verziója kell hozzá. A core
[POLAND.md](https://github.com/intragile/php-einvoicing/blob/main/docs/countries/POLAND.md)
leírása (angolul) részletesen bemutatja az FA(3) modellt, a KSeF klienst és a korlátait.

> **Élesben tesztelve a KSeF TE tesztkörnyezetben, e csomagon keresztül:** egy sorba állított
> küldés, amely KSeF-számmal és nyugtával (UPO) `delivered` állapotba jut, és a vevő
> postafiók-lekérdezése, amely `DocumentReceived` eseményt vált ki (2026-11-22, PHP 8.4 és 8.5).
> Az offline számlák, a határidők, a tanúsítványparancs és a doctor sora itt offline tesztelt; az
> offline küldést, a KSeF-tanúsítványokat és a QR-linkeket a core teszteli élesben a TE-n.

### Beállítás és hozzáférési adatok

```bash
KSEF_ENVIRONMENT=test                   # test (TE), demo vagy production; production csak ha beállítod
KSEF_NIP=5265877635                     # a kontextus, amelyre a kliens hitelesít
KSEF_CERTIFICATE_PATH=/run/secrets/ksef-seal.crt.pem
KSEF_PRIVATE_KEY_PATH=/run/secrets/ksef-seal.key.pem
# KSEF_PRIVATE_KEY_PASSPHRASE=...       # titkosított kulcshoz
# KSEF_TOKEN=...                        # tanúsítvány helyett: KSeF token
# KSEF_OFFLINE_CERTIFICATE_PATH=/run/secrets/ksef-offline.crt.pem
# KSEF_OFFLINE_PRIVATE_KEY_PATH=/run/secrets/ksef-offline.key.pem
# KSEF_OFFLINE_PRIVATE_KEY_PASSPHRASE=...
```

Ezek a `config/einvoicing.php` `einvoicing.ksef` kulcsát töltik ki:

| Beállítás | Alapérték | Jelentés |
|---|---|---|
| `environment` | `test` | `test`, `demo` vagy `production`; production csak akkor, ha beállítod |
| `nip` | — | a kontextus tízjegyű NIP-je, ellenőrző számjeggyel ellenőrizve |
| `credentials` | — | `certificate` és `private_key` (fájlútvonalak) opcionális `passphrase`-zel, **vagy** `token`; a kettő együtt nem |
| `offline_certificate` | — | a KSeF Offline tanúsítvány `certificate` és `private_key` útvonala és `passphrase`-e, amely az offline számlák KOD II-jét aláírja; ha nem használod, hagyd üresen |
| `max_response_bytes` | 67 108 864 | a memóriába olvasott KSeF-válaszok felső határa; legalább 52 428 816, a legnagyobb titkosított exportrész |
| `inbound_min_interval_minutes` | 15 | a legrövidebb idő két postafiók-lekérdezés között (0–1440; 0 kikapcsolja a védelmet) |
| `deadline_warning_hours` | 24 | milyen közel kell lennie egy offline határidőnek a `KsefOfflineDeadlineApproaching` eseményhez (1–720) |

- **A tanúsítványt és a privát kulcsot fájlból olvassuk**, sosem környezeti változóból; a
  kulcsfájlokat csak az alkalmazás felhasználója olvashassa. Minősített bélyegző vagy aláírás is
  jó, és KSeF Authentication tanúsítvány is. A titkosított kulcs jelszava környezeti változóból
  jön.
- **A KSeF token környezeti változóból jön** (`KSEF_TOKEN`), ahogy más szolgáltatók API-kulcsai.
  A KSeF csak tanúsítvánnyal hitelesített kontextusnak állít ki tanúsítványt, ezért az
  `einvoicing:ksef-certificate` tanúsítványos hozzáférést igényel.
- A hibaüzenetek a beállítást és a fájl útvonalát nevezik meg, sosem a fájl tartalmát, a jelszót
  vagy a tokent. A KSeF klienst az első használatkor építjük fel, és a folyamat élettartamára
  gyorsítótárazzuk: konfigurációváltozás után indítsd újra a queue workereket és az Octane-t. Az
  `EInvoicing::fake()` nem helyettesíti.
- A `php artisan migrate` hozzáadja a `ksef_number`, `invoicing_mode`, `offline_deadline` és `upo`
  oszlopot az `einvoicing_outbound_documents` táblához, és létrehozza az `einvoicing_ksef_cursors`
  táblát, amely a postafiók pozícióját tárolja (`DatabaseKsefCursorStore`; ha máshol tartanád,
  bindold a saját `Transport\Ksef\Inbound\KsefCursorStore` implementációdat).

### KSeF-tanúsítványok

```bash
php artisan einvoicing:ksef-certificate                         # Offline tanúsítvány, EC P-256
php artisan einvoicing:ksef-certificate --type=authentication --algorithm=rsa --name="Billing" \
    --certificate=/run/secrets/ksef-auth.crt.pem --private-key=/run/secrets/ksef-auth.key.pem
```

A parancs tanúsítványt igényel a beállított kontextusra, és a tanúsítványt meg a privát kulcsát
0600-as jogosultsággal a beállított útvonalakra írja: Offline tanúsítványnál az
`offline_certificate`, Authentication tanúsítványnál a `credentials` útvonalaira (a
`--certificate=` és a `--private-key=` más útvonalat ad meg). Az útvonalakat az igénylés előtt
ellenőrzi, és létező fájlt nem ír felül. A kulcs helyben készül, kiírásig csak a memóriában van, és
sosem jelenik meg a kimeneten; ha a típushoz jelszó van beállítva, titkosítva írja ki. A KSeF
alanyonként 100 tanúsítványt enged.

Authentication tanúsítványhoz add meg a `--certificate=` és a `--private-key=` kapcsolót: az
alapértelmezett útvonalak a `credentials` fájljai, amelyekkel maga a parancs hitelesít, ezek tehát
már léteznek, és a parancs sosem írja felül őket. Az új tanúsítvánnyal való bejelentkezéshez utána
állítsd a `credentials` útvonalait az új fájlokra.

### Küldés

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;

$message = EInvoicing::ksef()->queue($invoice);                    // Invoice vagy FA(3) fájl
$message = EInvoicing::ksef()->queue($fa3Xml, offline: true);       // offline módban kiállítva
$message = EInvoicing::ksef()->queue($invoice, reference: 'FV/2026/11/0042');
```

A `queue()` a pontos bájtokat az `einvoicing_outbound_documents` táblába írja (`transport` és
`process` `ksef`, `document_type` `FA (3)`, `sender` az eladó NIP-je, `receiver` a vevő), és a
nyitott tranzakció commitja után elindítja a `SendKsefInvoice` jobot. A job az FA(3) séma szerint
validálja a számlát, elküldi, és elindítja a `PollKsefStatus` jobot, amely a `status.delays` és a
`status.max_attempts` szerint fut:

- **200-as státusz:** a sor `delivered` lesz a `ksef_number`-rel és a KSeF által jelzett
  `invoicing_mode`-dal (`Online` vagy `Offline`; a mai napnál korábbi keltű online küldést a KSeF
  offline-nak minősíti), majd a nyugta az `upo` oszlopba kerül. Ha a KSeF még nem állította ki,
  egy későbbi futás tölti le.
- **Elutasítás:** a sor a KSeF kódjával és üzenetével `failed` lesz, és `DocumentFailed` esemény
  fut.
- **Az ismeretlen kimenetelű küldést aszinkron queue-n megismételjük**, a Peppoltól eltérően: a
  második példányt a KSeF duplikátumként jelzi, így kétszer nem fogadhatja be. Közben a sor
  `provider_code` értéke `outcome_unknown`. Szinkron kapcsolaton (`sync`, `deferred`,
  `background`) nem ismételjük: a sor azonnal **unconfirmed** lesz; az `einvoicing:resolve`
  paranccsal rendezd.
- **Ismeretlen kimenetel után** az ismételt példány olyan elutasítása, amely az első küldésről
  semmit nem mond (küldéskor, vagy 405-ös, 435-ös, 500-as vagy 550-es végleges státusz), a sort
  `failed` helyett **unconfirmed** állapotba teszi, és megtartja az `outcome_unknown` jelölést: az
  első küldést a KSeF befogadhatta, ezért újraküldés vagy újbóli kiállítás előtt keresd meg a
  számlát a KSeF-ben. A validációs hibát (430, 450) a bájtok döntik el, ezért az továbbra is
  `failed` állapotba teszi a sort.
- **Duplikátumok (440-es státusz).** A KSeF akkor jelez duplikátumot, ha azonos eladói NIP-pel,
  számlatípussal és számlaszámmal (`P_2`) már befogadott egy számlát, a tartalmától függetlenül. A
  sor csak akkor lesz **delivered** az eredeti KSeF-számmal, ha az eredeti nyugtája igazolja, hogy
  az eredeti pontosan ez a fájl (azonos dokumentum-hash); ilyenkor a `provider_code` `440`, és az
  eredeti nyugtáját tároljuk, ha olvasható. Ha az eredeti egy másik fájl, a sor `duplicate` kategóriával
  **failed** lesz. Ha nem dönthető el (a nyugtát elutasítják vagy még nincs kiállítva), a következő
  futás újra megkérdezi, az utolsó futás után pedig a sor **unconfirmed** lesz, operátori döntésre
  (`einvoicing:resolve`). Találgatásra sosem lesz delivered.

> **`sync` kapcsolaton a `queue()` blokkol.** A küldés és a státuszlekérdezés a hívásban fut: a
> core kliens lekérdezési korlátján belül, nagyjából 60 másodpercig vár a végleges státuszra, és
> egyszer megpróbálja letölteni a nyugtát. Élesben aszinkron queue-t használj (lásd:
> [Queue](#queue)).

- **A státuszkövetéshez kell az `einvoicing.status.poll`.** A KSeF-nek nincs webhookja, így
  kikapcsolt lekérdezés mellett a KSeF-sorok `pending` állapotban maradnak, KSeF-szám és nyugta
  nélkül.
- **Ha a státuszlekérdezések elfogynak** (`status.max_attempts`), miközben a KSeF még dolgozik,
  vagy a szinkron várakozás végleges státusz nélkül ér véget, a sor `pending` marad, és nem fut
  esemény. Keresd azokat a `pending` KSeF-sorokat, amelyeknél a `status_checks` elérte a maximumot.

### Fogadás

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('einvoicing:poll-inbound --transport=ksef')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('einvoicing:ksef-deadlines')->hourly();
```

A lekérdezés a beállított NIP vevői postafiókját olvassa a tárolt pozíciótól, és minden új számlát
bejövő sorként rögzít; a meglévő jobok letöltik, tárolják és nyugtázzák, a `DocumentReceived`
pedig egyszer fut le, ahogy Peppolnál. Az első lekérdezés az utolsó 30 napot olvassa.

- **A lekérdezés védett.** A KSeF kontextusonként és IP-címenként óránként 20
  metaadat-lekérdezést enged, és a
  Pénzügyminisztérium azt ajánlja, hogy egy alanyt legfeljebb 15 percenként kérdezz le. Ha egy
  futás az előző indulása után az `inbound_min_interval_minutes`-nél hamarabb indul (egy perc
  türelmet hagyva az ütemező pontatlanságára), kihagyjuk, és ezt ki is írjuk. Az indulás idejét az
  alapértelmezett cache store tárolja. Az intervallum legalább 4 perc legyen: a fix egy perc türelem
  miatt egy kisebb érték a beállítottnál gyakoribb futást enged (3 perces intervallumnál 2
  percenként, ami több a KSeF óránkénti 20 lekérdezésénél).
- **A bejövő azonosítók** `<KSeF-szám>@<tárolási dátum>` alakúak, így a letöltéshez nem kell
  metaadat-lekérdezés; az a számla, amelyet a tárolt ablak metaadat nélkül sorol fel, a sima
  KSeF-számát kapja. Mindkét alak ugyanahhoz a sorhoz tartozik. Ha a saját oldaladon szűröd a
  duplikátumokat, a `KsefTransport::ksefNumberOf($message->provider_document_id)` értékét használd.
- **A nyugtázás viszi tovább a tárolt ablakot.** Az ablak csak akkor lép tovább, ha minden benne
  lévő számla nyugtázva van, ezért KSeF-hez hagyd bekapcsolva az `einvoicing.inbound.acknowledge`
  beállítást: nélküle a lekérdezés újra és újra ugyanazt az ablakot olvassa. Ha két worker
  egyszerre nyugtáz, felülírhatják egymás írását; ha a lekérdezés olyan számlát listáz, amelynek a
  sora már nyugtázott, újra nyugtázza, így a következő lekérdezés helyreállítja az elveszett
  nyugtázást.
- A vevői postafiók néhány perccel a befogadás után listázza a számlát (a TE-n kb. két perccel).

### Események

A dokumentumesemények a KSeF-soroknál ugyanúgy futnak, mint Peppolnál: `DocumentSent`, amikor a
KSeF feldolgozásra befogadta a számlát, `DocumentDelivered` a KSeF-számmal, `DocumentFailed`,
`DocumentUnconfirmed`, és `DocumentReceived` a bejövő számlánál. Egy új esemény is van:

| Esemény | Mikor |
|---|---|
| `KsefOfflineDeadlineApproaching` | egy még úton lévő offline számlának (queued, sending, pending vagy unconfirmed, vagy olyan okból failed, amely nem zárja le, például szállítási hiba miatt) a `$deadline`-ig (`DateTimeImmutable`, a nap vége Europe/Warsaw időben) el kell jutnia a KSeF-be, és ez a határidő a `deadline_warning_hours`-on belül van vagy már elmúlt; a `$message` a sor. Az `einvoicing:ksef-deadlines` minden futáskor kiváltja, amíg ez fennáll, ezért jegyezd meg, miről értesítettél már |

### Offline mód és határidők

Az olyankor kiállított számlát, amikor a KSeF nem használható, `offline: true` paraméterrel állítsd
sorba; a KSeF offline-ként rögzíti, és egy határidőig el kell jutnia hozzá. A sor azonnal megkapja
az offline24 határidejét (a `P_1` utáni első munkanap). Az `einvoicing:ksef-deadlines` felsorolja a
még nem delivered offline sorokat, a Latarnia (a KSeF elérhetőségi szolgáltatása)
által bejelentett üzemzavarokkal újraszámolja a határidőket (egy üzemzavar a vége utáni 7.
munkanapra tolja; teljes üzemzavar megszünteti a kötelezettséget, és amíg egy üzemzavar tart, a
határidő üres), a sorra írja, és kiváltja a `KsefOfflineDeadlineApproaching` eseményt. Ha a
Latarnia nem érhető el, vagy DEMO környezetben, amelyhez nincs Latarnia, üzemzavarok nélkül számol,
és ezt jelzi. A munkanapok a hétvégéket és a lengyel törvényes ünnepnapokat hagyják ki (2025 és
2099 között).

Az olyan okból `failed` sor, amely nem zárja le a számlát (például szállítási hiba egy
KSeF-üzemzavar alatt), a listában marad „failed, resend with einvoicing:resolve --resend”
jelöléssel, és az esemény is kiváltódik rá: a számlának ugyanúgy el kell jutnia a KSeF-be a
határidőig. A validációs hiba, a duplikátum (`duplicate` kategória) vagy a nem támogatott
dokumentum miatt `failed` sorok kimaradnak.

**A határidők számítási segédletek, nem jogi tanácsadás.** Minden offline sort offline24
számlaként kezelünk; a ténylegesen használt mód szabályait ellenőrizd.

### QR-kódok

Az FA(3) számla vizualizációján KOD I szerepel, offline számlán az Offline tanúsítvánnyal aláírt
KOD II is. Mindkét linket a core állítja elő; képként kirajzolni opcionális (a
`ChillerlanQrRenderer`-hez a `chillerlan/php-qrcode` ^5 kell):

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Transport\Ksef\Qr\KsefQrCodes;

$ksef = EInvoicing::ksef();
$environment = $ksef->settings()->environment;

$kod1 = KsefQrCodes::online($environment, $message->xml, $message->ksef_number);   // ->url, ->label

[$kod1, $kod2] = KsefQrCodes::offline(
    $environment,
    $ksef->ksefConfig()->context,
    $message->xml,
    $ksef->offlineCertificate(),        // einvoicing.ksef.offline_certificate
    $message->ksef_number,              // null, amíg a KSeF nem ad számot: "OFFLINE" felirat
);
```

A soron tárolt pontos bájtokat használd: a linkek ezek hash-ét tartalmazzák.

### Doctor

Ha az `einvoicing.ksef` használatban van, az `einvoicing:doctor` `ksef` sorokkal bővül: beolvassa
és ellenőrzi a beállításokat és a hozzáférési adatokat, a tanúsítványoknak olvashatónak és
érvényesnek kell lenniük (a 30 napon belül lejáró Offline tanúsítvány figyelmeztetés), a
kurzortáblának léteznie kell, és az `einvoicing.transports` alatti `ksef` nevű transportot
eltakartként jelzi (`--live` mellett az inboxát sem olvassa). **Alapból lekérdezi a környezet
nyilvános Latarnia-állapotát**, hitelesítés nélküli kéréssel, 5 másodperces időkorláttal; a hiba
csak figyelmeztetés. Magát a KSeF-et sosem hívja, `--live` mellett sem. A `retry_after`
ellenőrzése a KSeF-jobok időkorlátját is figyelembe veszi (lásd: [Queue](#queue)).

### Korlátok

- **Csak FA(3).** FA_PEF (PEF (3)) számlát csak Peppol-szolgáltató küldhet, ezt nem támogatjuk; a
  kötegelt küldés, a technikai javítás és az export a core kliensen érhető el
  (`EInvoicing::ksef()->transport()`), a queue-n keresztül nem.
- **Alkalmazásonként egy kontextus:** az `einvoicing.ksef` egy NIP-et nevez meg.
- **Olvasási keret:** kontextusonként és IP-címenként óránként 20 metaadat-lekérdezés; tartsd meg a
  15 perces lekérdezést.
- **Letöltési keret:** kontextusonként és IP-címenként óránként 64 számlaletöltés. Minden letöltés
  egyet felhasznál, és a korlátozott (rate limited) letöltés is beleszámít az
  `inbound.max_fetch_attempts` értékébe, így az óránkénti 64-nél jóval több számla a sorokat a
  felső határig viheti; ezeket az `einvoicing:poll-inbound --transport=ksef --include-capped`
  paranccsal próbáld újra.
- **Online munkamenetek:** a core egy folyamat első küldésekor nyit egyet, és lejárat előtt
  lecseréli; a híd nem zárja le. A nyugtát nyitott munkamenet mellett töltjük le (ezt a TE-n
  végzett éles futtatás megerősíti).
- **Az offline határidő-segéd** minden offline sort offline24-ként kezel, és csak a Latarnia
  által bejelentett üzemzavarokról tud.

## Spanyol VERI\*FACTU

Az `EInvoicing::verifactu()` a spanyol számlák VERI\*FACTU nyilvántartási rekordjait kezeli a
spanyol adóhatóság (AEAT) felé: a core modell EN 16931 szerinti számlájából előállítja a rekordot
(`RegistroAlta`, `RegistroAnulacion`), SHA-256 `Huella`-val láncba fűzi az adatbázisban, azonnal
visszaadja a QR URL-t, hogy a számla rögtön nyomtatható legyen, és a queue-n át, az AEAT
forgalomszabályozását betartva beküldi. Az AEAT felőli oldalt a B2Brouter is átveheti,
aszinkron módon. Az `intragile/php-einvoicing` 1.3-as vagy újabb verzióját igényli; a core
[SPAIN.md](https://github.com/intragile/php-einvoicing/blob/main/docs/countries/SPAIN.md)
leírása (angolul) részletesen ismerteti a leképezést, a láncot és a transportokat.

> **Release label:** Verifactu live-verified on the B2Brouter sandbox (registered; hash and QR cross-checked); direct AEAT submission verified offline only — it needs a Spanish NIF and a qualified certificate
>
> Magyarul: a VERI\*FACTU élesben ellenőrzött a B2Brouter sandboxán (regisztrált rekordok; a hash
> és a QR keresztellenőrizve); a közvetlen AEAT-beküldés csak offline ellenőrzött, mert ahhoz
> spanyol adószám (NIF) és minősített tanúsítvány kell.

**Hatókör: csak VERI\*FACTU mód.** Nem része, mindegyik egy későbbi kiadásra vagy connectorra
marad:

- **a nem VERI\*FACTU mód** (XAdES-aláírású helyi rekordok, az eseménynapló és annak lánca, a
  6 óránkénti összesítő esemény, a lánc önellenőrzése és a requerimiento végpont);
- **a TicketBAI** (a baszk foral területek) és **Navarra**, amelyeknek saját rendszerük van;
- **a Facturae 3.2.x / FACe** (B2G számlák);
- **az SII**: az SII hatálya alá tartozó adózók kívül esnek a VERI\*FACTU-n;
- **a Crea y Crece B2B e-számlázási kötelezettség** (RD 238/2026, Orden HAC/1028/2026);
- **a lapozós `ConsultaFactuSistemaFacturacion` export**: csak egyetlen számla lekérdezése
  érhető el (`einvoicing:verifactu-status --lookup`).

**Határidők.** A hatályos szabály (RDL 15/2025) szerint a VERI\*FACTU 2027. január 1-jétől a
társasági adó alanyaira, 2027. július 1-jétől mindenki másra vonatkozik. 2026. október 5-én a
minisztérium bejelentette, hogy mindkettőt 2028 októberére tolja (**bejelentve, nem kihirdetve**:
2026. november 23-ig a BOE-ben semmi nem jelent meg). A csomag működése nem függ attól, melyik
dátum lesz érvényes.

**Nincs tanúsítás, nincs nyilatkozat.** Ez a csomag egy komponens, nem tanúsított számlázó
rendszer (SIF). Az IntrAgile nem lép fel a számlázó szoftver gyártójaként, és nem ír alá
*declaración responsable*-t; lásd [A saját nyilatkozatod](#a-saját-nyilatkozatod-declaración-responsable).

### Telepítés és az AEAT sémák

```bash
php artisan migrate                              # a lánc-, throttle- és rekordtáblák
php artisan einvoicing:verifactu-install-schemas # letölti az AEAT sémákat a schemas_dir mappába
```

Az AEAT XSD-ket (és az általuk importált W3C xmldsig sémát) **letöltjük, sosem csomagoljuk**:
nincs olyan licencük, amely továbbterjesztést engedne. A parancs letölti a rögzített fájlokat, és
ellenőrzőösszeggel együtt az `einvoicing.verifactu.schemas_dir` mappába teszi (alapértelmezés:
`storage/einvoicing/verifactu`). Offline telepítéshez töltsd le őket máshol, és add meg a
`--from=DIR` kapcsolót: a mappát, amely a nyolc sémafájlt név szerint tartalmazza; az
ellenőrzőösszegeket ugyanúgy ellenőrzi. A `register()`, `cancel()` és `correct()` nem fut, amíg a
sémák nincsenek telepítve.

A számlázási rekordokat meg kell őrizni (jogszabályi megőrzési kötelezettség), ezért a VERI\*FACTU
migráció visszagörgetése elutasít, amíg az `einvoicing_verifactu_records` táblában bármilyen sor
van: előbb exportáld vagy helyezd át a rekordokat, és tudatosan töröld őket.

### Beállítás

```dotenv
VERIFACTU_ENVIRONMENT=test               # test (AEAT pre-production) vagy production; production csak ha beállítod
VERIFACTU_DRIVER=aeat                    # aeat (közvetlen beküldés) vagy b2brouter
VERIFACTU_ISSUER_NIF=B12345674           # egyetlen kibocsátó; többhöz lásd az "issuers" kulcsot
VERIFACTU_ISSUER_NAME="Mi Empresa S.L."

# A rekordokat előállító számlázó szoftver (SistemaInformatico): a tiéd, sosem az IntrAgile.
VERIFACTU_PRODUCER_NAME="Your Software S.L."
VERIFACTU_PRODUCER_NIF=A39200019
VERIFACTU_SYSTEM_NAME=YourInvoicing
VERIFACTU_SYSTEM_ID=01
VERIFACTU_SYSTEM_VERSION=2.4.1
VERIFACTU_INSTALLATION_NUMBER=0001
VERIFACTU_SYSTEM_VERIFACTU_ONLY=true
VERIFACTU_SYSTEM_MULTIPLE_TAXPAYERS_POSSIBLE=false
VERIFACTU_SYSTEM_MULTIPLE_TAXPAYERS_INDICATOR=false

# Közvetlen AEAT-beküldés (aeat driver)
VERIFACTU_AEAT_CERTIFICATE=/run/secrets/aeat.p12   # PKCS#12 fájl, vagy PEM tanúsítvány ...
# VERIFACTU_AEAT_PRIVATE_KEY=/run/secrets/aeat.key.pem  # ... a privát kulcsával
VERIFACTU_AEAT_PASSWORD=...
# VERIFACTU_CA_BUNDLE=/etc/ssl/aeat-ca.pem          # tartalmaznia kell az FNMT gyökeret (lásd lent)

# B2Brouter (b2brouter driver): az einvoicing.transports egy b2brouter transportjának neve
# VERIFACTU_B2BROUTER_TRANSPORT=b2brouter

# VERIFACTU_SCHEMAS_DIR=/var/lib/verifactu
```

Az `einvoicing.verifactu` blokkban van még a `timezone` (`Europe/Madrid`, a generálási időbélyeg
zónája), a `system.foreign_id` (spanyol NIF nélküli gyártónak: vagy `nif`, vagy `foreign_id`), az
`aeat.port` (`personal` vagy `seal`, ez választja az AEAT hostot), az `aeat.timeout` (30 s) és az
`aeat.connect_timeout` (10 s), a `b2brouter.poll_attempts` (10) és a `b2brouter.poll_delay`
(30 s), a `submission` (`queue`, `connection`, `max_batch` 1–1000, `initial_wait` 60 s,
`throttle_scope`), a `cross_check` (`strict` vagy `report`) és a `stuck_after_minutes` (60;
legalább a beküldő job timeoutja percben, plusz egy, vagyis `aeat.timeout` + 60 s, így futó
beküldést sosem tekintünk elakadtnak). A `system` blokknak nincs alapértéke: a telepítés gyártója maga nyilatkozik. Az AEAT driverhez
kötelező; amíg minden kibocsátó a B2Brouteren megy, amely a saját `SistemaInformatico`-ját írja,
üresen maradhat.

**Kibocsátók.** Az `einvoicing.verifactu.issuers` minden kötelezett kibocsátó NIF-jéhez megadja a
`name` értéket, és opcionálisan a saját `driver`, `representative` (`name`, `nif`) és a
tanúsítványbeállításokat felülíró `aeat` kulcsot:

```php
// config/einvoicing.php
return [
    // ...
    'verifactu' => [
        // ...
        'issuers' => [
            'B12345674' => ['name' => 'Mi Empresa S.L.'],
            'A58818501' => [
                'name' => 'Cliente Prueba S.A.',
                'driver' => 'aeat',
                'aeat' => ['certificate' => '/run/secrets/a58818501.p12', 'password' => env('AEAT_A58818501_PASSWORD')],
            ],
        ],
    ],
];
```

A kibocsátókat futásidőben a core `VerifactuIssuerResolver` oldja fel; az alapértelmezett a
konfigurációból olvasó `ConfigIssuerResolver`. Ha az alkalmazás adatbázisban tartja a tenantokat,
saját resolvert köt be, amely NIF-enként `IssuerContext`-et ad vissza (név, driver, környezet,
hozzáférési adatok, képviselő), és a doctor számára felsorolja az összes kibocsátót:

```php
use IntrAgile\EInvoicing\Spain\CertificateKind;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\AeatCredentials;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;

final class TenantIssuerResolver implements VerifactuIssuerResolver
{
    public function resolve(Nif $issuer): IssuerContext
    {
        $tenant = Tenant::query()->where('nif', $issuer->value)->first()
            ?? throw new UnknownIssuer("No tenant for {$issuer->value}.");

        return new IssuerContext(
            $issuer,
            $tenant->legal_name,
            IssuerDriver::Aeat,
            VerifactuEnvironment::from(config('einvoicing.verifactu.environment')),
            new AeatCredentials($tenant->certificate_path, $tenant->certificatePassword(), null, CertificateKind::Personal),
        );
    }

    public function issuers(): array
    {
        return Tenant::query()->pluck('nif')->map(fn (string $nif): Nif => Nif::parse($nif))->all();
    }
}

// AppServiceProvider::register()
$this->app->singleton(VerifactuIssuerResolver::class, TenantIssuerResolver::class);
```

A context környezetének meg kell egyeznie a beállított `einvoicing.verifactu.environment`
értékkel; mást elutasít.

### Az AEAT-tanúsítvány és a TLS

A közvetlen beküldés a kibocsátó (vagy képviselője) minősített tanúsítványával, kölcsönös TLS-sel
hitelesít. Az `aeat.certificate` egy PKCS#12 fájl a jelszavával, vagy egy PEM tanúsítvány az
`aeat.private_key` kulccsal. A bridge a tanúsítványt és a kulcsot PEM fájlként (0600-as joggal)
egy 0700-as mappába írja a `storage/einvoicing/verifactu/tls` alatt, átadja a Guzzle kliensének
(`cert`, `ssl_key`, `verify` a CA-csomaggal), és törli őket, amikor a kliens megszűnik; a leállított
worker után maradt fájlokat egy nap után eltávolítja. Hozzáférési adatkészletenként egy kliens él,
és a helyben megújított tanúsítványt a következő használatkor felveszi. A TLS-ellenőrzés soha nincs
kikapcsolva.

Az AEAT szerverei az **AC RAIZ FNMT-RCM SERVIDORES SEGUROS** FNMT gyökérre láncolnak, amely sok
rendszer CA-csomagjából hiányzik. Az `aeat.ca_bundle` (`VERIFACTU_CA_BUNDLE`) egy ezt tartalmazó
csomagra mutat; `null` esetén az OpenSSL alapértelmezése érvényes. A doctor figyelmeztet, ha a
csomagban nincs FNMT gyökér, megmutatja a tanúsítvány tulajdonosát és a lejáratig hátralévő
napokat, a lejárt tanúsítványnál pedig hibát jelez.

### Rögzítés, sztornó és helyesbítés

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;

$record = EInvoicing::verifactu()->register($invoice, reference: 'order-1042');
$record->qr_url;   // most nyomtasd a számlára (AEAT driver)
$record->hash;     // a Huella; a $record->state "generated", amíg az AEAT nem válaszol

$id = new InvoiceId(Nif::parse('B12345674'), 'F-2027-0042', new \DateTimeImmutable('2027-01-15'));
EInvoicing::verifactu()->cancel($id, reference: 'void-17');
EInvoicing::verifactu()->correct($rectifiedInvoice);       // SpanishInvoice::$operation: nem Initial
EInvoicing::verifactu()->record($id);                      // a legutóbbi rekordsor, vagy null
```

- **`register(Invoice $invoice, ?string $reference = null)`** a lánc zárolása alatt, szinkron
  módon előállítja a `RegistroAlta` rekordot, eltárolja a pontos XML-t és az időbélyeg szó
  szerinti szövegét, és visszaadja a `VerifactuRecord` sort a `qr_url` értékkel, így a számla
  azonnal nyomtatható. Az EN 16931 modellben nem szereplő spanyol adatok (számlatípus,
  helyesbítés típusa, mentességi jogcím, adószám nélküli címzettek, a műveleti jelzők) a számlán
  lévő core `SpanishInvoice` kiterjesztésből jönnek. Ha a számlának már van rögzítési rekordja a
  láncában, elutasítja.
- **`cancel(InvoiceId $invoice, ?string $reference = null, AnulacionOperation $operation = Normal, bool $registeredByPreviousSystem = false)`**
  `RegistroAnulacion` rekordot fűz a lánchoz. Olyan számlához, amelyet az AEAT sosem kapott meg,
  az `AnulacionOperation::WithoutPriorRecord` kell. A lánc kezdőpontjának beállítása előtt
  használt szoftverrel rögzített számlának nincs helyi rekordja: add meg a
  `registeredByPreviousSystem: true` argumentumot, amelyet csak kezdőponttal indított lánc fogad
  el.
- **`correct(Invoice $invoice, ?string $reference = null)`** új rögzítést fűz egy olyan
  számlához, amelynek már van ilyen: helyesbítéshez (`Subsanacion`) vagy elutasítás utáni
  újraküldéshez; a műveletet a `SpanishInvoice::$operation` adja.
- **`record(InvoiceId $invoice)`** a számla legutóbbi rekordsorát adja vissza.

Az AEAT által elutasított rekord előállítottként a láncban marad; a lánc sosem íródik át. Javítsd
a számlát, és küldd be újra a `correct()` hívással.

**Tranzakciók.** Az AEAT driveren a `register()`, a `cancel()` és a `correct()` futhat a saját
adatbázis-tranzakciódon belül: a job és az esemény a commit után következik, visszagörgetéskor
semmi nem kerül sorba. A láncsor a tranzakció commitjáig zárolva marad, így ugyanannak a láncnak a
többi írója addig vár; a tranzakció legyen rövid. A B2Brouter driveren a `register()` nyitott
tranzakción belül `LogicException` kivétellel elutasít: a B2Brouter akkor is megtartja az importot,
ha a tranzakciód visszagördül, és az arról tudó sor elveszne. Hívd a commit után.

### Queue és AEAT forgalomszabályozás

A rekordokat a `SubmitVerifactuRecords` küldi a `submission.connection` / `submission.queue`
beállításon (`null` esetén az `einvoicing.queue` érvényes). Az AEAT a beküldések közti várakozást
(`TiempoEsperaEnvio`) a számlázó rendszerhez köti, ezért alapból **telepítésenként egy throttle**
van (`NumeroInstalacion` és környezet), amelyen a telepítés minden kibocsátója osztozik: egy futás
legfeljebb egy kérést küld, egy kibocsátóét, legfeljebb `max_batch` rekorddal lánc-sorrendben, és
`initial_wait` másodpercet vár, amíg az AEAT meg nem adja a saját várakozását. A várakozás csak
1000 rekordos beküldésnél marad el, ahogy az Orden HAC/1177/2024 16.2. cikke megengedi. Ha az AEAT
írásban megerősítette, hogy a kibocsátóid külön szabályozhatók, állítsd a `throttle_scope`
értékét `issuer`-re.

Az elküldött bájtok azonosak a tároltakkal. Válasz nélküli küldés után a rekordok `unconfirmed`
állapotba kerülnek; az `einvoicing.sending.backoff` késleltetései után, legfeljebb
`sending.max_attempts` alkalommal ugyanazokkal a bájtokkal újraküldjük őket, és az AEAT
duplikátum-válasza megerősíti őket. Az egészében elutasított beküldés (nem átmeneti SOAP `Client`
hiba vagy hitelesítési hiba) semmit nem rögzített: a rekordok állapota marad, a throttle sor
blokkolt lesz, és `VerifactuSubmissionBlocked` esemény fut le; a hiba elhárítása után az
`einvoicing:verifactu-submit` oldja fel a blokkot. A job timeoutja be van állítva, a doctor ezt a
`retry_after` értékkel veti össze (a `submission.connection` kapcsolatáéval is, ha az eltér).

Egy kérés számlánként és fajtánként legfeljebb egy rekordot visz: a helyesbítés, amely azelőtt
készült, hogy a számla első rekordja elment volna, a következő beküldésre vár, így minden rekord
saját választ kap. Ha a beküldő jobot a lefoglalás és az AEAT válasza között leállítják (worker
összeomlás, deploy), a rekordjai `sending` állapotban maradnak, és automatikusan nem küldjük újra
őket. A `stuck_after_minutes` után a doctor jelzi őket, az `einvoicing:verifactu-submit
--retry-unconfirmed` pedig ugyanazokkal a bájtokkal újraküldi őket.

### B2Brouter mód

A `b2brouter` driverrel (globálisan vagy kibocsátónként) a B2Brouter a számlázó rendszer: a saját
telepítésén állítja elő, láncolja és küldi be a rekordot, és a számlát is kézbesíti a partner
transportján. A `register()` szinkron módon importálja a számlát (UBL), és **`pending`** sort ad
vissza QR és hash nélkül; ezután a `PollVerifactuTaxReport` lekérdezi a tax reportot (futásonként
15 olvasás 3 s-onként, majd `poll_delay` után újra, összesen `poll_attempts` futás). Amint az AEAT
regisztrálta a rekordot, a bridge eltárolja a B2Brouter XML-jét, a `Huella`-t és a B2Brouter által
nyomtatott QR URL-t (az éles `www2.agenciatributaria.gob.es` hostot, a B2Brouter sandboxában is),
egy saját B2Brouter-láncba fűzi, és `VerifactuRecordRegistered` esemény fut le. A
`registered_with_errors` (az AEAT *AceptadoConErrores* válasza) `accepted_with_errors` állapotként
kerül a láncba. Ebben a módban a `cancel()` és a `correct()` nem érhető el; a sztornót a
B2Brouterben kezeld. Az ismeretlen kimenetelű importot újraimportálás előtt szám szerint
megkeressük (`einvoicing:verifactu-submit --retry-unconfirmed` vagy
`VerifactuManager::retryB2BrouterImport()`); ha a B2Brouter az új importot elutasítja (például
foglalt számra hivatkozva), a sor az indokkal `unconfirmed` marad, mert lehet, hogy a B2Brouternél
mégis megvan a számla.

Ha egy import a `--retry-unconfirmed` után is `unconfirmed` marad:

1. Keresd meg a számlát a B2Brouterben a száma és a kiállítás dátuma alapján (a parancs mindkettőt
   kiírja a sor azonosítójával együtt).
2. Ha a B2Brouternél megvan, vagy az ok ott javítható (például egy piszkozat foglalja a számot),
   javítsd, és futtasd újra a `php artisan einvoicing:verifactu-submit --retry-unconfirmed`
   parancsot: a sort először szám szerint keressük, így a B2Brouternél már meglévő számlát
   átvesszük, sosem importáljuk kétszer.
3. Ha a B2Brouter valóban elutasítja a számlát, és nincs meg nála, egyetlen parancs sem jelöli a
   sort hibásnak. Jelöld hibásnak közvetlenül az adatbázisban (a kapcsolatod esetleges
   táblaelőtagjával, a `42` helyére a sor azonosítóját írva):

   ```sql
   UPDATE einvoicing_verifactu_records
      SET state = 'failed', error_code = 'import_abandoned',
          error_message = 'Refused by B2Brouter; abandoned by an operator.', updated_at = CURRENT_TIMESTAMP
    WHERE id = 42 AND driver = 'b2brouter' AND state = 'unconfirmed' AND b2brouter_invoice_id IS NULL;
   ```

   Pontosan egy sornak kell változnia. Ezután futtasd még egyszer az `php artisan
   einvoicing:verifactu-submit --retry-unconfirmed` parancsot, hogy az erre az importra váró
   rekordokat (`pending`, `awaiting_predecessor`) újra lekérdezzük: ha a lánc fejéhez kapcsolódnak
   (például üres láncnál), láncba kerülnek; különben hibásak lesznek (`chain_conflict`), és
   operátori beavatkozást igényelnek. Maga a számla így nincs regisztrálva az AEAT-nál: állítsd ki
   újra, vagy kezeld a B2Brouterben.

Minden regisztrált rekordot **keresztellenőrzünk** a saját motorunkkal: az XML-t a séma szerint, a
szövegéből újraszámolt `Huella`-t, a kapcsolódását az előző tárolt rekordhoz, szemantikailag a
számla saját rekordunkkal való összevetését, és a B2Brouter QR-kódjában lévő URL-t. `strict`
módban a sikertelen ellenőrzés hibásnak jelöli a sort (a láncban marad, mert az AEAT-nál
megvan); `report` módban csak jelezzük. A `VerifactuCrossCheckFailed` mindkét esetben lefut. Két
eltérés **ismert eltérés**, sosem hiba: a mentességi jogcím a B2Brouter-fiók alapértelmezését
követi (`OperacionExenta` E1 minden mentes tételre), és a különbözeti helyesbítő számla (R1, I)
pozitív összegeket tartalmaz, ahol az AEAT az előjeles különbséget várja. A QR-kép olvasásához a
`chillerlan/php-qrcode` csomag kell `ext-gd` vagy `ext-imagick` kiterjesztéssel; nélküle az URL-t a
paraméterei alapján vetjük össze. Akkor is a paramétereket hasonlítjuk össze (megjegyzéssel), ha a
dekóder nem tud elolvasni egy képet: 2026. november 24-én 28 B2Brouter sandbox QR-képből 2 nem volt
dekódolható. Minden QR-paramétert így is ellenőrzünk; csak a képek dekódolása ellenőrizetlen
ezeknél a képeknél.

A rekordok abban a sorrendben kerülnek a láncba, ahogy maguk a rekordok megnevezik az elődjüket:
amelyiknek az elődje még függőben (`pending`) vagy megerősítetlen (`unconfirmed`, ismeretlen
kimenetelű import), megvárja, és újra lekérdezzük, amint az elődje rendeződik, akkor is, ha egy
újrapróbálás átveszi vagy újra importálja. A nem kapcsolódó rekord hibás lesz és kimarad a
láncból, amelyet a doctor ekkor blokkoltként jelez.

### Lánc folytatása és driverváltás

A láncot a kibocsátó NIF-je, a telepítés száma és a környezet azonosítja, így a teszt és az éles
rekordok sosem kerülnek egy láncba. Egy másik implementáció által kezdett lánc folytatásához
állítsd be a lánc kezdőpontját arra az utolsó rekordra, amelyet az a szoftver rögzített; ennek
`Huella`-ja megbízható bemenet, az ellenőrzés utána kezdődik:

```bash
php artisan einvoicing:verifactu-seed-chain B12345674 F-2026-0999 2026-12-31 "$HUELLA"   # annak a rekordnak a 64 karakteres Huella-ja
```

A kezdőpont a `system.installation_number` alá kerül, az egyetlen telepítés alá, amelybe ez a
rendszer ír; a `--installation` csak megerősíti ezt a számot, mást elutasít.

Ha egy kibocsátót az AEAT és a B2Brouter driver között váltasz, új lánc indul egy másik számlázó
rendszeren, ezért a `register()` és a doctor elutasít, amíg a másik drivernek nyitott lánca van
(AEAT-ra váltáskor akkor is, ha a kibocsátónak még láncba nem került B2Brouter-importjai vannak).
Az átállás sorrendje: **kiürítés → váltás → lezárás**:

1. **Kiürítés:** hagyd, hogy a régi lánc rekordjai rendeződjenek (`einvoicing:verifactu-submit`,
   `einvoicing:verifactu-status --lookup`, B2Brouter-importokhoz `--retry-unconfirmed`).
2. **Váltás:** állítsd át a kibocsátó `driver` beállítását.
3. **Lezárás:** zárd le a régi láncot az `einvoicing:verifactu-close-chain --issuer=B12345674`
   paranccsal, amely elutasít, amíg bármelyik rekordja `generated`, `sending`, `unconfirmed` vagy
   `pending` állapotú.

A B2Brouterre váltás után az AEAT-láncon maradt rekordokat a kibocsátó AEAT-hitelesítőadataival
küldjük be és kérdezzük le, amíg azok be vannak állítva: tartsd meg őket, amíg a lánc ki nem ürül.
Nélkülük annak a kibocsátónak a rekordjai visszatartva maradnak (`error_code`: `aeat_credentials`),
az ugyanazon a throttle kulcson lévő többi kibocsátó pedig tovább küld; állítsd vissza a
hitelesítőadatokat, és futtasd az `einvoicing:verifactu-submit` parancsot.

### Események

| Esemény | Mikor |
|---|---|
| `VerifactuRecordGenerated(record)` | egy rekord elkészült és sorba állt (AEAT driver) |
| `VerifactuRecordAccepted(record, withErrors, errorCode)` | az AEAT elfogadta, esetleg hibákkal (*AceptadoConErrores*) |
| `VerifactuRecordRejected(record, errorCode, errorMessage)` | az AEAT elutasította, vagy a B2Brouter tax report hibával zárult |
| `VerifactuRecordUnconfirmed(record)` | a kimenetele az automatikus újraküldések vagy lekérdezések után is ismeretlen; azonnal, ha az AEAT az első küldésre duplikátummal válaszolt (lekérdezésre vár), és ha egy B2Brouter-lekérdezés váratlanul hibára futott. Az ismeretlen kimenetelű B2Brouter-import nem vált ki eseményt: a `register()` `OutcomeUnknown` kivételt dob, a sor `unconfirmed` marad |
| `VerifactuRecordRegistered(record)` | B2Brouter mód: az AEAT regisztrálta a B2Brouter rekordját; a QR URL és a hash a soron van |
| `VerifactuCrossCheckFailed(record, result)` | a B2Brouter rekordja az ismert eltéréseken túl is eltér a miénktől |
| `VerifactuSubmissionBlocked(throttleKey, code, message)` | az AEAT egy teljes beküldést elutasított; a küldés az `einvoicing:verifactu-submit` parancsra vár |

### Parancsok

| Parancs | Mire való |
|---|---|
| `einvoicing:verifactu-install-schemas [--from=DIR]` | letölti (vagy átmásolja) és ellenőrzi az AEAT sémákat |
| `einvoicing:verifactu-submit [--issuer=] [--retry-unconfirmed]` | feloldja a blokkolt beküldést, és beütemez egy beküldési futást az esedékes rekordokra (a futás betartja az AEAT várakozását); a `--retry-unconfirmed` újra felveszi azokat a rekordokat, amelyeket már nem küldünk vagy kérdezünk le automatikusan, a leállított worker után `sending` állapotban maradt sorokat is |
| `einvoicing:verifactu-status NUMBER [--date=] [--issuer=] [--lookup]` | a tárolt állapot, CSV és hibák; a `--lookup` az AEAT-tól (vagy a B2Broutertől) kérdezi le a legutóbbi rekordot, sosem küld be, és az `unconfirmed` rekordot elfogadottnak jelöli, ha az AEAT ugyanazt a `Huella`-t tartja nyilván (CSV nélkül, mert azt az AEAT később nem adja vissza) |
| `einvoicing:verifactu-verify-chain [--issuer=]` | újraszámol minden tárolt hasht és kapcsolatot, és minden láncfejet összevet az utolsó rekordjával; csak integritási hibánál bukik |
| `einvoicing:verifactu-seed-chain ISSUER NUMBER DATE HASH [--installation=]` | lánc folytatása egy másik implementációtól (a `--installation` csak a `system.installation_number` értékét erősíti meg) |
| `einvoicing:verifactu-close-chain [--issuer=] [--driver=]` | lezárja annak a drivernek a láncait, amelyet a kibocsátó már nem használ |

### Doctor

Ha van beállított kibocsátó, az `einvoicing:doctor` `verifactu` sorokat ad hozzá: a konfiguráció,
a telepített sémák és ellenőrzőösszegeik, a deklarált rendszer, a resolver által felsorolt összes
kibocsátó, minden AEAT-tanúsítvány (tulajdonos, napok a lejáratig; a lejárt hiba), a CA-csomag FNMT
gyökere (figyelmeztetés), a B2Brouter-kulcs a környezethez mérve (`test_` az élesen kívül), a
táblák, a driverváltások, a láncfejek az utolsó rekordjukhoz mérve, a kezdőponttal indított láncok, a
`stuck_after_minutes` után is elakadt rekordok, a blokkolt vagy visszatartott B2Brouter-láncok, a
sikertelen keresztellenőrzések és a blokkolt beküldések. A `--live` egy TLS-kézfogást ad hozzá a
beállított AEAT végponttal, beküldést soha; ezt te futtasd, a saját tanúsítványoddal.

### A saját nyilatkozatod (declaración responsable)

A számlázó rendszer gyártója *declaración responsable*-t ír alá arról, hogy a rendszer megfelel
az RRSIF-nek (RD 1007/2023, Orden HAC/1177/2024). **Az IntrAgile nem lép fel gyártóként, nem ír alá
nyilatkozatot, és nem ad hozzá sablont.** Ez a csomag a rekordok előállítását, a `Huella`-t és a
láncot, a rekord XML-t, a QR URL-t és az AEAT SOAP klienst valósítja meg. Az alkalmazásodnak
továbbra is garantálnia kell:

- minden számla és rekordja elválaszthatatlan összekapcsolását (nincs számla rekord nélkül);
- hogy a rekordokat soha nem törlik és nem módosítják;
- hogy a rekordokat azonnal elküldik;
- a helyesbítési és sztornófolyamatokat;
- a saját `SistemaInformatico` azonosságát (a `system` blokk);
- magát a nyilatkozatot, amely ezt a könyvtárat és verzióját komponensként megnevezi.

### Korlátok

- **A közvetlen AEAT-beküldés csak offline ellenőrzött**, az AEAT sémái, a közzétett példák és
  egy helyi TLS-végpont ellen; élő AEAT-futáshoz spanyol NIF és minősített tanúsítvány kell.
- **B2Brouter mód:** sztornó és helyesbítés nem érhető el a bridge-en át; a mentességi jogcím és
  a helyesbítő számla előjele a B2Brouter-fiók beállításait követi.
- **Lekérdezés:** egyszerre egy számla; lapozós export nincs.
- **A nem VERI\*FACTU mód** és a fent felsorolt többi spanyol rendszer nem része.

## Queue

A kapcsolatot és a queue nevét az `einvoicing.queue.connection` és az `einvoicing.queue.name`
adja meg (`null` esetén az alkalmazás alapértelmezése). **Élesben valódi, aszinkron queue-t
használj.**

- **A `sync`, `deferred` és `background` driver a hívó folyamatban futtatja a jobokat, és
  figyelmen kívül hagyja a késleltetést.** Ezeken az újrapróbálható küldési hiba azonnal
  `failed` lesz (az üzenet jelzi, hogy az újrapróbáláshoz aszinkron queue kell), a
  státuszlekérdezés és a beragadt `sending` foglalás lejárata nincs ütemezve, `sync` esetén pedig
  a webhookban jelzett dokumentum letöltése és nyugtázása magában a webhook-kérésben fut, így ott
  egy hiba `500`-as választ ad (`APP_DEBUG` mellett hibaoldallal). A doctor ezekre a driverekre
  figyelmeztet.
- **A `retry_after` legyen hosszabb a jobok időkorlátjánál.** Az alapértelmezett `http`
  beállításokkal a küldő job időkorlátja 100, a bejövő dokumentumé 170 másodperc; ha az
  `einvoicing.ksef` használatban van, a KSeF küldő jobé 240, a KSeF státuszjobé 310. Ha egy job
  tovább fut a kapcsolat `retry_after` értékénél, egy másik worker újra lefoglalja, és mivel ezek
  a jobok sosem ismétlődnek, sikertelennek jelöli, miközben az első futás még sikerülhet. A
  doctor ezt is ellenőrzi.
- **A workerek fussanak `pcntl`-lel** (a linuxos CLI buildekben általában elérhető; a PHP-t
  `--enable-pcntl` kapcsolóval kell fordítani), hogy az időkorlátnál le tudják állítani a jobot.
  Az `einvoicing:resolve` erre építve tekinti elhagyottnak a sokáig `sending` állapotú sort. A
  doctor a `pcntl`-t abban a PHP-ban ellenőrzi, amely a doctort futtatja, és ez nem feltétlenül
  azonos a workerek PHP-jával; ha ott hiányzik, figyelmeztet, egyébként emlékeztet, hogy a
  workereknél is nézd meg.
- **Az Amazon SQS legfeljebb 900 másodperces késleltetést fogad el.** `sqs` kapcsolaton minden
  késleltetés legfeljebb 900 másodperc, így a hosszabb backoff, státuszlekérdezési késleltetés
  vagy `Retry-After` egyszerűen rövidebb várakozást jelent: az alapértelmezett `sending.backoff`
  (legfeljebb 1800) és `status.delays` (legfeljebb 21600) esetén a csomag a beállítottnál
  korábban kérdez le és próbálkozik újra. A foglalás lejárata (a küldő job időkorlátja plusz 30
  másodperc, az alapértelmezett `http` beállításokkal 130, KSeF-küldésnél 270) maradjon 900 alatt, különben a lassú
  küldés még futás közben `unconfirmed` lesz. Az Amazon SQS FIFO queue-n nem enged
  üzenetenkénti késleltetést (a `DelaySeconds` csak a queue szintjén állítható be), így az
  újrapróbálások és a státuszlekérdezések nem lennének időben széthúzva. **Ne használj FIFO
  queue-t.** (Lásd a `DelaySeconds` leírását a
  [SendMessage API-referenciában](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/APIReference/API_SendMessage.html).) A doctor
  figyelmeztet a 900 másodpercnél hosszabb késleltetésekre, FIFO queue-nál pedig hibát jelez; a
  FIFO queue-t a `.fifo` végű queue-névről, a kapcsolat `prefix` + név + `suffix` alakjáról vagy a
  teljes queue-URL-ről ismeri fel.

## Saját driver

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;

EInvoicing::extend('my-ap', fn ($app, #[\SensitiveParameter] array $config) => new MyAccessPointTransport($config['token']));
```

```php
// config/einvoicing.php
return [
    // ...
    'transports' => [
        'other' => ['driver' => 'my-ap', 'token' => env('MY_AP_TOKEN')],
    ],
];
```

A factory egy core `Transport`-ot ad vissza (és `InboundSource`-ot is, ha dokumentumot fogad).
Bekapcsolt validációnál a dokumentumait a beépített driveréhez hasonlóan validálja a csomag: a
küldő job a lefoglalás előtt, és az a `ValidatingTransport`, amelybe az `EInvoicing::transport()`
csomagolja. A csomaggal szemben vállalt szerződése:

- **Miután a kérés elment, csak a core `TransportException` alosztályait dobja**, aszerint, amit
  tud: `OutcomeUnknown`-t, ha az access point átvehette a dokumentumot, `TransportUnavailable`-t
  vagy `RateLimited`-et csak akkor, ha az újrapróbálás nem okozhat duplikációt. Az
  `InvalidArgumentException` azt jelenti a csomagnak, hogy „semmi sem ment el”, és a
  dokumentumot `failed` állapotba teszi; ha a kérés után dobja, egy operátori újraküldés
  duplikációt okozhat.
- **A kivételüzenetek ne idézzenek hozzáférési adatot**, semmilyen formában (kódolva vagy
  URL-be ágyazva sem). Ezek a `failure_message` és `last_error` oszlopba kerülnek, a doctor pedig
  csak a beépített kulcsneveket takarja ki. A factory `$config` paraméterét jelöld
  `#[\SensitiveParameter]` attribútummal, hogy a hozzáférési adatok ne kerüljenek a stack trace-be.
- **A `status()` pontosan arra a dokumentumazonosítóra válaszoljon, amelyet kapott**: a visszaadott
  státusz `documentId` mezője egyezzen meg vele (szigorú stringegyezés). Ha a driver normalizálja
  az azonosítót (kis- és nagybetű, előtag, szóköz), a válaszát a csomag figyelmen kívül hagyja,
  és a dokumentum sosem zárul le.

A felépített transportot a csomag név szerint gyorsítótárazza. Az `einvoicing.validation.enabled`
futás közbeni átállítása nem csomagolja át a már felépített transportot; a konfigurációban
állítsd, ne kódból.

Ha saját driverrel webhookot is fogadnál, regisztráld a parserét, egy core `WebhookParser`-t:

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;

EInvoicing::extendWebhook('my-ap', fn ($app, #[\SensitiveParameter] array $config) => new MyAccessPointWebhook($config['webhook_secret']));
```

A factory megkapja az alkalmazást és a transport konfigurációját, amelyben a `webhook_secret`
mindig meg van adva (az útvonal a secret nélküli transportot elutasítja). Ettől kezdve az útvonal
az adott driver minden transportjához fogad értesítést. Saját drivernek nincs beállított fiókja,
ezért a csomag egyetlen értesítést sem hagy figyelmen kívül idegenként; ha kell, a fiókot a
parseredben ellenőrizd. A beépített driverhez regisztrált parser a beépítettet váltja fel.

## Tesztelés

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\Exception\OutcomeUnknown;
use IntrAgile\EInvoicing\Transport\InboundDocument;
use IntrAgile\EInvoicing\Transport\InboundReference;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\SendResult;

$fake = EInvoicing::fake();

$fake->willReturn(
    new SendResult('doc-1', DeliveryState::Pending),   // a következő küldés
    new OutcomeUnknown('no answer'),                   // az azutáni
);

// ... a tesztelt kód futtatása ...

$fake->assertSent(fn (OutboundDocument $d): bool => $d->receiver->toString() === '0208:0123456789');
$fake->sent();                                         // list<OutboundDocument>

$fake->receive(new InboundDocument(
    new InboundReference('in-1', null, null, null, new \DateTimeImmutable),
    $ublXml,
));
$this->artisan('einvoicing:poll-inbound');
$fake->acknowledged();                                 // ['in-1']
```

A `fake()` minden transportot egyetlen memóriabeli `FakeTransport`-ra cserél, és kihagyja a
validációt. Előre megadott válasz nélkül minden küldés `delivered` eredményt ad `fake-1`,
`fake-2` stb. azonosítóval. Ha a tesztekben `sync` a queue-kapcsolat, a teljes folyamat azonnal
lefut; az eredményt a visszakapott üzenet `refresh()`-ével látod. A webhook-aláírást a csomag
fake módban is a beállított kulccsal ellenőrzi.

## Artisan parancsok

| Parancs | Feladat |
|---|---|
| `einvoicing:doctor [--live]` | ellenőrzi a transportokat, a webhook-URL-eket, a validációs szabályokat, a telepített szabálycsomagokat, a táblákat, a kísérleti keretet elhasznált bejövő dokumentumokat, a számértékű job-beállításokat, a queue drivert, a `pcntl`-t, a `retry_after`-t és az SQS-késleltetéseket; `--live` mellett a KSeF-inbox kivételével minden inboxot egyszer meghív a hozzáférési adatok ellenőrzéséhez |
| `einvoicing:install-rules [--from=DIR]` | telepíti a Peppol BIS Billing validációs szabályokat |
| `einvoicing:ksef-certificate [--type=offline\|authentication] [--algorithm=ec\|rsa] [--name=] [--certificate=] [--private-key=]` | KSeF Offline vagy Authentication tanúsítványt igényel, és a kulcsával együtt kiírja (0600); sosem ír felül, a kulcsot sosem írja ki |
| `einvoicing:ksef-deadlines` | felsorolja a még nem kézbesített offline KSeF-számlákat a határidejükkel, és kiváltja a `KsefOfflineDeadlineApproaching` eseményt; óránként ütemezd |
| `einvoicing:poll-inbound [--transport=] [--include-capped]` | rögzíti a várakozó bejövő dokumentumokat és sorba állítja a feldolgozásukat; ütemezd. A `--include-capped` minden, az `inbound.max_fetch_attempts` keretet elhasznált dokumentumnak még egy kísérletet ad. A `--transport=ksef` a KSeF postafiókot olvassa, legfeljebb `einvoicing.ksef.inbound_min_interval_minutes` percenként egyszer |
| `einvoicing:resolve ID (--sent=DOKUMENTUM-ID \| --resend) [--force]` | rendez egy `unconfirmed` küldést, vagy újra sorba állít egy dokumentumot |
| `einvoicing:verifactu-*` | az AEAT sémák telepítése, beküldés, állapot és lekérdezés, a VERI\*FACTU láncok ellenőrzése, folytatása és lezárása; lásd: [Parancsok](#parancsok) |

## Mi van ellenőrizve

| Terület | Hogyan | Állapot |
|---|---|---|
| Állapotgép, küldési szabályok, újrapróbálás, foglalás lejárata, státuszlekérdezés, bejövő feldolgozás, webhookok, parancsok | Offline feature tesztek `FakeTransport`-tal és stub transportokkal (a CI PHP 8.3, 8.4 és 8.5 × Laravel 12 és 13 kombinációkat futtat) | Minden tesztfuttatáskor |
| Peppol-validáció a manageren keresztül | Opcionális `peppol-rules` csoport: helyi másolatból telepíti a hivatalos szabályokat, és validáló transporton átküldi a Peppol alappéldát | Kiadás előtt |
| Recommand driver | A core offline tesztjei a Recommand publikált API-leírásából írt fixture-ökkel; a core `recommand-live` éles csoportja; a híd `RecommandSmokeTest` éles futtatása, amely számlát küld az `EInvoicing::send()`-del | **Élesben tesztelve a Peppol teszthálózaton** (2026-11-15: core- és hídfuttatás) |
| B2Brouter és e-invoice.be driver | A core offline szerződéstesztjei a szolgáltatók nyilvános API-leírásából írt fixture-ökkel, a core `b2brouter-live` és `einvoicebe-live` éles csoportjai; a manager, a konfiguráció, a doctor és a webhook-útválasztás tesztjei itt | **Élesben tesztelve a szolgáltatói sandbox ellen (szimulált hálózat)** (2026-11-15, core) |
| Francia folyamat (`EInvoicing::france()`, `EInvoicing::annuaire()`, `Annuaire` binding) | A core offline szerződéstesztjei és `b2brouter-france-live` éles csoportja; a híd `B2BrouterFranceSmokeTest` éles futtatása: az `Annuaire` binding, egy küldés `Deposited` állapotig és egy teljes fizetés, amely `Collected`-ként olvasható vissza | **Élesben tesztelve a B2Brouter sandbox ellen** (2026-11-18: core- és hídfuttatás, francia sandboxfiók); az elfogadott Flux 10 beküldést még nem teszteltük élesben |
| Lengyel KSeF (`EInvoicing::ksef()`, `SendKsefInvoice`, `PollKsefStatus`, KSeF postafiók, határidők, tanúsítványparancs, doctor sor) | Offline feature tesztek előre rögzített KSeF-válaszokkal; a híd `KsefLiveTest` éles futtatása (`ksef-live` csoport) a KSeF TE-n: egy `sync` kapcsolaton sorba állított küldés, amely KSeF-számmal és nyugtával `delivered` lesz, és a vevő `einvoicing:poll-inbound --transport=ksef` futása, amely legfeljebb 15 metaadat-lekérdezéssel `DocumentReceived` eseményt vált ki; a core `ksef-live` éles csoportja lefedi az offline küldést, a KSeF-tanúsítványokat és a QR-linkeket | **Élesben tesztelve a KSeF TE-n** (2026-11-22, hídfuttatás PHP 8.5-ön és 8.3-on); az offline határidők, a tanúsítványparancs és a doctor sor itt offline tesztelt |
| Spanyol VERI\*FACTU (`EInvoicing::verifactu()`, láncstore, `SubmitVerifactuRecords`, `PollVerifactuTaxReport`, parancsok, doctor sorok) | Offline feature tesztek az AEAT sémái, előre rögzített AEAT SOAP-válaszok, a core éles felvételeiből másolt B2Brouter-válaszok és egy helyi TLS-végpont ellen; láncpárhuzamosság forkolt írókkal SQLite-on (MySQL és PostgreSQL a `databases` CI jobban); a híd `B2BrouterVerifactuLiveTest` éles futtatása (`b2brouter-spain-live` csoport) a B2Brouter spanyol sandboxán, `sync` queue-val: két láncolt F1 számla, egy mentes számla és egy R1/I helyesbítő számla, mindegyik `registered`, egy B2Brouter-láncba fűzve és keresztellenőrizve (a mentességi jogcím és a helyesbítés előjele ismert eltérés), majd `einvoicing:verifactu-verify-chain` | **A VERI\*FACTU élesben ellenőrzött a B2Brouter sandboxán** (2026-11-24, hídfuttatás PHP 8.5-ön); a közvetlen AEAT-beküldés csak offline ellenőrzött |
| Webhook-aláírások | Offline tesztek a szolgáltatók dokumentált sémája szerint (az e-invoice.be-nél a közzétett tesztvektorral is) | Offline ellenőrizve; **a B2Brouter webhookjait élesben teszteltük** (2026-11-18: valódi, aláírt sandbox-kézbesítések a hídon visszajátszva); a Recommand és az e-invoice.be webhookjait nem |

A core `brick/math` ^0.14.8-at kér, ezt a Laravel 13 is elfogadja.

A futtatások eredménye dátummal és commitokkal a core
[VERIFICATION.md](https://github.com/intragile/php-einvoicing/blob/main/docs/VERIFICATION.md) fájljában van (angolul). A webhook-aláírásokat a szolgáltatók dokumentált sémája szerint offline ellenőrizzük (az e-invoice.be-ét a közzétett tesztvektorral is); élesben csak a B2Brouter webhookjait teszteltük (2026-11-18, sandbox-kézbesítések a Laravel hídon visszajátszva).

A csomag alapértelmezett tesztfuttatása nem használ hálózatot; az éles smoke-futtatás ki van
zárva belőle (`recommand-live`, `b2brouter-france-live`, `ksef-live` és `b2brouter-spain-live`
csoport, `Live` testsuite).
A KSeF futtatáshoz nem kell fiók: csak a KSeF TE-t éri el (`https://api-test.ksef.mf.gov.pl/v2`,
minden más KSeF hostot elutasít), véletlen NIP-eket használ önaláírt bélyegzőkkel, amelyeket
ideiglenes könyvtárba ír (0600), minden kérésnek van időkorlátja, és 15:40 és 18:00 (Europe/Warsaw)
között, a TE napi karbantartása körül nem indul. A Recommand
futtatás csak akkor indul, ha a kulcs a Peppol teszthálózaton lévő Recommand playgroundhoz
tartozik; a francia futtatás (egy francia B2Brouter-fiók `B2BROUTER_API_KEY` és
`B2BROUTER_ACCOUNT_ID` értékével) csak sandbox hoston `test_`, staging hoston `stag_` kulccsal
indul. A spanyol futtatás (egy spanyol B2Brouter sandboxfiók `B2BROUTER_ES_API_KEY` és
`B2BROUTER_ES_ACCOUNT_ID` értékével) az első kérés előtt elutasít minden kulcsot, ami nem `test_`
kulcs a `https://api.b2brouter.net` hoston, az AEAT-ot sosem hívja, és futásonként négy
sandboxszámlát hoz létre. Ugyanebben a csoportban a `B2BrouterWebhookReplayTest` a B2Brouter-től elmentett
webhook-kézbesítéseket (`B2BROUTER_WEBHOOK_CAPTURE_DIR`, `B2BROUTER_WEBHOOK_SECRET`) játssza vissza
a webhook-útvonalon, hálózati hívás nélkül. A core-ban opcionális éles csoportok
vannak:
`recommand-live` Recommand playground csapathoz, `b2brouter-live` B2Brouter sandboxhoz és
`einvoicebe-live` e-invoice.be sandbox céghez; lásd a core
[TRANSPORT.md](https://github.com/intragile/php-einvoicing/blob/main/docs/TRANSPORT.md) leírását.

### Ismert korlátok (1.x-re halasztva)

- Még nincs operátori parancs olyan `pending` sorra, amelynek az állapotlekérései elfogytak; az
  ilyen sort a hozzáférési pontnál ellenőrizd (az `einvoicing:resolve` az `unconfirmed` és az
  elhagyott `sending` sorokat kezeli).
- e-invoice.be: a postafiókot nálunk vágjuk a visszatekintési időnél; a szolgáltató `date_from`
  szűrőjét még nem használjuk.
- Nincs védelem arra, hogy egy állapotmentes webhook második állapotlekérési láncot indítson; az
  extra lánc a dokumentum `status_checks` keretén osztozik, így az összes lekérés a
  `status.max_attempts` keretén belül marad.

Egyik sem helyességi hiba.

## Verziókezelés

A nyolc csomag mindig együtt, azonos verziószámmal jelenik meg, a szemantikus verziózás (Semantic
Versioning) szerint: az öt motorcsomag (`intragile/php-einvoicing`, az
`intragile/einvoicing-rules-en16931`, `intragile/einvoicing-rules-xrechnung` és
`intragile/einvoicing-rules-facturx` szabálycsomag, valamint az `intragile/laravel-einvoicing`) és a
három belépő csomag (`intragile/laravel-xrechnung`, `intragile/laravel-zugferd` és
`intragile/laravel-peppol`). Mindegyikből ugyanazt a minor verziót használd. A szabálycsomagok
Composer `conflict` bejegyzéssel kizárják a saját minor verziójuknál régebbi (és a következő major)
magot, így új szabályok sosem kerülnek olyan motor mellé, amely nem tudja futtatni őket.

## Fejlesztés

```bash
composer test        # PHPUnit
composer analyse     # PHPStan, max szint
composer format      # Pint
EINVOICING_PEPPOL_FROM=/path/to/peppol/sch vendor/bin/phpunit --group peppol-rules
vendor/bin/einvoicing-rules install spain --dir=build/verifactu
vendor/bin/phpunit --group verifactu-official --fail-on-skipped
EINVOICING_TEST_DB_URL=mysql://root:secret@127.0.0.1:3306/einvoicing_test vendor/bin/phpunit --group verifactu-concurrency --group verifactu-databases --fail-on-skipped
```

**Minden tesztfuttatás, amelyben az `EINVOICING_TEST_DB_URL` be van állítva, kiüríti azt az
adatbázist:** az ezt olvasó tesztek (a lánc párhuzamossági tesztje és a doctor adatbázis-lekérdezései,
`verifactu-concurrency` és `verifactu-databases` csoport) a MySQL vagy PostgreSQL adatbázis minden
tábláját eldobják és újra létrehozzák. Eldobható adatbázist adj meg, soha olyat, amelyben adat van;
a változó nélkül SQLite-on futnak.

## Licenc

MIT. Lásd: [LICENSE](LICENSE).

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)
