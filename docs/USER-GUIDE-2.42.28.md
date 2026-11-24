# Brendy CMS 2.42.28

Brendy CMS je samostalni PHP CMS za objavu stranica, podstranica i članaka, upravljanje korisnicima, temama i dodacima. Izdanje uključuje pet tema i devet dodataka te centralizirane SEO postavke. Podaci se čuvaju u zaštićenim JSON datotekama, ne u SQL bazi.

Za instalaciju su potrebni PHP 8.2+ (preporučeno 8.4), mbstring, json, gd, dom, session, fileinfo, filter i hash. Za mrežne zahtjeve i ZIP sigurnosne kopije potrebni su curl i zip. Koristite HTTPS i konfiguraciju poslužitelja koja štiti privatne direktorije.

Otvorite `install.php` samo na novoj instalaciji. Za postojeću instalaciju slijedite `NADOGRADNJA.md`. Testovi: `php tests/run.php`. Ograničenja provjere i popis promjena nalaze se u `AUDIT.md` i `PROMJENE.md`.

Bootstrap 5.3.9 i TinyMCE 8.10.2 fizički su integrirani iz dostavljenih distribucija. Izdanje je kandidat za staging, ne potvrđeno produkcijsko izdanje.

Lokalni TinyMCE 8.10.2 s DOMPurify 3.5.0 zadani je editor. Tiny Cloud je dostupan samo uz izričiti poslužiteljski opt-in; udaljena verzija sanitizatora nije potvrđena. Ova personalizirana distribucija sadrži preglednički API ključ; ne objavljujte je javno.

Bootstrap Icons 1.13.2 uključuje lokalni CSS i fontove. Test ikona i kalendara: `python3 tests/browser-icons-datepicker.py` (Playwright i Chromium).

Odabir datuma i vremena koristi nativni HTML datetime-local bez stare jQuery DateTimePicker biblioteke. Vrijeme se tumači u vremenskoj zoni CMS-a, ne pretvara se u UTC. jQuery 4.1.0 fizički je integriran iz dostavljenog koda.

Izvještaji ovog izdanja i CycloneDX 1.6 inventar nalaze se u `tests/reports/`. Pokrenite `php tests/zip-runtime-smoke.php` s ext-zip prije uporabe backup/restore funkcija na produkciji.

Backup Manager prikazuje posljednji ishod oporavka i zaostale staging direktorije. Za testove atomskog pisanja na Linuxu: `python3 tests/atomic-write-faults.py`.

## 2.42.20 — autentificirani oporavak

Prije nadogradnje dovršite aktivnu obnovu na prethodnoj verziji i napravite neovisnu kopiju cijelog privatnog sadržaja, uključujući tmp. Zamijenite sve datoteke jezgre i Backup Manager zajedno. Novi ključ i ledger nastaju pri prvoj pripremi transakcije; ne distribuiraju se u ZIP-u. Zaštitite `tmp/restore-ledger-key.php`, `tmp/restore-ledger.json`, journal i receipt od HTTP pristupa, automatskog čišćenja i nekooperativnih upisa. Nemojte rotirati/brisati ključ ili ledger samostalno. Ako nastane nejasno stanje, zaustavite promet, kopirajte kompletno stanje i zatražite ručni oporavak. Ne pokušavajte downgrade dok postoji aktivan ili blokiran journal. Stariji kod ne razumije schema 3.

U Recovery Managementu pregledajte povijest i direktorije. Prije trajnog uklanjanja prethodnog sadržaja provjerite neovisnu sigurnosnu kopiju. Brisanje nije reverzibilno; prekid rekurzivnog brisanja može ostaviti nepotpun direktorij koji više nije automatski podoban. Ledger je ograničen na 10.000 dovršenih zapisa i 16 MiB, marker na 4 MiB; na granici se nova obnova odbija, nema automatskog brisanja povijesti. Hashiranje je ograničeno na 1 GiB i 100.000 stavki po stablu, uključujući kopirani backup workspace, dubina 128. Provjerite prostor i trajanje prije produkcijske obnove. Testirajte `php tests/run.php`, `python3 tests/atomic-write-faults.py`, `php tests/zip-runtime-smoke.php` i stvarnu instalaciju s potrebnim ekstenzijama.

## 2.42.21 — redundantni ledger, checkpoint i nastavak čišćenja

Prije nadogradnje završite restore na 2.42.20, zaustavite promet i izradite neovisnu sigurnosnu kopiju cijelog privatnog sadržaja, uključujući `tmp`. Nadogradite jezgru i Backup Manager zajedno. Stari jednodatotečni ledger čita se bez migracije unaprijed; pri sljedećem spremanju s najmanje 256 dovršenih zapisa automatski se objavljuje autentificirani indeks i sadržajno adresirani segmenti. `restore-ledger.json` je isključiva točka commita, `restore-ledger-mirror.json` može sadržavati tek pripremljeni kandidat, pa se nikada automatski ne promovira. Ako primarni indeks nedostaje ili nije valjan, zaustavite promet, sačuvajte sve datoteke i zatražite pregled. Kopija segmenta može se koristiti samo kada njen hash točno odgovara autentificiranom primarnom indeksu.

Na zaustavljenoj instalaciji kao vlasnik CMS-a pokrenite (zamijenite `/apsolutni/put/sadrzaj` stvarnom kanonskom putanjom):

```sh
php tests/recovery-ledger-admin.php status /apsolutni/put/sadrzaj
php tests/recovery-ledger-admin.php reconcile /apsolutni/put/sadrzaj
php tests/recovery-ledger-admin.php checkpoint /apsolutni/put/sadrzaj
php tests/recovery-ledger-admin.php rotate /apsolutni/put/sadrzaj
```

`status` ne ispisuje tajni ključ; `reconcile` obnavlja kopije iz valjanog primarnog stanja, nikada obrnuto; `checkpoint` ispisuje kontrolnu točku dovršene povijesti na stdout. Sačuvajte je atomski u zasebnom, neovisno administriranom spremištu, izvan sadržaja i izvan dosega pisanja PHP računa. Konfigurirajte `BRENDY_RECOVERY_CHECKPOINT` na apsolutnu kanonsku putanju čitljivu PHP procesu. Datoteka mora biti stvarno neovisna od lokalnog rollbacka; ne smije biti simbolička poveznica. Ne postavljajte varijablu prije inicijalizacije ledgera. Osvježite checkpoint nakon novih potvrđenih transakcija izvan CMS-a; stariji checkpoint štiti samo prikvačeni prefiks. U okruženjima s više instalacija postavite zasebnu konfiguraciju po instalaciji. Nestali, nevaljani ili nepodudarni checkpoint blokira oporavak. Na običnom shared hostingu bez odvojenog spremišta ostavite ga nekonfiguriranim i prihvatite ograničenje.

`rotate` zahtijeva zaustavljen promet, ekskluzivni gate, valjan primarni ledger, bez aktivnog ili blokiranog restorea i bez bilo kakvih GC planova/cursora (uključujući završene). Ne brišite GC dokaze radi zaobilaženja ograničenja. Key ring privremeno čuva stari i novi ključ; novi primarni indeks, mirror i segmenti moraju biti verificirani samo novim ključem prije umirovljenja starog. Ako je prekinuta, ponovite istu naredbu, ne stvarajte novi ključ ručno; nove restore i verified GC operacije blokirane su dok rotacija nije dovršena. Stari backup ključevi i orphan segmenti ostaju predmet zaštite i odvojene retencijske politike. Postojeće povijesne checkpoint vrijednosti ostaju kompatibilne s rotacijom. Lokalni rollback cijelog key ringa i ledgera i dalje nije moguće potpuno prepoznati bez neovisnog svježeg sidra.

GC provjerava cjelovito stablo prije autentificiranog postorder plana i zapisuje cursor prije svake pojedinačne destruktivne operacije. Nakon prekida administrator ponovno odabire **Nastavi provjereno čišćenje**. Dopušten je samo nedostajući trenutno autorizirani unos; ranije uklonjeni unosi moraju ostati odsutni, svi preostali sadržaji i nadređeni direktoriji provjereni. Ne brišite plan/cursor; ostaju kao dokaz, i nakon završetka. Brisanje nije reverzibilno; prije potvrde provjerite neovisnu kopiju. GC plan ima najviše 10.000 stavki; povijest do 20.000 zapisa, 256 po punom segmentu, 512 KiB po segmentu, indeks do 16 MiB. Učitavanje još rekonstruira cijelu povijest u memoriji. Integritet ima prethodnu granicu 1 GiB / 100.000 stavki / dubina 128, ne provjerava ACL ili sve metapodatke. Zaštitite sve `restore-*` datoteke u tmp od HTTP pristupa i automatskog čišćenja. Ne pokušavajte downgrade koda nakon sharding migracije ili rotacije bez odgovarajuće potpune sigurnosne kopije.

Ovo je staging kandidat. Obavezno provjerite potrebne PHP ekstenzije, stvarni ZIP/AES-256 restore, instalaciju i upgrade na izoliranom okruženju. Testovi `php tests/run.php`, `python3 tests/recovery-publication-faults.py`, `python3 tests/atomic-write-faults.py` i `php tests/recovery-performance.php` ne zamjenjuju produkcijski E2E i power-loss test.


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

## 2.42.23 — segmentni reader i dokazi umirovljenja

Nadogradite jezgru i Backup Manager zajedno, tek nakon dovršetka restorea i uz neovisnu kopiju privatnog sadržaja. Paginirani pregled i status zadržavaju samo potrebne receipte, uz provjeru cijele autentificirane povijesti i jedinstvenosti identifikatora. Stari `read()` ostaje kompatibilan. Limit 20.000 transakcija ostaje. Lookup: `php tests/recovery-ledger-admin.php lookup /absolute/content/root 257` (ili puni 32-znamenkasti heksadekadski ID). Rotacija stvara privatni HMAC ledger `tmp/restore-ledger-retirement.json` s dokazima prethodno provjerenih segmenata; sačuvajte ga u backupu. `orphan-list` i `orphan-prune` mogu prepoznati certificirane stare segmente samo ako ih ne referencira nijedan indeks; starost 24 sata ostaje. Ne uklanjajte dokaze ručno.

Za postojeće neovisno sidro operator može periodično izvršiti `advance-stdin` i dostaviti prijedlog standardnim ulazom preko zasebno konfiguriranog, autentificiranog SSH kanala. Primjer (pokrenuti pod nadziranim operatorskim rasporedom, uz unaprijed provjerene host ključeve i timeout):

```sh
set -o pipefail
ssh cms-host 'php /srv/cms/tests/recovery-ledger-admin.php anchor-proposal /srv/cms/sadrzaj' | timeout 60 php /opt/brendy/tests/recovery-anchor-operator.php advance-stdin /secure/anchor.json /secure/secret.hex -
```

CMS ne smije imati pristup privatnom ključu niti pisanje u autoritativno sidro. Dostava najnovijeg sidra u CMS-u čitljivo, ali CMS-u nezapisivo spremište mora biti posebno konfigurirana; ne koristi se nepouzdani cache ni stara kopija. Nema ugrađenog udaljenog servisa niti automatske provjere svježine; bez periodičke neovisne objave zaštita vrijedi do posljednjeg checkpointa.

## 2.42.25 — Streaming recovery mutations i indeks

Restore priprema, commit i finalizacija koriste `RecoveryLedger::mutate()`. Provjera i dalje prolazi sve segmente, ali se postojeći immutable shardovi ne kodiraju niti zapisuju ponovno; novi puni segment objavljuje se prije primarnog indeksa. Rotacija ključa ponovno potpisuje segment po segment, a GC receipte čita u skupinama do 100. Stari API `read()/save()` ostaje kompatibilan.

Opcionalni autentificirani UUID cache izradite s `php tests/recovery-ledger-admin.php index-rebuild /absolute/content/root`. `lookup-indexed /absolute/content/root UUID` provjerava HMAC cachea, točan hash primarnog indeksa i ciljanu transakciju. Izričito je dijagnostički put za brzi pozitivan lookup: ne provjerava nepovezane shardove. `lookup` i recovery gate i dalje provjeravaju cijelu povijest. Ako je cache zastario, ne postoji, oštećen je, ne nalazi ID ili je vanjski checkpoint konfiguriran, koristi se potpuna provjera. Cache se održava nakon uspješne mutacije i pokušava obnoviti nakon rotacije; neuspjeh ne mijenja commit. Indeks nije autoritet niti zamjena za ledger. 

`archive-export /absolute/content/root` i `anchor-proposal /absolute/content/root` iznose istu provjerenu JSON povijest kroz stdout, uz privremeni spool, bez uklanjanja povijesti. Zaustavite promet, zaštitite izlaz (umask 077, privremena datoteka, provjerite exit status, pa preimenujte), potpišite checkpoint na neovisnom operatoru i čuvajte izvoz izvan web roota. Izvoz nije samostalno potpisan arhiv ili zamjena za cijelu sigurnosnu kopiju. Granica 20.000 transakcija i dalje vrijedi. Writer ne čuva sve receipte, ali provjera jedinstvenosti UUID-a i opcionalni cache još rastu s brojem zapisa. `php tests/recovery-writer-benchmark.php` mjeri izolirani writer bez aktiviranog cachea.

## 2.42.27 — segmentirani recovery arhiv

Opcionalni neovisni operatorski arhiv podržava NDJSON, Ed25519, Merkle korijen i nedestruktivni retention pregled. Testiran do 1.000.000 sintetičkih arhivskih receipata; aktivni ledger zadržava limit od 20.000. Upute: NADOGRADNJA.md.


## 2.42.28 — hijerarhijski aktivni ledger i neovisna provjera

Prije nadogradnje dovršite restore, zaustavite promet i izradite neovisnu kopiju cijelog privatnog sadržaja (uključujući `tmp`, ključeve, GC dokaze, ledger i sve kopije). Nadogradite jezgru i Backup Manager zajedno, bez prepisivanja korisničkog sadržaja. Stari v1/v2 formati i dalje se čitaju. V3 automatski nastaje pri sljedećem zapisu s najmanje 16 punih shardova; operator može eksplicitno migrirati povijest od najmanje 256 zapisa: `php tests/recovery-ledger-admin.php index-migrate /absolute/content/root`. Obje kopije indeksnih stranica (do 64 sharda) objavljuju se prije mirror i primary indeksa. Primary je jedina točka commita. Migracija ne mijenja stare receiptove, GC planove i cursore. Ne vraćajte stari kod nad već migriranim podacima; povratak zahtijeva kompatibilnu cjelovitu kopiju i provjeru neovisnog checkpointa. Ako primary nije valjan, nema promocije mirrora. `reconcile` popravlja kopije iz verificiranog primaryja. Stare stranice namjerno nisu predmet GC-a.

Novi limit je 100.000 aktivnih transakcija; prethodne granice navedene u povijesnim odjeljcima odnose se na stara izdanja. Nije neograničen. Potpuni query još ima O(N) memorije za jedinstvenost ID-a i obilazi sve shardove. Stariji `read()` i monolitni `save()` i dalje rekonstruiraju povijest; streaming CLI `checkpoint` i `archive-export-v2` su preporučeni. Na 100.000 zapisa legacy seed zahtijevao je 124 MB vršne memorije, dok su testirane operacije nakon seeda prošle pod 32 MiB. Stara ograničenja GC skeniranja, certifikata i veličine pojedinačnih datoteka ostaju. Nema compactiona niti automatskog brisanja starih stranica. Testiranje milijun aktivnih transakcija nije izvršeno.

Neovisni operator može objaviti checkpoint iz provjerenog segmentiranog arhiva: `php tests/recovery-segmented-archive-operator.php checkpoint-initialize /private/archive /private/secret.hex /private/checkpoint-dir/anchor.json` ili `checkpoint-advance` nakon početnog postavljanja. Direktoriji moraju biti privatni, potpisni ključ 0600 i izvan CMS kontrole. Advance odbija nestali checkpoint, regresiju i divergentni prefiks. Dovršeni checkpoint prenosi se putem neovisno zaštićenog kanala do konfiguriranog CMS sidra; nema automatskog mrežnog servisa. Za jednokratnu provjeru operator koristi `php tests/recovery-anchor-freshness.php issue /private/nonce-dir PUBLIC_KEY_HEX /private/expected-signed-checkpoint.json`, prenosi nonce neovisnom signer-u (`respond`), te prosljeđuje odgovor `consume /private/nonce-dir NONCE < response.json`. Nonce direktorij mora biti 0700, zasebno zaštićen od CMS-a i rollbacka; zapisi 0600. Potrošnja se zapisuje prije uspjeha, nevaljani ili istekli odgovori se odbijaju. Provjera je vezana uz točno očekivano potpisano stanje, ne dokazuje aktualnost ako očekivani checkpoint nije osvježen. Nema automatske retencije nonce zapisa. `partialConsistencyProof` i `verifyPartialConsistency` dokazuju proširenje djelomičnog segmenta pomoću novih boundary bajtova i hashova; oba manifesta moraju biti prethodno verificirana i pouzdana. Dokaz može otkriti do 256 receipata; tretirajte ga kao privatne podatke.

Ovo je staging kandidat; stvarni ZIP/AES-256, instalacijski i upgrade E2E, 1M aktivnih zapisa, Windows/NFS, nestanak napajanja i potpuni security/CVE audit još nisu potvrđeni.
