# Nadogradnja na 2.42.26

1. Napravite sigurnosnu kopiju svih datoteka i privatnog spremišta `sadrzaj` (ili starijeg `brendy-content`). Provjerite povrat kopije na zasebnom okruženju.
2. Isprobajte novu verziju na staging kopiji s odgovarajućim PHP ekstenzijama. Nemojte pokretati novu instalaciju nad postojećom stranicom.
3. Zamijenite jezgru, `index.php`, `install.php`, isporučene teme i dodatke. Vlastite izmjene tema/dodataka najprije usporedite i spojite. Zadržite postojeće podatke i njihove dozvole. Spojite sigurnosna pravila iz novog `.htaccess` u postojeću konfiguraciju, osobito ako je izmijenjena. Ako koristite Nginx ili drugi poslužitelj, primijenite ekvivalentne zabrane. Nakon provjerene nadogradnje obnovite baseline integriteta kroz System Health prema uputama sučelja.
4. Provjerite prijavu, 2FA, uloge, objavu sadržaja, upload, sve aktivne dodatke, `robots.txt` i `sitemap.xml`. Otvorite System Health. Ako se koristi dodatak Sitemap, spremite njegove postavke za prisilnu regeneraciju XML-a.
5. Ako provjera ne uspije, vratite prethodni kod i odgovarajuću kopiju sadržaja. Ovo izdanje ne uvodi promjenu formata JSON baze ni SQL migraciju.

6. Uklonite zastarjeli `jezgra/css/select2-bootstrap4.min.css` nakon zamjene jezgre. Vlastite teme i dodatke migrirajte na Bootstrap 5 `data-bs-*` i nove utility klase. Dodatni kompatibilni CSS nije potpuna emulacija Bootstrap 4.

7. Zamijenite cijeli direktorij `jezgra/urednik/tinymce/` iz novog paketa, ne miješajte stare i nove vendor datoteke. Hrvatski prijevod je već uključen. Ne uključujte dodatne neprovjerene TinyMCE plugine. Očistite CDN/cache ako se koristi.
8. Provjerite uređivanje hrvatskog sadržaja, slike, objavu/nacrt, revizije, lokalne nacrte i SEO polja. Ako je nacrt iz starije verzije sadržaja, editor neće automatski vratiti njegov sadržaj; ručno sačuvajte potrebni nacrt prije uređivanja.

9. Tiny Cloud: kopirajte priloženu skrivenu datoteku `sadrzaj/.tinymce-cloud.json` u aktivni privatni direktorij (`sadrzaj` ili `brendy-content`) ako želite sačuvati postojeći ključ. Cloud se ne uključuje automatski. Ako već imate konfiguraciju, sačuvajte je. Ne zamjenjujte cijeli sadržajni direktorij. Na Tiny Cloud računu odobrite stvarnu domenu (i staging domenu), provjerite aktivan plan i mogućnost uređivanja/spremanja. Ne objavljujte ovu personaliziranu arhivu javno. API ključ je namijenjen pregledničkom CDN zahtjevu i zato je vidljiv autentificiranom uredniku; ne može se smatrati serverskom tajnom.
10. Lokalni način: u privatnoj konfiguraciji postavite `mode` na `local`, ili postavite `BRENDY_TINYMCE_MODE=local` na poslužitelju. Za Cloud se može postaviti `BRENDY_TINYMCE_MODE=cloud` i `BRENDY_TINYMCE_API_KEY` u okruženju umjesto datoteke. Poveznica **Koristi lokalni urednik** na stranici uređivanja ponovno otvara stranicu s `brendy_editor=local`; prije prelaska spremite promjene ili lokalni nacrt. Ako CDN skripta ili init odbije izvršavanje, integracija pokušava lokalnu skriptu. Odbijanje domene/računa može rezultirati read-only editorom i tada ručno odaberite lokalni način. Lokalni TinyMCE koristi postojeću GPL konfiguraciju; provjerite uvjete licence za svoju distribuciju.
11. Provjerite redoslijed dodataka povlačenjem i tipkama, spremanje, Select2 polja, upload i kalendar. Stari jQuery Sortable helper ostaje za kompatibilnost proširenja. Ugrađen je jQuery 4.1.0; vlastiti dodaci moraju se dodatno testirati na toj verziji.

12. Bootstrap Icons: zamijenite cijeli `jezgra/css/bootstrap-icons/` direktorij zajedno s CSS-om, oba fonta i kompatibilnim aliasom. Očistite cache. Ako koristite vlastite dodatke, provjerite njihove ikone i scanner; nedopuštene skrivene mape, posebne datoteke i složeni izvršni nastavci sada se odbijaju.

13. JSON-guarded baze sada sigurno kodiraju PHP oznake pri spremanju. Ne prepisujte ručno postojeće baze; promjena se primjenjuje prilikom sljedećeg spremanja. Ako datoteka zaključavanja ima više hard linkova ili PHP ne može postaviti potrebne dozvole, operacija neće uspjeti. Provjerite vlasništvo i dozvole na stagingu; ne uklanjajte aktivne lock datoteke.

14. Uklonite zastarjele `jezgra/js/jquery.datetimepicker.full.min.js` i `jezgra/css/jquery.datetimepicker.min.css` nakon što provjerite koriste li ih vlastiti dodaci. Ugrađeni editor ih više ne koristi. Novi nativni datum poštuje vremensku zonu CMS-a i čuva sekunde; sučelje kalendara ovisi o pregledniku i OS-u. Neispravan datum odbija se u admin kontrolerima. Lokalni nacrti sa starim formatom se normaliziraju.
15. Na stagingu pokrenite `php tests/source-audit.php` i `python3 tests/dependency-audit.py --verify-registry`; izlaz 1 nije prolaz sigurnosnog audita.

16. Mrežni zahtjevi namjerno odbijaju lokalne, translacijske i nekanonske numeričke hostove; koristite javno DNS ime s HTTPS-om. Na stagingu provjerite mrežne integracije s pravim cURL modulom. Privremene datoteke moraju biti obične, prazne i s jednim hard linkom prije pisanja. Ograničite pristup nadređenim direktorijima na vlasnika CMS-a. Pokrenite `python3 tests/runtime-preflight.py` za izolirane preflight provjere.

17. Provjerite Search nakon izmjene sadržaja. Ako obnova poznato zastarjelog indeksa ne uspije, pretraga ne vraća rezultate iz stare kopije. Provjerite dozvole workspace direktorija. Debug log više ne uključuje query parametre; pregledajte i zasebne access logove poslužitelja prema vlastitoj politici privatnosti. XML ETag se mijenja na hash sadržaja; klijenti s prethodnim ETagom preuzet će novi XML. Izvršite `python3 tests/http-xml-snapshot.py`.


## 2.42.12: sigurnosni odabir editora

Lokalni TinyMCE je prilagođena izvedba 8.10.2 s DOMPurifyjem 3.5.0. Udaljeni Tiny Cloud bundle nije verificiran; samo izričita poslužiteljska postavka `BRENDY_TINYMCE_MODE=cloud` dopušta Cloud. Ključ je sačuvan. Ne uključujte Cloud prije potvrde zakrpanog udaljenog sanitizatora. Pri nadogradnji TinyMCE-a koristite službeni zakrpani paket i ponovite testove; lokalna izmjena može se prepisati.

## 2.42.17 — provjera kopiranja i arhiva

Na stagingu s ext-zip pokrenite `php tests/zip-runtime-smoke.php`. Izlaz 2 označava preskočen test, ne prolaz. Provjerite backup i restore stvarne kopije sadržaja, uključujući dozvole i vlasništvo. Ako se direktorij zamijeni tijekom operacije, kopiranje/ekstrakcija može odbiti čišćenje nesigurne putanje; pregledajte zaostale datoteke ručno nakon zaustavljanja paralelnih operacija. U slučaju regresije vratite prethodni kod i provjerenu kopiju privatnog sadržaja.


## 2.42.17 — obavezne staging provjere

Izvršite `php tests/run.php`, `php tests/concurrency-smoke.php` i `php tests/zip-runtime-smoke.php` na punom PHP runtimeu. Testirajte običan i AES backup, krivu lozinku, povrat podataka, prekid restorea te provjerite dozvole i dovoljno slobodnog prostora za dodatnu ZIP kopiju. Kod nepotpunog rollbacka sačuvajte `sadrzaj/tmp/backup-restore-rollback-*` i `backup-manager-preserve-*`, onemogućite upise i oporavite iz neovisne kopije prema zapisniku; ne brišite podatke automatski. Ovo je staging kandidat.

## 2.42.18 — restore journal i oporavak

Prije nadogradnje napravite neovisnu kopiju cijelog CMS-a i sadržaja te zaustavite promet i CLI procese koji pišu u sadržaj. Nadogradite sve datoteke jezgre i Backup Manager zajedno. Nema migracije JSON sheme. Testirajte na stagingu s PHP zip/mbstring/gd/dom/curl.

Nakon prekinutog restorea prvi PHP zahtjev pod ekskluzivnim zaključavanjem pokušava vratiti prethodni sadržaj (prepared) ili potvrditi već dovršeni restore (committed). Ako identiteti ne odgovaraju ili zapis nije čitljiv, HTTP 503 je namjerna zaštita. Nemojte brisati `tmp/restore-transaction.json`, `tmp/restore-request.lock` niti pripadajuće staging direktorije tijekom oporavka. Zaustavite promet, napravite potpunu kopiju sadržaja i tmp direktorija te ručno analizirajte journal i oba stabla. Nakon automatskog oporavka pregledajte i sigurno uklonite preostale kopije tek nakon neovisne provjere. Ne vraćajte stariju jezgru dok postoji aktivan journal.

Direktorij tmp mora biti trajno lokalno spremište, ne automatski brisana cache mapa; onemogućite izravan web pristup. Restore zahtijeva filesystem sa stabilnim inode identitetima i atomskim rename na istom uređaju. Ne provoditi nadogradnju na aktivnom restoreu.

## 2.42.19 — Recovery Management

Prije zamjene koda dovršite svaki postojeći restore. Za aktivni ili blokirani journal nemojte raditi downgrade, brisati `restore-transaction.json` niti premještati pojedinačne stavke bez forenzičke kopije cijelog privatnog sadržaja i logova. Recovery se pokreće pri sljedećem PHP zahtjevu; tijekom aktivnog ili blokiranog oporavka i admin vraća 503. Pri blokadi zaustavite promet i zatražite ručni oporavak od održavatelja; ne postoji nezaštićeni web bypass.

Backup Manager prikazuje posljednji ishod i najviše 100 prepoznatih direktorija (najviše 10.000 pregledanih stavki). Čišćenje traži svježu administratorsku autentikaciju, postojeći CSRF guard, ekskluzivni gate i potvrdu. Dopušteni su samo prazni direktoriji stariji od 24 sata (ili staging s praznim `sadrzaj` djetetom). Sadržaj i nepoznate stavke nisu za automatsko uklanjanje. U slučaju popunjenog diska oslobodite nepovezan prostor izvan recovery stabla i ponovite recovery; ne brišite journal ni njegove direktorije. Zapis `restore-last-result.json` čuva samo posljednji ishod, nije revizijski ledger. Prije povratka na stariji kod dovršite journal i napravite novu kopiju sadržaja.

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

## Nadogradnja na 2.42.23

Dovršite ili riješite sve aktivne restore i rotacije na prethodnom izdanju, zaustavite promet i napravite neovisnu kopiju svih datoteka i privatnog sadržaja uključujući tmp. Nadogradite jezgru i Backup Manager zajedno; čita se stari format bez migracije unaprijed. Novi retirement ledger nastaje pri rotaciji ključa. Zadržite ga uz ključ, primarni indeks, mirror i segmente. Stari segmenti bez provjerljivog potpisa ili certifikata ostaju netaknuti. Nemojte vraćati pojedinačne recovery datoteke iz različitih generacija. Prije čišćenja koristite `orphan-list`, provjerite neovisnu kopiju, pa tek onda `orphan-prune` uz ekskluzivni gate i zaustavljen promet. U slučaju greške sačuvajte sve dokaze i ne promovirajte mirror. Upute za operatorsku stdin automatizaciju su u README-u. Ovaj paket nije potvrđen za produkciju; obavezno provedite ZIP/AES i instalacijski/upgrade E2E na stagingu s potrebnim PHP ekstenzijama.

## 2.42.24 — segmentne mutacije i indeks

Završite aktivan restore i rotaciju na prethodnoj verziji; zaustavite promet i izradite neovisnu sigurnosnu kopiju cijelog CMS-a i privatnog sadržaja uključujući tmp. Nadogradite jezgru i Backup Manager zajedno. Nema obvezne migracije formata: postojeće HMAC domene, primarni indeks i segmenti ostaju kompatibilni. Novi `restore-ledger-lookup.json` opcionalan je izvedeni cache, vezan uz SHA-256 primarnog ledgera; nemojte ga tretirati kao autoritet. Izradite ga CLI naredbom `index-rebuild` po želji. Standardna `lookup` naredba, recovery i GC nisu oslabljeni. `lookup-indexed` je brza dijagnostika koja ne provjerava nepovezane segmente; kod konfiguriranog checkpointa uvijek radi puni pregled. Za arhiviranje koristite `archive-export` uz privatni privremeni izlaz i provjeru exit statusa, potom neovisno potpisivanje checkpointa i backup. Povijest se ne briše niti automatski skraćuje. Ako dođe do prekida rotacije, ponovite `rotate` pod ekskluzivnim gateom; ne uklanjajte key ring, primarni ledger, mirror ili GC dokaze. Izvršite staging ZIP/AES-256 i E2E provjere prije produkcije.

## 2.42.25 — segmentirani UUID cache i neovisni arhiv

- Prije nadogradnje napravite neovisnu sigurnosnu kopiju cijele instalacije i zaustavite promet. Ne prepisujte postojeći `sadrzaj/`, recovery ključ, journal ili ledger. Zamijenite distribucijski kod prema redovnim uputama. Ne vraćajte stari recovery ledger zasebno od podataka.
- Stari UUID cache više se ne koristi za brzu pretragu; puni lookup ostaje dostupan. Na zaustavljenoj instalaciji pokrenite `php tests/recovery-ledger-admin.php index-rebuild /absolute/content/root`. Novi cache je opcionalan; kvar cachea ne poništava commit. Potrebni su slobodan privremeni prostor i do 256 privremenih deskriptora; ako izgradnja ne uspije, popravite uvjete i ponovite. Nema migracije korisničke baze.
- Izvezite povijest s `php tests/recovery-ledger-admin.php archive-export /absolute/content/root > proposal.json`. Provjerite exit status; ne koristite djelomičan izvoz. Prenesite ga autentificiranim kanalom neovisnom operatoru. Arhiv nije puna sigurnosna kopija CMS-a.
- Operator na zasebnom računalu koristi prazan privatni apsolutni direktorij (0700), Ed25519 secret hex datoteku (0600, izvan arhiva i webroota) i postojeći anchor ključ. `php tests/recovery-archive-operator.php initialize /absolute/private/archive /absolute/secret.hex /absolute/proposal.json`; za sljedeće objave koristite `advance` (podržan i stdin `-`). Provjerite `php tests/recovery-archive-operator.php verify /absolute/private/archive PUBLIC_KEY_HEX /absolute/latest-independent-checkpoint.json`. Bez checkpointa provjera potvrđuje autentičnost, ali ne svježinu. Privatni ključ ne smije doći u CMS.
- Ne brišite stare blobove; retencija nije automatizirana. Ako je proces prekinut nakon objave bloba, ponovite istu operaciju; manifest je jedina točka objave. Ako nedostaje ili je oštećen manifest, zaustavite operacije i provjerite neovisni najnoviji checkpoint i kopiju. Inicijalizacija nije alat za reset postojeće povijesti. Zaštita od vraćanja cijelog operatorskog spremišta zahtijeva nezavisan aktualan checkpoint.
- Granice: 20.000 receipata, 16 MiB po arhivskom izvozu; verifikacija arhiva još dekodira cijeli JSON. Nema automatske destruktivne retencije. Za rollback koda čuvajte kompatibilnu kopiju i ponovno izgradite izvedeni indeks nakon povratka; ne vraćajte stare podatke samo zbog promjene cachea.

## Segmentirani arhiv 2.42.26 (opcionalno)

Na zaustavljenoj instalaciji izvezite `php tests/recovery-ledger-admin.php archive-export-v2 /absolute/content/root > proposal.ndjson`. Prenesite ga sigurnim kanalom u neovisno operatorsko okruženje. U zasebnom privatnom direktoriju (0700), izvan webroota, koristite postojeći neovisni Ed25519 ključ (hex datoteka 0600):

```sh
php tests/recovery-segmented-archive-operator.php initialize /private/archive /private/secret.hex proposal.ndjson /private/checkpoint.json
php tests/recovery-segmented-archive-operator.php advance /private/archive /private/secret.hex proposal.ndjson /private/checkpoint.json
php tests/recovery-segmented-archive-operator.php verify /private/archive PUBLIC_KEY_HEX /private/checkpoint.json
php tests/recovery-segmented-archive-operator.php retention-plan /private/archive PUBLIC_KEY_HEX /private/checkpoint.json
```

Checkpoint mora biti neovisno pohranjen i aktualan; retention-plan traži točan aktualni checkpoint. Održavajte odvojeni backup arhiva i ključeva. Ne uklanjajte prethodne manifeste ili segmente; retention-plan je samo izvještaj. Stari format v1 ostaje podržan u zasebnom direktoriju. Nema automatskog brisanja aktivne povijesti ni migracije JSON baze. Ako inicijalizacija ostavi segmente, za ponovni pokušaj potreban je neovisni checkpoint; bez njega zadržite sve datoteke i istražite prekid.

## 2.42.27 — provjereno čišćenje arhiva i svježina checkpointa

Prije čišćenja izradite neovisnu sigurnosnu kopiju arhiva i provjerite je. Na zasebnom operatorskom računalu, u privatnom direktoriju 0700, pokrenite `retention-plan` kao prije. Pregledajte popis, identitete i polje `approval` (SHA-256 točnog inventara). Za početak eksplicitno upotrijebite dobiveni digest:

```sh
php tests/recovery-segmented-archive-operator.php gc-start /private/archive /private/secret.hex /private/checkpoint.json APPROVAL_DIGEST
php tests/recovery-segmented-archive-operator.php gc-resume /private/archive /private/secret.hex /private/checkpoint.json
```

Jedna operacija obrađuje do 100 datoteka; plan do 1.000. Ponovite `gc-resume` dok su cursor i broj stavki u `archive-gc-journal.json` jednaki. Dovršeni potpisani journal čuva se kao receipt pri sljedećem planu. Ne uređujte journal ručno. Objavljivanje arhiva blokirano je dok plan nije dovršen. Ako je sadržaj, identitet, checkpoint ili potpis nevaljan, zaustavite čišćenje i istražite; nemojte uklanjati journal. Svi segmenti iz aktualnog i svih potpisanih povijesnih manifesta zaštićeni su. Nema brisanja manifesta niti aktivne recovery povijesti. Ovaj postupak ne zamjenjuje neovisni backup.

Za provjeru svježine operatorskog odgovora generirajte novu jednokratnu vrijednost `php tests/recovery-anchor-freshness.php challenge`. Na neovisnom operatoru pokrenite `php tests/recovery-anchor-freshness.php respond /private/checkpoint.json /private/secret.hex NONCE`. Sigurnim kanalom prenesite odgovor; na strani verifikatora pokrenite `php tests/recovery-anchor-freshness.php verify PUBLIC_KEY NONCE INSTALLATION_ID MINIMUM_SEQUENCE [EXACT_PREFIX] < response.json`. Uporabite nonce samo jednom, odgovor vrijedi 60 sekundi uz 5 sekundi dopuštenog pomaka sata. Očekivani prefix (ako naveden) zahtijeva točan redni broj. Svjež odgovor ne znači da je sama povijest svježa: minimum i prefix moraju biti neovisno poznati. CLI nije mrežni servis niti se automatski pokreće iz CMS-a.

Merkle metode `inclusionProof/verifyInclusion` dokazuju uključivanje segmenta u pouzdani potpisani korijen. `consistencyProof/verifyConsistency` dokazuju dodavanje segmenata nakon potpunog starog segmenta; za djelomični zadnji segment koristite potpunu provjeru prefiksa zapisa. Korijene i identitet instalacije pribavite iz verificiranih potpisanih manifesta, ne iz dokaza koji se provjerava.


## 2.42.28 — hijerarhijski aktivni ledger i neovisna provjera

Prije nadogradnje dovršite restore, zaustavite promet i izradite neovisnu kopiju cijelog privatnog sadržaja (uključujući `tmp`, ključeve, GC dokaze, ledger i sve kopije). Nadogradite jezgru i Backup Manager zajedno, bez prepisivanja korisničkog sadržaja. Stari v1/v2 formati i dalje se čitaju. V3 automatski nastaje pri sljedećem zapisu s najmanje 16 punih shardova; operator može eksplicitno migrirati povijest od najmanje 256 zapisa: `php tests/recovery-ledger-admin.php index-migrate /absolute/content/root`. Obje kopije indeksnih stranica (do 64 sharda) objavljuju se prije mirror i primary indeksa. Primary je jedina točka commita. Migracija ne mijenja stare receiptove, GC planove i cursore. Ne vraćajte stari kod nad već migriranim podacima; povratak zahtijeva kompatibilnu cjelovitu kopiju i provjeru neovisnog checkpointa. Ako primary nije valjan, nema promocije mirrora. `reconcile` popravlja kopije iz verificiranog primaryja. Stare stranice namjerno nisu predmet GC-a.

Novi limit je 100.000 aktivnih transakcija; prethodne granice navedene u povijesnim odjeljcima odnose se na stara izdanja. Nije neograničen. Potpuni query još ima O(N) memorije za jedinstvenost ID-a i obilazi sve shardove. Stariji `read()` i monolitni `save()` i dalje rekonstruiraju povijest; streaming CLI `checkpoint` i `archive-export-v2` su preporučeni. Na 100.000 zapisa legacy seed zahtijevao je 124 MB vršne memorije, dok su testirane operacije nakon seeda prošle pod 32 MiB. Stara ograničenja GC skeniranja, certifikata i veličine pojedinačnih datoteka ostaju. Nema compactiona niti automatskog brisanja starih stranica. Testiranje milijun aktivnih transakcija nije izvršeno.

Neovisni operator može objaviti checkpoint iz provjerenog segmentiranog arhiva: `php tests/recovery-segmented-archive-operator.php checkpoint-initialize /private/archive /private/secret.hex /private/checkpoint-dir/anchor.json` ili `checkpoint-advance` nakon početnog postavljanja. Direktoriji moraju biti privatni, potpisni ključ 0600 i izvan CMS kontrole. Advance odbija nestali checkpoint, regresiju i divergentni prefiks. Dovršeni checkpoint prenosi se putem neovisno zaštićenog kanala do konfiguriranog CMS sidra; nema automatskog mrežnog servisa. Za jednokratnu provjeru operator koristi `php tests/recovery-anchor-freshness.php issue /private/nonce-dir PUBLIC_KEY_HEX /private/expected-signed-checkpoint.json`, prenosi nonce neovisnom signer-u (`respond`), te prosljeđuje odgovor `consume /private/nonce-dir NONCE < response.json`. Nonce direktorij mora biti 0700, zasebno zaštićen od CMS-a i rollbacka; zapisi 0600. Potrošnja se zapisuje prije uspjeha, nevaljani ili istekli odgovori se odbijaju. Provjera je vezana uz točno očekivano potpisano stanje, ne dokazuje aktualnost ako očekivani checkpoint nije osvježen. Nema automatske retencije nonce zapisa. `partialConsistencyProof` i `verifyPartialConsistency` dokazuju proširenje djelomičnog segmenta pomoću novih boundary bajtova i hashova; oba manifesta moraju biti prethodno verificirana i pouzdana. Dokaz može otkriti do 256 receipata; tretirajte ga kao privatne podatke.

Ovo je staging kandidat; stvarni ZIP/AES-256, instalacijski i upgrade E2E, 1M aktivnih zapisa, Windows/NFS, nestanak napajanja i potpuni security/CVE audit još nisu potvrđeni.
