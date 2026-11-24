# Brendy CMS 2.42.28 — razvojni i sigurnosni audit

Status: staging kandidat, polazište 2.42.27. Linux, PHP 8.4.24 CLI; cilj PHP 8.2+, JSON baza i Extension API 2.0. Nema promjena korisničkog sadržaja, dizajna, koda tema/dodataka ili vendor biblioteka. Metapodaci kompatibilnosti svih 14 proširenja ažurirani.

## Izvedene izmjene

- Aktivni ledger schema 3: HMAC-SHA-256 root, sadržajno adresirane HMAC indeksne stranice s do 64 sharda po stranici i dvije neovisne datoteke po stranici (iste fizičke pohrane). Zadržani v1/v2 read i prethodni shardovi. Pri 16 punih shardova redovni writer prelazi na v3. CLI index-migrate dopušta eksplicitnu migraciju najmanje 256 receipata uz ekskluzivni gate i bez aktivnog restorea. Objavljivanje obje kopije stranice prije mirror i primary rootova; primary jedini commit. Preview ne zapisuje stranice. Migracija ne mijenja receiptove, UUID, sekvence, GC planove ili cursore. Stare stranice se ne brišu. Orphan shard GC provjerava reference oba autentificirana indeksa, uključujući v3. Reconcile popravlja samo kopije bajtova pinanih valjanim primaryjem; nema promocije mirrora. Različiti valjani rootovi klasificiraju se kao divergentni bez potpunog čitanja mirror povijesti; popravak uvijek zahtijeva provjeru primary povijesti. Uklonjen prethodni privatni decodeState koji više nema poziva.
- Limit 20.000 podignut na 100.000. Povećanje nije neograničeno. CLI lookup prihvaća šest znamenki, checkpointSummary provjerava cijelu povijest i računa prefiks bez rekonstrukcije svih receipata. CLI reconcile/orphan operacije ne pozivaju više nepotreban full read. Postojeći HMAC UUID bucket indeks zadržan, testiran na novom kapacitetu; v3 generation binding i mutacija cachea kompatibilni. Query i dalje drži O(N) skup identifikatora i spljošti listu referenci; full read/save su monolitni i ne moraju stati u 32 MiB. Stari limiti GC skeniranja (10.000 stavki), retirement certifikata i veličine datoteka ostaju.
- Merkle partialConsistencyProof/verifyPartialConsistency: dokazuje proširenje djelomične zadnje stranice arhiva iz prethodnih Merkle vrhova, novog boundary segmenta i hashova sljedećih segmenata. Potrebni su prethodno verificirani, pouzdani potpisani manifesti istog identiteta. Dokaz otkriva do 256 receipata i nije logaritamski po broju novih segmenata. Nije zamjena za provjeru jedinstvenosti ID-a ili potpuni audit arhiva.
- Operatorski checkpoint-initialize/checkpoint-advance iz verificiranog segmentiranog arhiva: Ed25519 potpis, prethodni potpisani prefix, odbijanje regresije i nestalog postojećeg sidra, atomska objava. Ne treba dekodirati monolitni JSON prijedlog. Nije mrežni servis niti automatizirana provjera CMS startupa.
- RecoveryNonceStore: lokalni privatni operatorski direktorij 0700, pojedinačni zapisi 0600, kooperativni lock, vezivanje noncea uz javni ključ i točno očekivani potpisani checkpoint, TTL do 300 s (CLI 60 s), atomska potrošnja prije povratka uspjeha, odbijanje ponovne uporabe, isteka ili nestale evidencije. Zapisi nisu samostalno kriptografski autentificirani i zahtijevaju zaštitu direktorija i neovisan rollback-resistant storage. Nema automatske retencije. Svježina odgovora ne jamči da je očekivani checkpoint najnoviji ako nije osvježen.

## Stvarno izvršene provjere

| Provjera | Rezultat |
|---|---|
| PHP regresije | 201/201 (5 novih) |
| Recovery publication / fault injection | 25/25 (3 nova stvarna SIGKILL scenarija) |
| Atomic write fault injection | 9/9 |
| HTTP/runtime fixture | 84/84 (61 Apache/front controller + 8 XML + 11 gate + 4 preflight) |
| Recovery panel komponenta | 6/6 |
| Anchor CLI prethodnog formata | 5/5 |
| Concurrency | 2/2, 80 zaključanih ažuriranja |
| PHP lint | 227/227 |
| JS lint | 31/31 |
| JSON provjera | 91 ulazna JSON datoteka, bez pogreške |
| Vendor integritet | 42/42 |
| ZIP/AES-256 runtime | SKIP, ext-zip nedostaje |

Tri stvarna SIGKILL scenarija: nakon rename mirrora i primaryja tijekom v2→v3 migracije, te nakon atomske potrošnje noncea. Oba indeksa oporavljena bez promjene starih receipt shardova; potrošeni nonce nije ponovno prihvaćen. Dodatne regresije provjeravaju gubitak jedne/dvije kopije stranice, popravak, rotaciju, GC reference i očuvanje postojećih GC dokaza, djelomični Merkle proof i rollback vanjskog checkpointa. Testovi simuliraju prekid procesa, ne nestanak napajanja.

## Benchmark aktivnog ledgera (stvarno izmjereno)

Sintetički seed 99.999 rollback receipata, zatim stvarni restore u izoliranom direktoriju koji završava na 100.000 receipata. Seed legacy save zahtijevao je 124.228.560 B vršne PHP memorije (limit 256 MiB); drugi PHP proces pod 32 MiB izvršio je query, index rebuild, lookup, prepare/apply/commit/recover, provjeru redundancije i odbio 100.001. transakciju. Zasebna rotacija na 100.000 zapisa također je prošla pod 32 MiB. Ovo nisu medijani, mjerenja na produkcijskom hostingu ili rezultat milijun aktivnih zapisa.

| Operacija | Vrijeme | Dodatna vršna PHP memorija |
|---|---:|---:|
| Query 100.000 | 357,09 ms | 12.716.888 B |
| UUID index rebuild 99.999 | 690,27 ms | 12.944.104 B |
| Indexed lookup 99.999 | 2,22 ms | 898.848 B |
| Prepare | 1.524,94 ms | 12.841.336 B |
| Apply + commit + recover | 2.789,22 ms | 12.852.896 B |
| Mirror provjera | 394,42 ms | 12.770.416 B |
| Indexed lookup novog receipta | 1,55 ms | 480.616 B |

Rotacija + provjera povijesti i mirrora: 4.615,66 ms i 15.205.128 B ukupne vršne PHP memorije (ne dodatna memorija). Streaming CLI checkpoint vraća seq 100.000 pod 32 MiB. Sirova mjerenja u tests/reports/active-ledger-*.json. Benchmark aktivnog ledgera na 1.000.000 nije izvršen: trenutni limit 100.000. Prethodni benchmark arhiva na milijun zapisa nije ponovljen i nije benchmark aktivne povijesti.

## Statički i CVE audit

Source scanner: 290 distribucijskih datoteka, 171 PHP, 20 rizičnih kandidata i 165 dinamičkih poziva. Semantic scanner: 178 datoteka, 314 privatnih metoda, 0 privatnih kandidata bez tekstualnih referenci, 3.284 statička poziva i 185 dinamičkih indikatora. Clone scanner: 1.362 funkcije, 1 identična skupina, 0 normaliziranih kandidata. To su konzervativni scanneri, ne potpuni AST/data-flow, neovisni security ili forenzički backdoor audit. Operatorske komponente pokrivene ciljanim pregledom i regresijama, nisu u distribucijskim scannerima koji izuzimaju tests.

42 lokalna vendor hasha odgovaraju manifestu. SBOM 13 deklariranih komponenti, podrijetlo i tranzitivni graf nisu neovisno verificirani. OSV pristup blokiran DNS-om; dependency audit exit 1. Ručno pregledani TinyMCE GHSA-mf2p-h6hf-fcwm (8.9.3 zakrpa), DOMPurify GHSA-mrv2-3pqr-j5jr i CVE-2026-109344/109345 (rasponi u opisima objava do 3.4.16). Deklarirane verzije nisu dokaz autentičnosti lokalnih bajtova. Izvori: https://github.com/advisories/GHSA-mf2p-h6hf-fcwm, https://github.com/advisories/GHSA-mrv2-3pqr-j5jr, https://github.com/advisories/GHSA-59fh-8xxf-p69h, https://github.com/advisories/GHSA-cjhx-p48c-gfm9. Biblioteke nisu mijenjane.

## Nedovršeno / ograničenja

- Nema destruktivnog active history compactiona, uklanjanja history receipata ili retencije starih indeksnih stranica; namjerno zadržani svi dokazi. Retencija potpisanih arhivskih manifesta ostaje konzervativna iz prethodnog izdanja.
- Aktivni limit 100.000, ne milijun; UUID index je prethodni 256-bucket format, ne novi višerazinski UUID trie. Globalna validacija ID-a i dalje O(N); I/O i memorija ovise o hostingu. Stare maintenance operacije mogu zahtijevati više memorije; GC skeniranje i retirement limiti mogu blokirati rad prije limita transakcija ako se akumuliraju dokazi. No downgrade starog koda nad v3 rootom.
- Nema neovisnog mrežnog trust anchor servisa, automatskog osvježenja pri pokretanju ni automatiziranog čišćenja nonce zapisa.
- PHP ekstenzije mbstring, gd, dom, curl i zip nedostaju; ZIP/AES, puni clean install i upgrade E2E nisu izvedeni. Pojedinačna funkcionalna i vizualna verifikacija svih tema/dodataka, Chromium/Firefox/WebKit, SEO promjene i CWV nisu izvedeni u ovom ciklusu. Nema fizičkog power-loss, Windows/NFS testova ni potpunog TOCTOU jamstva. Statički i komponentni testovi ne zamjenjuju penetration test.
- Korisnički sadržaj i lokalna Tiny Cloud konfiguracija ostaju nepromijenjeni; personalizirani ZIP ne distribuirati javno. Upute: NADOGRADNJA.md i SECURITY.md. Stari izvještaji odvojeni u tests/reports/historical-*.
