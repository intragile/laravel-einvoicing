# Laravel E-Invoicing

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-einvoicing/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-einvoicing.svg)](https://packagist.org/packages/intragile/laravel-einvoicing)
[![License](https://img.shields.io/packagist/l/intragile/laravel-einvoicing.svg)](LICENSE)

Laravel-integráció az [`intragile/php-einvoicing`](https://github.com/intragile/php-einvoicing)
csomaghoz: EN 16931 szerinti számlák küldése és fogadása **Peppol** hálózaton, Laravel
alkalmazásból.

[English documentation → README.md](README.md)

> **Állapot:** 1.0.0-s verzió, kiadva 2026-11-16-án. Mit és hogyan ellenőriztünk:
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
saját számlamodelljéből állítja elő, így a kézbesített bájtok eltérhetnek a te XML-edtől. A webhook-aláírásokat a szolgáltatók dokumentált sémája szerint offline ellenőrizzük (az e-invoice.be-ét a közzétett tesztvektorral is); a webhookokat nem teszteltük élesben. Hogy
melyik driver mit küld, hogyan képezi le az állapotokat, hogyan fogad és mit tud a webhookja, azt
a core [TRANSPORT.md](https://github.com/intragile/php-einvoicing/blob/main/docs/TRANSPORT.md)
leírása tartalmazza (angolul).

## Telepítés

```bash
composer require intragile/laravel-einvoicing
php artisan vendor:publish --tag=einvoicing-config     # opcionális: config/einvoicing.php
php artisan einvoicing:install-rules                   # a Peppol BIS validációs szabályok
php artisan migrate                                    # két tábla: einvoicing_outbound_documents, einvoicing_inbound_documents
php artisan einvoicing:doctor                          # mindent ellenőriz, ami alább szerepel
```

> **Az első kiadásig** sem ez a csomag, sem az `intragile/php-einvoicing` nincs fent a
> Packagisten. Addig klónozd mindkét repót az alkalmazás mellé, és vedd fel őket path
> repositoryként az alkalmazás `composer.json`-jába:
>
> ```json
> "repositories": [
>     { "type": "path", "url": "../php-einvoicing", "options": { "versions": { "intragile/php-einvoicing": "1.1.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-en16931", "options": { "versions": { "intragile/einvoicing-rules-en16931": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-xrechnung", "options": { "versions": { "intragile/einvoicing-rules-xrechnung": "1.0.0" } } },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-facturx", "options": { "versions": { "intragile/einvoicing-rules-facturx": "1.0.0" } } },
>     { "type": "path", "url": "../laravel-einvoicing", "options": { "versions": { "intragile/laravel-einvoicing": "1.0.0" } } }
> ]
> ```
>
> majd kérd be így: `"intragile/laravel-einvoicing": "^1.0"`. A `versions` beállítás minden path
> csomagnak azt a verziót adja, amelyet a csomagok egymás közötti feltételei várnak.

A migrációt a csomag maga tölti be. Ha saját példányt szeretnél belőle az alkalmazásban:
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

> **Élőben (a B2Brouter sandboxban) tesztelve: a címtár-lekérdezés, a küldés és az életciklus a
> `Deposited` állapotig; a válaszok, a fizetések és az elfogadott beküldés csak offline tesztelt.**

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

**Doctor.** Beállított `einvoicing.france.transport` mellett az `einvoicing:doctor` egy `france`
sort is kiír: a transportnak érvényes B2Brouter transportnak kell lennie, és a sor megmondja, hogy
a kulcsa sandbox (`test_`), staging (`stag_`) vagy éles kulcs. Kérést nem küld.

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
  beállításokkal a küldő job időkorlátja 100, a bejövő dokumentumé 170 másodperc. Ha egy job
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
  másodperc, az alapértelmezett `http` beállításokkal 130) maradjon 900 alatt, különben a lassú
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
| `einvoicing:doctor [--live]` | ellenőrzi a transportokat, a webhook-URL-eket, a validációs szabályokat, a telepített szabálycsomagokat, a táblákat, a kísérleti keretet elhasznált bejövő dokumentumokat, a számértékű job-beállításokat, a queue drivert, a `pcntl`-t, a `retry_after`-t és az SQS-késleltetéseket; `--live` mellett minden inboxot egyszer meghív a hozzáférési adatok ellenőrzéséhez |
| `einvoicing:install-rules [--from=DIR]` | telepíti a Peppol BIS Billing validációs szabályokat |
| `einvoicing:poll-inbound [--transport=] [--include-capped]` | rögzíti a várakozó bejövő dokumentumokat és sorba állítja a feldolgozásukat; ütemezd. A `--include-capped` minden, az `inbound.max_fetch_attempts` keretet elhasznált dokumentumnak még egy kísérletet ad |
| `einvoicing:resolve ID (--sent=DOKUMENTUM-ID \| --resend) [--force]` | rendez egy `unconfirmed` küldést, vagy újra sorba állít egy dokumentumot |

## Mi van ellenőrizve

| Terület | Hogyan | Állapot |
|---|---|---|
| Állapotgép, küldési szabályok, újrapróbálás, foglalás lejárata, státuszlekérdezés, bejövő feldolgozás, webhookok, parancsok | Offline feature tesztek `FakeTransport`-tal és stub transportokkal: 338 teszt, 1824 ellenőrzés PHP 8.5-ön az itt telepített függőségekkel (a CI PHP 8.3, 8.4 és 8.5 × Laravel 12 és 13 kombinációkat futtat) | Minden tesztfuttatáskor |
| Peppol-validáció a manageren keresztül | Opcionális `peppol-rules` csoport: helyi másolatból telepíti a hivatalos szabályokat, és validáló transporton átküldi a Peppol alappéldát | Kiadás előtt |
| Recommand driver | A core offline tesztjei a Recommand publikált API-leírásából írt fixture-ökkel; a core `recommand-live` éles csoportja; a híd `RecommandSmokeTest` éles futtatása, amely számlát küld az `EInvoicing::send()`-del | **Élesben tesztelve a Peppol teszthálózaton** (2026-11-15: core- és hídfuttatás) |
| B2Brouter és e-invoice.be driver | A core offline szerződéstesztjei a szolgáltatók nyilvános API-leírásából írt fixture-ökkel, a core `b2brouter-live` és `einvoicebe-live` éles csoportjai; a manager, a konfiguráció, a doctor és a webhook-útválasztás tesztjei itt | **Élesben tesztelve a szolgáltatói sandbox ellen (szimulált hálózat)** (2026-11-15, core) |
| Webhook-aláírások | Offline tesztek a szolgáltatók dokumentált sémája szerint (az e-invoice.be-nél a közzétett tesztvektorral is) | Offline ellenőrizve; **a webhookokat nem teszteltük élesben** (nyilvános HTTPS-végpont kellene) |

A core `brick/math` ^0.14.8-at kér, ezt a Laravel 13 is elfogadja.

A futtatások eredménye dátummal és commitokkal a core
[VERIFICATION.md](https://github.com/intragile/php-einvoicing/blob/main/docs/VERIFICATION.md) fájljában van (angolul). A webhook-aláírásokat a szolgáltatók dokumentált sémája szerint offline ellenőrizzük (az e-invoice.be-ét a közzétett tesztvektorral is); a webhookokat nem teszteltük élesben.

A csomag alapértelmezett tesztfuttatása nem használ hálózatot; az éles smoke-futtatás ki van
zárva belőle (`recommand-live` csoport, `Live` testsuite), és csak akkor indul, ha a kulcs a
Peppol teszthálózaton lévő Recommand playgroundhoz tartozik. A core-ban opcionális éles csoportok
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
```

## Licenc

MIT. Lásd: [LICENSE](LICENSE).

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)
