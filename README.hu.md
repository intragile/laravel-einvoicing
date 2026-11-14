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

- **Konfigurált transportok.** Névvel ellátott kapcsolatok a `config/einvoicing.php`-ban; a
  beépített `recommand` driver a [Recommand](https://recommand.eu) access pointhoz csatlakozik,
  saját drivert az `EInvoicing::extend()` regisztrál.
- **Peppol BIS validáció minden küldés előtt**, alapból bekapcsolva; a hivatalos szabályokat
  egyetlen artisan parancs telepíti.
- **Sorba állított küldés, védett állapotgéppel.** A küldést egy feltételes UPDATE foglalja le,
  így egy duplikált vagy megismételt job sosem küld kétszer. Ha egy küldés kimenetele nem
  ismert, a csomag **soha nem küldi újra magától**: egy operátor dönt róla.
- **Kézbesítési státusz** lekérdezéssel és webhookkal, minden lépésnél eseménnyel.
- **Bejövő dokumentumok** webhookból vagy lekérdezésből: letöltés, tárolás, egyszeri esemény,
  és csak ezután nyugtázás az access pointnál.
- **Aláírt webhookok**, a nyers kéréstörzsön ellenőrizve, a `web` middleware-csoport nélkül.
- `EInvoicing::fake()` a saját tesztekhez, `einvoicing:doctor` a telepítés ellenőrzéséhez.

> **Az éles küldés a Recommandon keresztül még nincs kipróbálva.** Minden teszt offline fut;
> lásd: [Mi van ellenőrizve](#mi-van-ellenőrizve).

## Követelmények

- PHP 8.3+ a `dom`, `libxml` és `mbstring` kiterjesztéssel
- Laravel 12 vagy 13
- Éles használathoz queue worker (lásd: [Queue](#queue))

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
>     { "type": "path", "url": "../php-einvoicing" },
>     { "type": "path", "url": "../php-einvoicing/packages/rules-en16931" },
>     { "type": "path", "url": "../laravel-einvoicing" }
> ]
> ```
>
> majd kérd be így: `"intragile/laravel-einvoicing": "@dev"`.

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

A `config/einvoicing.php` további részei: HTTP-időkorlátok és a válaszméret felső határa
(`http`), a validáció ki- és bekapcsolása (`validation.enabled`), a küldés újrapróbálása
(`sending.max_attempts`, `sending.backoff`), a státusz lekérdezése (`status.poll`,
`status.delays`, `status.max_attempts`), a bejövő dokumentumok nyugtázása és kísérleti kerete
(`inbound.acknowledge`, `inbound.max_fetch_attempts`) és a webhook-útvonal (`webhooks`). A
késleltetési listák utolsó eleme ismétlődik. A számok számot tartalmazó stringként is
megadhatók, ahogy a környezeti változók adják őket; minden mást a csomag a kulcsot megnevező
hibával elutasít. A `webhooks.enabled` a `"true"`/`"false"` és az `"1"`/`"0"` stringet is
elfogadja. A Recommand API-kulcs azonosítója és titka egyaránt legalább 8 karakter legyen.

A transport első használatkor épül fel, így a hiányzó hozzáférési adatok sosem akasztják meg az
alkalmazás indulását. A titkos kulcsok nem kerülnek kivételüzenetbe, a doctor kimenetébe,
eseménybe vagy sorba állított job adatai közé; a jobok csak a sor azonosítóját viszik magukkal.

## Küldés

```php
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;

$message = EInvoicing::send($invoice, reference: 'INV-2026-0001');
```

A `send()` elfogad egy core `Invoice` modellt, UBL XML-t stringként vagy egy
`OutboundDocument`-et. A címzést (küldő, címzett, folyamat) magából a számlából olvassa ki. A
dokumentumot `OutboundMessage`-ként, `queued` állapotban tárolja, a `SendDocument` jobot pedig
a nyitott adatbázis-tranzakció (ha van) commitja után indítja. A harmadik, opcionális
paraméterrel az alapértelmezettől eltérő transport adható meg.

**Állítsd be a Peppol BIS CustomizationID-t.** A core UBL-írója alapból
`urn:cen.eu:en16931:2017` értéket ír a `cbc:CustomizationID`-be: ez sima EN 16931, nem Peppol
BIS Billing 3.0. Küldés előtt add meg a számlán a BIS-azonosítót:

```php
use IntrAgile\EInvoicing\Model\ProcessControl;
use IntrAgile\EInvoicing\Transport\Peppol\PeppolBis3;

new Invoice(
    // ...
    processControl: new ProcessControl(
        'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0',
        PeppolBis3::PROCESS,
    ),
);
```

Bekapcsolt validációval (ez az alapértelmezés) a Peppol-szabályok elutasítják az enélküli
dokumentumot, és a küldés `failed` állapotban, `validation` kategóriával zárul, mielőtt bármi
elhagyná a szervert. Kikapcsolt validációnál semmi sem állítja meg; erre a doctor figyelmeztet.

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

Keresd meg a dokumentumot az access pointban (Recommandnál: a cég dokumentumai a felületen vagy
az API-ban, azonos címzettel és számlaszámmal), majd:

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
| `WebhookReceived` | egy ehhez a céghez tartozó, aláírt webhook ellenőrizve, még a feldolgozása előtt (ismételt kézbesítésnél újra lefut) |

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
`POST /einvoicing/webhooks/{transport}` (neve: `einvoicing.webhook`). Értesítést csak `recommand`
driverű, `webhook_secret`-tel rendelkező transporthoz fogad el; minden más név `404`-et kap.
Beállítás:

1. Futtasd a `php artisan einvoicing:doctor` parancsot. A `transport <név>: webhook` sor akkor
   írja ki a teljes URL-t, ha a transportnak van webhook secretje, és a webhookok be vannak
   kapcsolva; különben figyelmeztetést ad arról, mi hiányzik. A hiányzó `company_id` miatt a
   `config` sor jelez hibát.
2. Vedd fel ezt az URL-t webhookként a Recommandban, az aláíró kulcsot pedig írd a
   `RECOMMAND_WEBHOOK_SECRET` változóba.
3. Más teendő nincs: az útvonal nem futtatja a `web` middleware-csoportot, így sem CSRF-token,
   sem session nem kell hozzá, és CSRF-kivételt sem kell felvenni.

Az aláírást (`X-Signature`, HMAC-SHA256) a csomag a nyers kéréstörzsön ellenőrzi, pontosan úgy,
ahogy megérkezett. A válaszok törzse üres: `401` hiányzó vagy hibás aláírásra, `404` webhook
secret nélküli transportra, minden másra `204`, a figyelmen kívül hagyott értesítésekre is
(másik cég, ismeretlen dokumentum, nem dokumentumra vonatkozó esemény), hogy a Recommand ne
próbálja újra őket. Az ismételt vagy rossz sorrendben érkező értesítések ártalmatlanok: a bejövő
dokumentum azonosítónként egyszer tárolódik, a `delivered` és `failed` állapot pedig már nem
változik.

**Védd az útvonalat.** Saját sebességkorlátja és törzsméret-korlátja nincs. Adj meg throttle-t az
`einvoicing.webhooks.middleware` beállításban, például `['throttle:120,1']`, a kérés törzsének
méretét pedig korlátozd a webszerverben (nginxben `client_max_body_size`, Apache-ban
`LimitRequestBody`). Az előtag az `einvoicing.webhooks.prefix`; az
`einvoicing.webhooks.enabled = false` eltávolítja az útvonalat.

Ha egy kézbesítési státuszról szóló webhook még azelőtt érkezik, hogy a küldő job eltárolta
volna az access point dokumentum-azonosítóját, nem talál sort, és elvész. Valódi queue mellett a
státuszlekérdezés pótolja; `sync` kapcsolaton semmi, ezért ezeket a sorokat kézzel kell
ellenőrizni.

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
'transports' => [
    'other' => ['driver' => 'my-ap', 'token' => env('MY_AP_TOKEN')],
],
```

A factory egy core `Transport`-ot ad vissza (és `InboundSource`-ot is, ha dokumentumot fogad).
Bekapcsolt validációnál a manager a beépített driverhez hasonlóan `ValidatingTransport`-ba
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
| `einvoicing:doctor [--live]` | ellenőrzi a transportokat, a webhook-URL-eket, a validációs szabályokat, a táblákat, a kísérleti keretet elhasznált bejövő dokumentumokat, a queue drivert, a `pcntl`-t, a `retry_after`-t és az SQS-késleltetéseket; `--live` mellett minden inboxot egyszer meghív a hozzáférési adatok ellenőrzéséhez |
| `einvoicing:install-rules [--from=DIR]` | telepíti a Peppol BIS Billing validációs szabályokat |
| `einvoicing:poll-inbound [--transport=] [--include-capped]` | rögzíti a várakozó bejövő dokumentumokat és sorba állítja a feldolgozásukat; ütemezd. A `--include-capped` minden, az `inbound.max_fetch_attempts` keretet elhasznált dokumentumnak még egy kísérletet ad |
| `einvoicing:resolve ID (--sent=DOKUMENTUM-ID \| --resend) [--force]` | rendez egy `unconfirmed` küldést, vagy újra sorba állít egy dokumentumot |

## Mi van ellenőrizve

| Terület | Hogyan | Állapot |
|---|---|---|
| Állapotgép, küldési szabályok, újrapróbálás, foglalás lejárata, státuszlekérdezés, bejövő feldolgozás, webhookok, parancsok | Offline feature tesztek `FakeTransport`-tal és stub transportokkal: 165 teszt, 1022 ellenőrzés mind a hat PHP 8.3, 8.4, 8.5 × Laravel 12, 13 kombináción | Minden tesztfuttatáskor |
| Peppol-validáció a manageren keresztül | Opcionális `peppol-rules` csoport: helyi másolatból telepíti a hivatalos szabályokat, és validáló transporton átküldi a Peppol alappéldát | Kiadás előtt |
| Recommand driver | A core offline tesztjei a Recommand publikált API-leírásából írt fixture-ökkel | **Az éles küldés nincs kipróbálva** |

A core `brick/math` ^0.14.8-at kér, ezt a Laravel 13 is elfogadja.

A csomag egyik tesztje sem használ hálózatot. A core-ban van egy opcionális `recommand-live`
csoport Recommand playground csapathoz; lásd a core
[TRANSPORT.md](https://github.com/intragile/php-einvoicing/blob/main/docs/TRANSPORT.md) leírását.

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

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu).
