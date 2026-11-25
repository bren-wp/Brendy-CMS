# Sigurnost

Prijave sigurnosnih problema šaljite održavatelju putem https://brendigo.com bez objave tajni ili korisničkih podataka. Koristite HTTPS, 2FA, minimalne korisničke ovlasti, rotaciju API tokena, redovite backupove i provjeru restore postupka. Zabranite HTTP pristup privatnim datotekama i izvršavanje uploadanih skripti. PHP ekstenzijski scanner je dodatna obrana, a ne sandbox za proizvoljan nepouzdan PHP kod; instalirajte samo pouzdana proširenja.

Izdanje 2.42.1 dodatno provjerava simboličke poveznice spremišta, zabranu URL API tokena, mrežni DNS pinning i robots pravila. Izvršni sigurnosni testovi i vanjski penetration test nisu zamjena za provjeru produkcijskog poslužitelja. Detalji u AUDIT.md.

Izdanje 2.42.2 proširuje zaštitu izvršnih nastavaka (npr. .php74), provjeru kodiranih URL putanja, poveznica komentara i naziva slika te privatnosti navigacije. HTTP provjere izvedene su na lokalnim Apache fixture datotekama; ne predstavljaju kompletan DAST produkcijske instalacije.

## Dodatne mjere 2.42.2

- U rootu su dopušteni samo `index.php` i `install.php` kao javni PHP entrypointi. Nakon instalacije uklonite installer. Ako imate zasebne integracije, premjestite ih u zaseban virtualni host ili izričito provjerite pravila servera.
- Neočekivana izvršna datoteka u rootu blokira stvaranje nove integrity baseline. Nemojte brisati sumnjive datoteke prije izrade forenzičke kopije; provjerite ih izvan webroota.
- Proširenja s kontrolnim konfiguracijama servera ili višestrukim izvršnim nastavcima se odbijaju. Scanner nije PHP sandbox niti dokaz pouzdanosti dodatka.
- Prije deploya usporedite SHA-256 manifest s čistim paketom pohranjenim izvan webroota. Manifest nije digitalni potpis niti dokaz autentičnosti izvora.
- `DEPENDENCIES.json` dokumentira otvorene nadogradnje; nije potvrda da su sve ovisnosti zakrpane.

## Izdanje 2.42.3
Bootstrap 5.3.6 fizički zamjenjuje 4.6.2. Upstream 5.3.9 i TinyMCE 8.10.2 nisu fizički preuzeti; mrežna provjera OSV/npm nije dostupna. Media plugin ostaje isključen. Vidi AUDIT.md prije produkcijske objave.

## Izdanje 2.42.4
Bootstrap 5.3.9 i TinyMCE 8.10.2 fizički su zamijenjeni iz dostavljenih arhiva. TinyMCE changelog navodi ispravak media XSS od 8.9.3; media plugin nije uključen. Kontrolne sume izvora i lokalnih datoteka u `tests/vendor-manifest.json` omogućuju provjeru dostavljenih bajtova, ne potvrđuju neovisno podrijetlo paketa. Ostale vanjske biblioteke i mrežni OSV audit ostaju otvoreni.


## 2.42.13 — granice datotečne sigurnosti

Direktoriji podataka, staginga i svi njihovi nadređeni direktoriji moraju biti pod kontrolom pouzdanog korisnika, bez pisanja nepouzdanih lokalnih procesa. PHP pathname operacije ne nude potpuno atomsku zaštitu od TOCTOU-a. Nove provjere identiteta i dozvola smanjuju rizik, ali nisu zamjena za izolaciju procesa. ZIP runtime test i PrismJS verifikacija ostaju otvoreni (AUDIT.md).


## 2.42.14 — Prism i datotečno čišćenje

Lokalni Code Sample plugin koristi ograničeni podskup PrismJS 1.30.0 iz dostavljene datoteke, bez opcionalnih mrežnih plugina. CVE-2024-53382 više nije u deklariranom lokalnom rasponu; neovisna verifikacija izvora i CVE audit ostaju otvoreni. Rekurzivno brisanje ne slijedi terminalne symlinkove s kosom crtom; provjere identiteta nisu potpuna TOCTOU zaštita. Vidi AUDIT.md.

## 2.42.15 — granice putanja

Završne kose crte normaliziraju se prije lstat/is_link provjere; mutacije odbijaju terminalne dot segmente i stream wrapper putanje. Provjere su pathname-based, ne jamče atomsku zaštitu od lokalnog procesa koji može mijenjati iste direktorije. ZIP promjena nije izvršno provjerena bez ext-zip.

## 2.42.16 — vlasništvo direktorija

Rekurzivni copy i ZIP rollback provjeravaju spremljeni device/inode direktorija i sve prethodno evidentirane pretke. Zamijenjeni direktorij ili njegova djeca neće se namjerno čistiti. Ovo je best-effort pathname zaštita: PHP ne nudi atomsku kombinaciju provjere identiteta, otvaranja i uklanjanja svih zapisa. Zamjene između provjera, ponovna uporaba inodea, neprovjereni nadređeni direktoriji izvan korijena operacije i platforme s ograničenim stat identitetom ostaju rizici. Ograničite pisanje u webroot i privatne direktorije na pouzdane procese; Windows i ZIP runtime nisu verificirani.


## 2.42.17 — granice brisanja, staginga i restorea

Rekurzivno brisanje planira stavke prije mutacije; odbija posebne datoteke, promjenu uređaja i zamijenjene evidentirane direktorije. Ne jamči sve-or-nothing brisanje. Ograničite pristup direktorijima na vlasnika CMS-a. PHP pathname provjere ne mogu ukloniti zadnju TOCTOU utrku niti inode reuse. ZIP se generira u privatnoj mapi; nakon zatvaranja provjerava se izvor i objavljuje kopija. Restore koji ne može vratiti evidentirane stavke ostavlja recovery podatke i zapisnik. Ne brišite recovery direktorije prije potvrđenog oporavka. ZIP/AES runtime nije verificiran bez ext-zip.

## 2.42.18 — trajni restore journal

Plan se zapisuje atomskim zapisom u `tmp/restore-transaction.json` s dozvolama 0600 prije prvog premještanja. Recovery koristi ograničene relativne nazive i identitete inode/device/type, ne izvršava journal kao PHP. Nepoznato stanje zadržava podatke i blokira zahtjeve. PHP request gate koristi flock, ne blokira izravne statičke datoteke, CLI skripte koje ga zaobilaze ni nekooperativne procese. Standardni pathname API ostavlja TOCTOU prozor i ne jamči fsync direktorija ili oporavak nakon nestanka napajanja. Testirano na Linuxu; Windows/NFS nisu verificirani. Journal nije kriptografski autentificiran; zaštita se oslanja na privatnost lokalnog spremišta i povjerenje u lokalni korisnički račun.

## 2.42.19 — recovery evidencija i integritet

Journal schema 2 provjerava SHA-256 nad datotekama `databases` (ne i prazne direktorije, medije, workspaces ili cijeli backup nakon namjernih izmjena). Schema 1 ostaje čitljiv bez tvrdnje o digestu. Evidencija nije kriptografski autentificirana i nije otporna na lokalnog napadača s pravom pisanja u privatni direktorij. Čišćenje iz panela ne briše datoteke; samo `rmdir` nad ponovno provjerenim praznim direktorijem. Zaključavanje je kooperativno, bez potpunog pathname TOCTOU jamstva. Power-loss i Windows nisu potvrđeni.

Lock generacija sada se čita iz read/write descriptora i mijenja pri automatskom recoveryju. Synchronous staging/rollback brisanje predaje očekivani identitet korijena.

## 2.42.20 — granice HMAC-a i recovery GC-a

HMAC autentificira sadržaj journala i ledgera uz lokalni slučajni ključ; ledger i marker ostaju u trajnom privatnom tmp direktoriju, izvan premještanog stabla. HMAC nije enkripcija ni vanjski trust anchor. Napadač s ključem ili mogućnošću vraćanja cijelog konzistentnog starijeg stanja može zaobići anti-rollback; kooperativni flock nije zaštita od svih lokalnih procesa. Nepotpisani legacy journal dopušten je samo kad nema autentificiranog stanja/evidencije; dovršite ga prije nadogradnje. Istodobni gubitak svih autentificiranih artefakata ne može se neovisno prepoznati. Prekid pri prvom stvaranju ključa prije ledgera blokira obnovu i zahtijeva pregled.

Digest obuhvaća relativne nazive, tipove, prazne direktorije i sadržaj običnih datoteka, ne ACL/mtime/permissions. Legitimne UUID upload aliase hashira kao tekst linka, ne slijedi ih. Ostali linkovi i hardlinkovi se odbijaju. U postprocessingu zasebni immutable digest izuzima samo generirane protection datoteke, security/core-integrity i security write lock te validirane upload aliase; konačni potpuni digest uključuje ih. Standardni PHP API ostavlja TOCTOU i power-loss ograničenja.

GC zahtijeva potvrđenu staru transakciju, admin ovlasti, CSRF guard, ekskluzivni gate, potvrdu i ponovno hashiranje starog rollback stabla. Ne uklanja nepotvrđene podatke. Brisanje nije atomsko; nakon djelomičnog brisanja hash više ne odgovara i treba ručni pregled. Nema dokaza da je vanjska sigurnosna kopija dostupna, zato je administrator mora provjeriti prije potvrde.

## 2.42.21 — ograničenja redundantnog recoveryja

Primarni indeks ostaje autoritativan, čak i kada mirror ima noviju autentificiranu verziju; ne promovirajte mirror samo zato što je potpis valjan. Duplicirani immutable segment smije se koristiti samo ako odgovara hash referenci u valjanom primarnom indeksu. Vanjski checkpoint je operatorski upravljan prikvačeni prefiks, nije automatski ažuriran monotoni hardverski brojač. Potpuni rollback na stanje novije ili jednako zadnjem checkpointu ostaje moguć. Key ring se privremeno nalazi na istom privatnom filesystemu; stari ključ nije sigurno obrisan s backup medija, a key ring nije zaštićen od potpunog lokalnog rollbacka. Rotacija uz postojeće GC artefakte namjerno se odbija.

GC autentificira plan i cursor u različitim HMAC domenama. Cursor dopušta ponavljanje točno jedne autorizirane operacije ako je ona već izvršena. Svaki nastavak provjerava preostalo stablo; prije svakog uklanjanja provjerava identitet i sadržaj, a rmdir odbija neočekivane datoteke. Standardni pathname API i kooperativni flock i dalje ne pružaju potpunu TOCTOU ni power-loss zaštitu. Autentificirani GC dokazi i stari nereferencirani segmenti ostaju na disku, bez automatskog brisanja.


## 2.42.22 — neovisni potpis, migracija GC dokaza i shard GC

Prije nadogradnje zaustavite promet, dovršite restore i ranije započetu rotaciju na starom kodu, provjerite neovisnu kopiju svih podataka uključujući tmp. Nadogradite jezgru i Backup Manager zajedno. Ne vraćajte staru verziju nakon migracije GC dokaza/rotacije; vratite konzistentnu kopiju cijele instalacije uz koordinaciju neovisnog checkpointa.

Opcionalni potpisani checkpoint zahtijeva PHP ext-sodium, odvojeni operatorski račun ili host i konfiguraciju zaštićenu izvan kontrole CMS-a. Potpisni tajni ključ nikada ne stavljajte u CMS ili njegov backup. CMS mora imati samo javni ključ (64 heksadecimalna znaka) u `BRENDY_RECOVERY_ANCHOR_PUBLIC_KEY` i kanonsku apsolutnu putanju najnovijeg potpisanog checkpointa u `BRENDY_RECOVERY_CHECKPOINT` (izvan sadržajnog stabla). Obje postavke i datoteka moraju biti zaštićene od CMS upisa. Ako se koristi zaseban host, prijenos mora imati zaštitu od replaya; lokalna kopija starog potpisanog checkpointa sama po sebi nije dovoljan dokaz svježine. Neovlašteno vraćanje cijelog operatorskog spremišta, isključenje konfiguracije ili napad prije objave najnovijeg sidra nisu pokriveni. Stari nepotpisani checkpoint i dalje radi samo bez konfiguriranog javnog ključa.

Na zaustavljenom CMS-u izvezite `php tests/recovery-ledger-admin.php anchor-proposal /absolute/content/root > proposal.json`. Datoteka sadrži povijest recovery transakcija (ne HMAC ključ); prenesite je privatno. Na neovisnom operatoru generirajte Ed25519 par pomoću `sodium_crypto_sign_keypair()` i spremite `bin2hex(sodium_crypto_sign_secretkey($pair))` u privatni `secret.hex` (0600) te zasebno javni ključ dobiven `bin2hex(sodium_crypto_sign_publickey($pair))`. Nemojte ispisivati tajni ključ u log. Na operatoru s kopijom pouzdanog koda pokrenite:

```sh
php tests/recovery-anchor-operator.php initialize /protected/anchor.json /protected/secret.hex /protected/proposal.json
php tests/recovery-anchor-operator.php advance /protected/anchor.json /protected/secret.hex /protected/proposal.json
```

`initialize` je samo za novu, prethodno verificiranu instalaciju i odbija postojeće sidro. `advance` traži postojeće valjano sidro i odbija stariji ili divergentni prefiks. Prije prelaska s nepotpisanog checkpointa provjerite postojeći prefiks i početni proposal te tek zatim promijenite konfiguraciju. Ne inicijalizirajte ponovno izgubljeno sidro; obnovite ga iz neovisne provjerene kopije. Osvježite nakon svake dovršene transakcije u kontroliranom maintenance postupku; alat ne objavljuje automatski na udaljeni servis. Potpisana datoteka mora biti čitljiva CMS-u, ali ne zapisiva. Opcionalni način ne mijenja minimalnu PHP verziju za instalacije bez potpisanog sidra.

Rotacija `php tests/recovery-ledger-admin.php rotate /absolute/content/root` sada prihvaća autentificirane GC planove i cursore koji odgovaraju receiptima; nevaljani ili orphan cursor blokiraju operaciju. GC plan i cursor payload ostaju identični. Nakon rotacije osvježite admin stranicu za novi akcijski token. Kod prekida ponovite rotate, ne brišite privremeni dual-key ring. Rotacija i čišćenje zahtijevaju maintenance i ekskluzivni gate.

Nereferencirani shardovi: najprije `php tests/recovery-ledger-admin.php orphan-list /absolute/content/root`; nakon neovisno provjerene kopije i pregleda popisa eksplicitno `php tests/recovery-ledger-admin.php orphan-prune /absolute/content/root`. Obje naredbe zahtijevaju valjan primarni i usklađeni mirror, neaktivan restore i aktivni ključ. Uklanjaju se samo datoteke s točnim nazivom, hashom, privatnim regularnim identitetom, valjanim HMAC-om i starošću od najmanje 24 sata, nereferencirane u oba indeksa. Malformirani, nepoznati i shardovi potpisani umirovljenim ključem ostaju sačuvani. Ne postoji automatska retencija povijesti ni globalni garbage collection. Nakon prekida ponovite list i pregledajte stanje prije prune.

Testovi: `php tests/run.php`, `python3 tests/recovery-publication-faults.py`, `python3 tests/recovery-anchor-cli-smoke.py`, `php tests/recovery-memory-benchmark.php`. Potpuni instalacijski E2E i stvarni ZIP test i dalje zahtijevaju odgovarajuće PHP ekstenzije. Ledger čitanje još rekonstruira cijelu povijest; granica 20.000 zapisa ostaje. Ovo nije potvrđeno produkcijsko izdanje.

## 2.42.23 — retirement evidence i segmentni query

Query provjerava HMAC, digest i slijed svakog segmenta i svih identifikatora, ne samo traženog prozora. Konfigurirani vanjski checkpoint provjerava se streaming hashom prefiksa i ponovnom provjerom na kraju. Privremeno se drži skup ID-a; memorija nije konstantna u odnosu na broj zapisa. Mutacije i operatorski prijedlozi još učitavaju cijelu povijest. Kooperativni restore gate obavezan je pri čitanju i pisanju. Retirement certifikati potvrđuju samo konkretne SHA-256 bajtove verificiranih starih segmenata, nisu autorizacija za proizvoljne putanje. GC provjerava oba indeksa, starost, identitet i sadržaj prije unlinka. Rotacija čuva prethodni ključ do objave certifikata i provjere nove generacije. Dokazi ne omogućuju detekciju rollbacka cijelog lokalnog spremišta; neovisno sidro mora biti izvan CMS kontrole. Retention ledger ograničen je na 50.000 digesta i 16 MiB, skeniranje tmp na 10.000 stavki, povijest na 20.000 zapisa. Ne postoji automatsko brisanje evidencije ili mrežni servis za trust anchor. U slučaju oštećenja ili nejasnog stanja prekinite rad i sačuvajte dokaze.

### 2.42.24 — derived lookup cache and streaming writes

`restore-ledger-lookup.json` is private, HMAC domain-separated and generation-bound. Only an explicit positive diagnostic lookup may use direct shard lookup; a configured anchor, miss, stale or corrupt cache triggers a full verification. Global recovery and destructive paths never trust this cache. Mirror publication remains preparatory, primary publication is the sole commit. A failed derived-cache write cannot reverse a committed transaction. A local attacker able to roll back the full key ring and ledger still requires independent checkpoint protection; no new remote anchor service is included. Archive exports are sensitive, unsigned JSON; protect externally and verify the independent checkpoint. Streaming rotation retains dual keys until both current indexes and referenced shards and GC evidence are verified with the new key. PHP pathname races and physical power-loss guarantees remain unresolved.

### 2.42.25 — segmentirani cache i Ed25519 arhiv

UUID cache ima HMAC manifest vezan uz SHA-256 primarnog ledgera, do 256 hash-bucketa s HMAC potpisima i točnim digestima. Pogođeni bucket objavljuje se prije manifesta, nakon primarnog commita. Pogrešan ili zastario cache prelazi na puni lookup; pozitivna brza pretraga nije globalna provjera. Puni reader i GC ostaju fail-closed. Izgradnja spoola koristi privremene datoteke; globalni set UUID-ova i dalje O(n).
Neovisni operator potpisuje arhivski manifest Ed25519 domenski odvojenim potpisom, s identitetom instalacije, brojem transakcija, hashom prefiksa, SHA-256 i veličinom točnih bajtova izvoza. Provjerava strukturu receipata, jedinstvenost ID-ova, prethodni manifest i prefiks. Blob se objavljuje prije manifesta i čuva; nema automatskog brisanja. Verifikacija s pouzdanim svježim checkpointom odbija stariji ili divergentni arhiv. Lokalna monotonost ne štiti od vraćanja cijelog operatorskog spremišta bez neovisnog checkpointa. PHP standardni pathname API i procesni SIGKILL test ne dokazuju power-loss sigurnost.

### 2.42.26

Segmentirani arhiv koristi neovisni Ed25519 ključ, potpisani manifest i provjeru SHA-256/Merkle korijena. Privatni direktorij i aktualni checkpoint moraju biti zaštićeni od rollbacka izvan CMS-a. Neovisni servis i fizička trajnost nisu potvrđeni. Retention pregled ne autorizira brisanje.

### 2.42.27 — granice operatorskog arhivskog GC-a

GC koristi eksplicitnu potvrdu inventara, Ed25519 potpisani write-ahead journal i cursor, ekskluzivno kooperativno zaključavanje, provjeru istog checkpointa i manifesta, identiteta i SHA-256 sadržaja. Nedostajući kandidat pri ponavljanju može označavati dovršeni unlink; ne dokazuje uzrok nestanka. Ne postoji zaštita od napadača koji kontrolira operatorski račun ili vraća cijeli operatorski disk i neovisno sidro. PHP pathname API ne uklanja sve TOCTOU utrke, a SIGKILL testovi nisu dokaz otpornosti na fizički nestanak napajanja. Ne dijelite privatni Ed25519 ključ s CMS-om. Arhivski GC ne uklanja referencirane zapise niti potpisane manifeste. Svježina nonce odgovora nije dokaz da se operatorov checkpoint ažurira automatski; nonce mora generirati i jednokratno potrošiti neovisni verifikator.


## 2.42.28 — hijerarhijski aktivni ledger i neovisna provjera

Prije nadogradnje dovršite restore, zaustavite promet i izradite neovisnu kopiju cijelog privatnog sadržaja (uključujući `tmp`, ključeve, GC dokaze, ledger i sve kopije). Nadogradite jezgru i Backup Manager zajedno, bez prepisivanja korisničkog sadržaja. Stari v1/v2 formati i dalje se čitaju. V3 automatski nastaje pri sljedećem zapisu s najmanje 16 punih shardova; operator može eksplicitno migrirati povijest od najmanje 256 zapisa: `php tests/recovery-ledger-admin.php index-migrate /absolute/content/root`. Obje kopije indeksnih stranica (do 64 sharda) objavljuju se prije mirror i primary indeksa. Primary je jedina točka commita. Migracija ne mijenja stare receiptove, GC planove i cursore. Ne vraćajte stari kod nad već migriranim podacima; povratak zahtijeva kompatibilnu cjelovitu kopiju i provjeru neovisnog checkpointa. Ako primary nije valjan, nema promocije mirrora. `reconcile` popravlja kopije iz verificiranog primaryja. Stare stranice namjerno nisu predmet GC-a.

Novi limit je 100.000 aktivnih transakcija; prethodne granice navedene u povijesnim odjeljcima odnose se na stara izdanja. Nije neograničen. Potpuni query još ima O(N) memorije za jedinstvenost ID-a i obilazi sve shardove. Stariji `read()` i monolitni `save()` i dalje rekonstruiraju povijest; streaming CLI `checkpoint` i `archive-export-v2` su preporučeni. Na 100.000 zapisa legacy seed zahtijevao je 124 MB vršne memorije, dok su testirane operacije nakon seeda prošle pod 32 MiB. Stara ograničenja GC skeniranja, certifikata i veličine pojedinačnih datoteka ostaju. Nema compactiona niti automatskog brisanja starih stranica. Testiranje milijun aktivnih transakcija nije izvršeno.

Neovisni operator može objaviti checkpoint iz provjerenog segmentiranog arhiva: `php tests/recovery-segmented-archive-operator.php checkpoint-initialize /private/archive /private/secret.hex /private/checkpoint-dir/anchor.json` ili `checkpoint-advance` nakon početnog postavljanja. Direktoriji moraju biti privatni, potpisni ključ 0600 i izvan CMS kontrole. Advance odbija nestali checkpoint, regresiju i divergentni prefiks. Dovršeni checkpoint prenosi se putem neovisno zaštićenog kanala do konfiguriranog CMS sidra; nema automatskog mrežnog servisa. Za jednokratnu provjeru operator koristi `php tests/recovery-anchor-freshness.php issue /private/nonce-dir PUBLIC_KEY_HEX /private/expected-signed-checkpoint.json`, prenosi nonce neovisnom signer-u (`respond`), te prosljeđuje odgovor `consume /private/nonce-dir NONCE < response.json`. Nonce direktorij mora biti 0700, zasebno zaštićen od CMS-a i rollbacka; zapisi 0600. Potrošnja se zapisuje prije uspjeha, nevaljani ili istekli odgovori se odbijaju. Provjera je vezana uz točno očekivano potpisano stanje, ne dokazuje aktualnost ako očekivani checkpoint nije osvježen. Nema automatske retencije nonce zapisa. `partialConsistencyProof` i `verifyPartialConsistency` dokazuju proširenje djelomičnog segmenta pomoću novih boundary bajtova i hashova; oba manifesta moraju biti prethodno verificirana i pouzdana. Dokaz može otkriti do 256 receipata; tretirajte ga kao privatne podatke.

Ovo je staging kandidat; stvarni ZIP/AES-256, instalacijski i upgrade E2E, 1M aktivnih zapisa, Windows/NFS, nestanak napajanja i potpuni security/CVE audit još nisu potvrđeni.

### Authenticated operator nonce store (development)
Provision a **new empty** private 0700 operator directory with `php tests/recovery-anchor-freshness.php initialize /absolute/private/nonce-dir`. Existing unsigned nonce records are intentionally not accepted or migrated; allow old challenges to expire and keep the old directory offline for review. Protect `nonce-auth.key` (0600), never publish it. Run `php tests/recovery-anchor-freshness.php prune /absolute/private/nonce-dir` from the trusted operator account for bounded retention after a 24-hour grace period. A missing or modified record fails closed. HMAC does not prevent rollback of a previously authentic record or the whole operator filesystem; independent storage protection is still required. No automatic production scheduling is configured.

### Authenticated index page retirement (development)
The existing HMAC retirement ledger now accepts a separate `pages` digest map; legacy shard-only certificates remain readable. Rotation certifies exact old-key page bytes before retiring the key. In maintenance mode, use `php tests/recovery-ledger-admin.php page-orphan-list /absolute/content/root` to inspect candidates, then `page-orphan-prune` only after a verified backup. Current primary/mirror references are excluded, candidates must be at least 24 hours old, and identity, digest, root and retirement proof are rechecked before unlink. This is explicit, repeatable orphan cleanup, not active-history compaction or a durable per-deletion GC journal. Do not downgrade to older recovery code after enabling new maintenance functions.

### Adaptive authenticated UUID trie (development)
Derived lookup buckets split at 128 IDs into authenticated prefix nodes, maximum prefix length eight. A parent pins each child's exact SHA-256 digest; lookup checks the target receipt and generation as before. Legacy flat roots remain readable, and `index-rebuild` regenerates the cache. Interrupted updates or tampering may invalidate the cache and trigger full verification, not alter authoritative records. The exact-uniqueness spool recursively partitions oversized temporary buckets, fails closed at a bounded depth, and retains private handles only. The active ledger limit remains 100,000 pending end-to-end scale and directory/retirement budget changes. The million-UUID test covers the spool only.

### Hierarchical lookup storage and large-ledger opt-in (development)
The cache retains legacy flat root buckets, with child nodes in private `restore-ledger-lookup-nodes/<prefix>/...` directories. Rebuild creates the hierarchy; old flat children remain fallback evidence and are not deleted automatically. The default admission limit is 100,000; `BRENDY_RECOVERY_LARGE_LEDGER=1` enables an experimental 1,000,000-record admission ceiling. Previously committed records remain readable if the setting is removed. Do not enable this on production/shared hosting until actual execution time, disk capacity, backups, independent anchor operation and platform recovery are verified. Maintenance directory scans remain capped at 65,536 entries; retirement certificates remain capped at 50,000 per kind, and orphan sweeps at 1,000 candidates per invocation. Repeat maintenance sweeps rather than raising limits indiscriminately. No active history compaction or automatic destruction is enabled. The million-record fixture uses a new empty private directory and synthetic receipts; it is not an installer/upgrade E2E test.

### Source completeness and upstream verification
The CI source inventory pins required paths from the verified import, not mutable content or an obsolete build ZIP. A new checkout must contain the full CMS, five bundled themes, nine extensions, and the empty content placeholder. JSON data is generated on installation; no SQL database service is required. The dependency audit checks Parsedown against the exact Packagist release source and jQuery UI against the official distribution executable bytes; neither a matching version string nor a local vendor manifest alone proves upstream origin. Modified TinyMCE/DOMPurify/Prism, bundled Popper attribution and legacy sortable provenance remain subject to independent verification. The security gate remains fail-closed.

### Custom editor provenance checks
The dependency audit now verifies the exact embedded DOMPurify distribution and ordered Prism language components against SHA-512-checked npm tarballs. It separately checks every shipped upstream TinyMCE asset and the bytes outside documented integration boundaries. Local notices are not executable. An unverified locale, differing executable bytes, unknown wrapper, missing component or network failure keeps the audit red. This is a provenance check, not an independent review of the custom integration or proof of commercial redistribution rights. No vendor assets were replaced.
