# Brendy API

**API dodatak:** 2.0.9  
Verzija protokola: 2.3 (prema `/api/meta`). Aktivirajte dodatak i generirajte vjerodajnice u administraciji. Šaljite `Authorization: Bearer TOKEN` za čitanje, a `X-Brendy-Authentication` za ovlaštene upise. Vjerodajnice se ne šalju u URL-u ni tijelu zahtjeva. Koristite HTTPS. Rotirajte vjerodajnice u slučaju izlaganja.

`GET /api/meta` vraća trenutne mogućnosti, metode i status ovlaštenja. Rute: `/api/pages` (GET, POST), `/api/pages/{key}` (GET, PUT, DELETE), `/api/tags` i `/api/tags/{key}` (GET), `/api/categories` i `/api/categories/{key}` (GET), `/api/users` i `/api/users/{username}` (GET), `/api/files/{page-key}` (GET, POST), `/api/images` (POST), `/api/settings` (GET, PUT; potrebna dodatna autorizacija). URL-ove prilagodite podmapi instalacije. JSON upisi moraju odgovarati modelu podataka i provjerama jezgre. Ograničenja uloga, brzine i isteka tokena ostaju aktivna.
