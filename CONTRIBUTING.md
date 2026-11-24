# Razvoj i provjera izmjena

Promjene radite na zasebnoj kopiji bez produkcijskih korisničkih podataka. Prije isporuke pokrenite `php tests/run.php`, PHP lint svih PHP datoteka i `node --check` nad isporučenim JavaScript datotekama. Pokrenite funkcionalne testove uz pune PHP ekstenzije, na staging instalaciji s HTTPS-om. Ne brišite javne API metode ili dinamičke hookove samo zato što nemaju izravan statički poziv. Dokumentirajte promjene kompatibilnosti i nalaze audita.
