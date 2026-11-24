# Razvoj tema i dodataka

Extension API 2.0 ostaje kompatibilan. Za temu ili dodatak pratite strukturu i metadata.json isporučenih proširenja. PHP ulazne datoteke moraju imati `BRENDY` guard. Za teme koristite `Theme::publicHead()` kako bi se zadržala jedinstvena canonical, robots i social metadata obrada; ne dodajte drugi canonical ili zaseban SEO generator.

Dodatke registrirajte kroz postojeću klasu i lifecycle hookove. Provjeravajte ulaze, ovlaštenja i CSRF na mutacijama, koristite postojeće sigurnosne funkcije, ograničene filesystem operacije i odgovarajuće izlazno escapiranje. Izbjegavajte izravno izlaganje PHP datoteka HTTP-u. Testirajte aktivaciju, deaktivaciju i migraciju vlastitih proširenja odvojeno.
