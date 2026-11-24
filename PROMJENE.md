# 2.42.28 — 25.11.2026.

- HMAC hijerarhijski aktivni indeks v3 s redundantnim stranicama, kompatibilnim čitanjem v1/v2, atomskom migracijom i popravkom kopija iz autoritativnog primarnog indeksa.
- Aktivni limit podignut na 100.000; 100.000 sintetičkih zapisa i stvarni restore/commit, lookup i rotacija provjereni pod 32 MiB limita za operacije nakon inicijalnog seeda.
- Merkle dokaz kontinuiteta djelomičnog segmenta; trajni jednokratni nonce store; monotoni checkpoint iz verificiranog segmentiranog arhiva.
- Nema brisanja povijesti ni destruktivnog compactiona. Starije indeksne stranice namjerno se čuvaju. Staging kandidat.

# 2.42.27 — 24.11.2026.

- Ed25519 potpisani arhivski GC plan i cursor, eksplicitno odobrenje inventara, nastavak prekinutog brisanja.
- Merkle inclusion i segment-aligned consistency dokazi.
- Operatorski nonce challenge-response s provjerom svježine i minimalnog checkpointa.
- Aktivni ledger i korisnički podaci ostaju nepromijenjeni.

# 2.42.26 — 24.11.2026.

- Segmentirani NDJSON arhiv s Ed25519 manifestom i Merkle korijenom; kompatibilni checkpoint, nedestruktivna retencija.
- Atomska objava i očuvanje prethodnih manifesta; regresije i SIGKILL testovi.
- Aktivni ledger limit i postojeća JSON baza ostaju nepromijenjeni.

# 2.42.25 — 24.11.2026.

- Segmentirani HMAC UUID cache s do 256 hash-bucketa, spool izgradnja i inkrementalna objava jednog bucketa.
- Neovisni operatorski Ed25519 manifest, monotona provjera prefiksa, provjera arhiva javnim ključem i opcionalnim svježim checkpointom, atomska objava manifesta nakon bloba.
- Novi regresijski i SIGKILL testovi; nema brisanja povijesti ni promjene korisničkih podataka.
- Backup Manager kod i verzija 1.13.10 zadržani; metapodaci kompatibilnosti izdanja osvježeni.

# 2.42.22 — 24.11.2026.

- Opcionalni Ed25519 monotoni checkpoint s neovisnim operatorskim CLI-jem; postojeći nepotpisani checkpoint format ostaje podržan bez novog javnog ključa.
- Rotacija HMAC ključa migrira GC dokaze i cursore bez promjene autentificiranih payloadova, uz provjeru veze s potvrđenim receiptom.
- CLI popis i eksplicitno čišćenje autentificiranih, nereferenciranih, najmanje 24 sata starih shardova. Ne promovira mirror i ne uklanja dokaze potpisane umirovljenim ključem.
- Streaming izračun prefiksa povijesti, brža provjera identičnih indeksa i ograničavanje kopije povijesti za admin stranicu na 50 zapisa.
- Backup Manager 1.13.8; zadržani JSON baza, branding, teme i Extension API 2.0.

# 2.42.21 — 24.11.2026.

- Redundantni HMAC ledger s jedinom autoritativnom točkom commita, operatorsko usklađivanje kopije.
- Opcionalni neovisni checkpoint povijesti, detekcija nestalih artefakata.
- Immutable segmenti od 256 zapisa, dvostruke kopije s autentificiranim referencama, do 20.000 zapisa.
- Kontrolirana CLI rotacija ključa s privremenim key ringom i nastavkom nakon prekida; odbija aktivan restore i GC dokaze.
- Trajni autentificirani GC plan i cursor, nastavak nakon stvarnog prekida te završni zapis nakon uklanjanja korijena.
- Backup Manager prikazuje redundanciju i opciju nastavka GC-a; 1.13.7.
- Bounded native streaming SHA-256 uz postojeće snapshot provjere; stvarni benchmark ledgera i 64 MiB medija.
- Zadržani JSON format CMS-a, Extension API 2.0, teme, lokalni TinyMCE i vendor biblioteke.

# 2.42.20 — 24.11.2026.

- HMAC-SHA-256 journal schema 3 i potpisani Recovery Ledger: ključ 0600 generiran na instalaciji, odvojeni HMAC domenski konteksti, redni broj i veza na prethodni ishod.
- Atomski ledger odlučuje o commitu; journal je nepromjenjivi autentificirani plan. Povijest novih dovršenih transakcija i pregled 50 zapisa po stranici.
- Provjera cijelog premještanog stabla (uključujući prazne mape i medije); legitimni UUID aliasi hashiraju se kao linkovi bez praćenja. Ograničeni postprocessing ne smije promijeniti ostali sadržaj.
- Eksplicitni Verified GC za nepromijenjeni rollback potvrđene obnove starije od 24 sata. Nepotvrđeni i nepoznati sadržaj ostaje sačuvan.
- 10 novih PHP regresija, proširen Linux ENOSPC/SIGKILL test i administratorski POST test.
- Nema izmjene JSON baze, Extension API 2.0, dizajna ili vendor biblioteka.

# 2.42.19 — 24.11.2026.

- Recovery Management u Backup Manageru 1.13.5: posljednji ishod, ograničeni inventar i eksplicitno uklanjanje samo praznih starih direktorija.
- Journal schema 2: SHA-256 datoteka baze prije applya, na commitu i pri recoveryju; kompatibilno čitanje schema 1.
- Lock descriptor otvoren za čitanje i pisanje; recovery mijenja generacijski token, zastarjeli zahtjevi se odbijaju.
- Trajni zapis ishoda prije uklanjanja journala; neuspjela objava ostavlja transakciju za retry.
- Očekivani identitet korijena pri redovnom staging i rollback čišćenju.
- Ograničenje dubine i broja stavki tree digesta, bez promjene formata postojećeg backup hasha.
- Testovi regresije, fault injection atomskog pisanja i restart HTTP procesa.

# 2.42.18 — 24.11.2026.

- Trajni write-ahead journal za restore, plan top-level identiteta i razlikovanje prepared/committed stanja.
- Oporavak prije provjere instalacijskog markera u oba PHP entrypointa; ekskluzivno zaključavanje restorea nasuprot zajedničkom zaključavanju redovnih zahtjeva.
- Backup workspace uključuje se u staging prije zamjene umjesto zasebnog post-commit brisanja.
- Regresije za prekid procesa, nastavak rollbacka, commit, konflikt, traversal i zamijenjene direktorije. Backup Manager 1.13.4.
- Bez promjena JSON sheme, Extension API 2.0 ili vendor biblioteka.

# 2.42.17 — 24.11.2026.

- Rekurzivno brisanje s dvostupanjskim planom identiteta stabla i odbijanjem posebnih datoteka.
- Ekskluzivno privatno staging stvaranje, praćenje roditelja pri atomskom zapisu.
- Objedinjena obična i AES-256 ZIP kompresija, privatna libzip radna mapa i provjera izvornog stabla.
- Restore rollback premješta samo evidentirane stavke; upozorenja i zadržavanje podataka kod konflikta.
- Šest novih regresijskih testova, tokenizirani inventar statičkih poziva, proširen ZIP runtime runner.
- Backup Manager 1.13.3; bez promjene JSON sheme, vendor bajtova ili Extension API 2.0. ZIP runtime i produkcijska verifikacija otvoreni.

# 2.42.16 — 24.11.2026.

- Praćenje device/inode identiteta izvornog i odredišnog direktorija tijekom rekurzivnog kopiranja; provjera lanca direktorija prije operacija i nakon pisanja.
- Zajednički rollback kopiranja i ZIP ekstrakcije uklanja samo zapise u provjerenim lancima direktorija; odbija zamijenjeni direktorij i ne briše prepostojeće direktorije.
- ZIP relativna putanja izračunava se uklanjanjem početnog prefiksa, ne globalnom zamjenom podniza.
- Pet novih PHP regresijskih testova, zasebni ZIP runtime runner, prošireni statički scanneri i lokalni CycloneDX SBOM s označenim nepotpunim podrijetlom.
- Bez promjene formata JSON pohrane, vendor bajtova, Extension API 2.0 ili vizualnog dizajna.

# 2.42.15 — 24.11.2026.

- Zajednička normalizacija završnih separatora prije provjere simboličkih poveznica u mkdir, rmdir, mv, copyRecursive, deleteRecursive, directoryExists, getSize i ZIP ekstrakciji. Terminalni . i .. se odbijaju.
- getSize odbija posebne datoteke i broji preskočene simboličke poveznice u limitu.
- SEO slika: jednom validira naslovnu sliku, bez učitavanja fallbacka kada već postoji valjani cover. Search unaprijed izračunava težine i odluku o fallbacku.
- Tri nova regresijska testa, postojeći SEO test proširen.

# 2.42.14 — 24.11.2026.

- Lokalni PrismJS 1.30.0 u TinyMCE Code Sample pluginu; testirano svih deset postojećih jezika, zadržani stilovi.
- Zakrpa rekurzivnog brisanja simboličkih poveznica i dodatne provjere identiteta.
- Optimizacija search scoringa i 404/preview SEO metapodataka.
- Prošireni testovi i ažurirani inventar ovisnosti.

# 2.42.13 — 24.11.2026.

- Sigurniji filesystem lock, move/copy rollback i ZIP ekstrakcija; ZIP runtime provjera ostaje otvorena.
- Objedinjene provjere redaka i paginacija taksonomija; dodan tokenizirani clone audit.
- Optimiziran search cache, lokalizirani 404 naslov i ponovna uporaba robots direktiva.
- Dodani višezadaćni i regresijski testovi; dokumentiran otvoreni PrismJS nalaz.

# 2.42.12 — 24.11.2026.

- Fizički integriran jQuery 4.1.0 iz dostavljenog koda.
- Lokalna TinyMCE 8.10.2 izvedba koristi DOMPurify 3.5.0 iz dostavljenog paketa; zamijenjen interni factory, ne samo globalna biblioteka.
- Lokalni editor je zadani; Tiny Cloud zahtijeva izričit opt-in poslužitelja dok se udaljena verzija ne verificira. Ključ ostaje sačuvan.
- Dodani testovi stvarnog sanitizacijskog factoryja i lokalnog integriteta.

# 2.42.11 — 24.11.2026.

- Modernizirani jQuery obrasci u administraciji i installeru.
- Sigurnije dozvole kopija i provjere identiteta tijekom čišćenja.
- Jednokratni izračun SEO opisa i paginirani društveni/schema naslovi.
- Semantički audit kandidata i ručni CVE nalazi, uključujući otvoreni DOMPurify nalaz.
- Dodan staging public smoke runner; potpuni E2E i CVE audit ostaju otvoreni.

# 2.42.10 — 24.11.2026.

- Snapshot čitanja i kopiranja uspoređuje početni i završni inode, veličinu, mtime, ctime i broj poveznica. Kopiranje dodatno provjerava odredište prije chmod.
- Debug zapis izostavlja cijeli URL query i fragment te neutralizira kontrolne znakove.
- Search ne koristi poznato zastarjeli cache kada obnova ne uspije.
- API odbija nevaljane UTF-8 podatke, NUL u vrijednostima i kontrolne znakove u ključevima.
- SEO fallback opis zadržava vidljiv, HTML-escapiran kod; XML HTTP ETag temelji se na SHA-256 sadržaja, a freshness provjera čisti stat cache.
- Pet novih PHP i osam izoliranih HTTP XML provjera; vendor verzije ostaju iste.

# 2.42.9 — 24.11.2026.

- Mrežni sloj odbija IPv6 translacijske prefikse i dvosmislene numeričke hostove; javni IP literali ne prolaze DNS override.
- JSON/atomski zapis i mrežni download provjeravaju prazan staging descriptor, broj hard linkova i identitet prije objave; provjera identiteta i nakon rename.
- Jednaki strukturirani podaci i Open Graph tip na početnoj stranici i njezinu canonical aliasu; ograničena duljina URL-a u sitemapu.
- Četiri nova PHP regresijska testa i izolirani HTTP preflight testovi.
- Vanjske biblioteke zadržane; jQuery 4.1.0 i CVE provjera još blokirani mrežom.

# 2.42.8 — 24.11.2026.

- Nativni odabir datuma/vremena umjesto stare jQuery DateTimePicker komponente; normalizacija datuma na serveru i starih nacrta.
- Dodatna provjera identiteta i broja hard linkova privremene datoteke prije objave.
- SEO ItemList bez privatnih/noindex/dupliciranih stavki; ispravljena poveznica WebPage, BlogPosting i BreadcrumbList.
- Tokenizirani audit rizičnih PHP poziva i poboljšana provjera manifestnih kontrolnih suma.
- jQuery 4.1.0 nije preuzet, potpuni CVE audit i instalacijski E2E ostaju blokirani.

# 2.42.7 — 24.11.2026.

- Sigurnije kodiranje PHP-guarded JSON pohrane, instalacije i baselinea.
- Odbijanje hardlinkova pri zaključavanju datoteka.
- SEO validacija lokalnih slika i ispravna naslovna slika konfigurirane početne stranice.
- Četiri nova regresijska testa i čišćenje dangling symlink testnih podataka.
- Ovisnosti zadržane zbog mrežnog ograničenja i kompatibilnosti.

# 2.42.6 — 24.11.2026.

- Bootstrap Icons 1.13.2: fizički zamijenjeni CSS, JSON i oba fonta; alias za uklonjenu ikonu.
- Scanner: ograničeno pretraživanje svih stavki, odbijanje posebnih datoteka i višestrukih izvršnih nastavaka.
- Integritet jezgre: ograničenje broja stavki, obvezni index.php pri stvaranju baselinea.
- HTTP zaštita za php.ini i web.config.
- jQuery 3.7.1 zadržan radi nedostupnosti novije distribucije i kompatibilnosti.

# 2.42.5 — 24.11.2026.

- Tiny Cloud 8.10.2 uz privatnu konfiguraciju, lokalnu alternativu i ograničenje vanjskih izvora na autentificirane stranice za uređivanje.
- Fizički zamijenjeni Select2 4.2.0 JS/CSS i dodan jQuery UI 1.14.2 za redoslijed dodataka. Tipke za pomicanje omogućuju korištenje bez povlačenja. Stari Sortable helper ostaje radi kompatibilnosti vanjskih dodataka.
- Parsedown 1.8.0 bajtovno identičan prethodnoj verziji; uključena dostavljena licenca. Bootstrap 5.3.9 i TinyMCE 8.10.2 potvrđeni u ponovljenim arhivama.
- jQuery 4.1.0 nije preuzet; zadržan jQuery 3.7.1. Nema migracije baze.

# 2.42.4 — 24.11.2026.

- Fizički nadograđeni Bootstrap 5.3.9, TinyMCE 8.10.2 i povezani isporučeni runtime resursi.
- Zadržani hrvatski prijevod, 13 aktivnih TinyMCE dodataka i postojeći sigurnosni filtri; media plugin i primjeri nisu uključeni.
- Lokalni nacrti: očuvanje višestrukih odabira i isključenih checkboxova, zaštita identiteta i verzijskog markera, odbijanje automatskog vraćanja zastarjelog nacrta.
- Objavljivanje koristi aktualni data-switch atribut i nativni submit događaj; tekstualni fallback inicijalizira nacrt kada editor nije dostupan.
- Dodani vendor manifest i regresijske provjere. JSON pohrana, SEO sustav i Extension API 2.0 ostaju kompatibilni.

# 2.42.3 — 24.11.2026.

- Fizička migracija Bootstrap 4.6.2 na lokalno dostupni 5.3.6; prilagodba markup/JS/CSS, novi Select2 skin i nativni file inputi.
- Odvojene postavke aktivnih kartica bez dinamičke konstrukcije CSS selektora.
- Kandidat za staging; mrežno preuzimanje novijih paketa i kompletni E2E nisu potvrđeni.

# 2.42.2 — 24.11.2026.

- Dodatna sigurnost: izravno HTTP izvršavanje ograničeno na index.php i install.php; runtime scanner odbija konfiguracijske i složene izvršne datoteke; stroža bootstrap zaštita i provjera integriteta root skripti.
- Inventar lokalnih biblioteka i SHA-256 manifest; novije biblioteke identificirane, ali nisu preuzete zbog mrežnog ograničenja.
- Kraći cache statičkih resursa radi bržeg preuzimanja budućih sigurnosnih zakrpa.

- Pretraživanje: vidljivi HTML tekst, ispravni razmaci, sigurnije isticanje, jedinstveni ID-evi polja, obnova cachea nakon nadogradnje, ispravni SEO naslovi i ograničenja postavki.
- Sigurnost: odbijanje neispravnih kodiranih putanja prije bootstrapa, zaštita od višeznamenkastih PHP nastavaka i usklađena Apache/installer pravila.
- Navigacija: provjera javne dostupnosti predaka, ograničeno iteriranje i aria-current za početnu stranicu.
- Komentari: sigurno povezivanje običnih HTTP(S) URL-ova i čuvanje HTML entiteta.
- Galerija: odbijanje opasnih naziva, izostavljanje nestalih ili simbolički povezanih datoteka.
- Sitemap: neispravan objekt taksonomije više ne prekida obradu ostalih arhiva. SEO i search dijele pomoćnu funkciju za tekst.
- API, kategorije i backup: uklanjanje suvišnih referenci i dodjela, bez promjene javnih ugovora.
- Dodano devet regresijskih testova i obavljene zasebne lokalne HTTP provjere. JSON format i Extension API 2.0 ostaju nepromijenjeni.

# 2.42.1 — 24.11.2026.

- Usklađeni canonical URL početne stranice, njenog aliasa i XML sitemapa; podrška za Unicode slugove u canonical URL-u.
- Robots direktive primijenjene i na konfiguriranu početnu stranicu te HTTP odgovore; strukturirani podaci se ne emitiraju za noindex stranice.
- Optimizirani SEO opisi, izbor social slike i JSON-LD; ispravljen broj stranica za pojedinačan sadržaj.
- Dodan lokalni SEO pregled u postavkama; uklonjeni dvostruki ključevi polja i ponavljajući izračuni.
- Ojačani API ulazi, mrežni zahtjevi, privatni direktoriji, preflight i DOM obrada. Dokumentacija i regresijske provjere uključene su u paket.
- Format postojećih podataka i Extension API 2.0 ostaju nepromijenjeni.

## 2.42.23 — 2026-11-24

- Segmentni reader s provjerom cjelokupne povijesti, jedinstvenosti ID-a i vanjskog checkpointa; zadržava samo traženu stranicu i odabrane receipte. Kompatibilni `read()` zadržava cijelu povijest za mutacije.
- CLI lookup po rednom broju ili UUID-u, status s brojem retirement certifikata. Pretraga još uvijek sekvencijalno provjerava sve segmente; ne postoji trajni direktni UUID indeks.
- Autentificirani `restore-ledger-retirement.json` potvrđuje hash segmenata validiranih prethodnim ključem, migrira se prije umirovljenja ključa i omogućuje siguran GC nereferenciranih segmenata. Neprovenljivi segmenti ostaju netaknuti. Dokazi se ne brišu automatski.
- Operatorski `advance-stdin` omogućuje raspoređenu dostavu prijedloga, bez uvođenja udaljenog servisa i bez privatnog potpisnog ključa u CMS-u.

## 2.42.24 — 2026-11-24

- Append-only segmentne restore mutacije s provjerom očekivanog aktivnog plana i zaštitom od replaya; immutable segmenti ostaju nepromijenjeni.
- Opcionalni HMAC UUID indeks vezan za hash primarnog ledgera, eksplicitni `lookup-indexed`, siguran fallback i obnova nakon rotacije.
- Segmentna rotacija HMAC ključa i ograničeno dohvaćanje GC receiptova; streaming izvoz povijesti za neovisnog operatora i arhiviranje bez brisanja.
- Regresijski testovi prelaska granice segmenta, manipuliranja cacheom, izvoza i prekida objave. Backup Manager 1.13.10, Extension API 2.0.
