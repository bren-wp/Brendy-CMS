# Struktura sustava

- `index.php`: javni front controller i preflight. `install.php`: instalacija.
- `jezgra/`: modeli, sigurnost, rutiranje, administracija, pomoćnici, AJAX i lokalni uređivač.
- `dodaci/`: devet isporučenih dodataka (API, backup-manager, categories, comments, gallery, navigation, search, sitemap, tags).
- `teme/`: alternativa, blog, fokus, novosti, okus. Zajednički SEO i vizualne funkcije nalaze se u `Theme`.
- `jezici/`: hrvatska lokalizacija. `sadrzaj/`: privatni JSON podaci, sadržaj, radni prostori, revizije i javne datoteke u uploads. Ne izlažite ostale poddirektorije HTTP-u.
- `tests/`: CLI regresijske provjere. Ne objavljujte ih kao javne rute.
