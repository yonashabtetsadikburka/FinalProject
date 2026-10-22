# Modulo "Nuova proposta": checklist test manuale

Prerequisiti: `extension=gd`, `fileinfo`, `exif` attivi in `php.ini`, limiti alzati (`upload_max_filesize=6M`, `post_max_size=12M`, `memory_limit=256M`), Apache riavviato, migrazione `api/migrations/2026-10-20_proposte_form_foto.sql` applicata.
Verifica rapida: `php -r "var_dump(extension_loaded('gd'), gd_info()['WebP Support']);"` deve stampare due `true`.

## 1. Invio
1. Cliente: Proposte → "+ Nuova proposta". Compila nome, descrizione, categoria, link, prezzo e **senza foto** → "Proposta inviata!" con WhatsApp / Telegram / Copia link e la nota sull'approvazione. Chiudi: si apre "Le mie proposte" con la proposta "In attesa".
2. Ripeti **con foto** (JPEG da telefono, anche ruotato): anteprima nel modulo, "Rimuovi foto" la toglie; dopo l'invio, in `api/uploads/proposals/` compaiono due file (`<32 hex>.webp` e `<32 hex>_t.webp`) e nel DB `foto_file` contiene solo il nome.
3. Dopo l'approvazione admin, la card mostra la miniatura e la pillola categoria.

## 2. Errori per campo (nessun invio al server finché non sono corretti)
- Nome vuoto / 2 caratteri / 81 caratteri.
- Descrizione vuota / oltre 300 (contatore).
- Link `esempio.it`, `javascript:alert(1)`, `ftp://…`.
- Prezzo `abc`, `-5`, `1,999`. Prezzo `0` e `49,90` ok.
- Foto: file `.txt` rinominato `.jpg`, SVG, GIF → "Formato non supportato: usa JPEG, PNG o WebP"; file > 5 MB → "Il file supera 5 MB"; immagine 200×200 → "troppo piccola"; > 6000 px → "troppo grande".
- Anche saltando i controlli del browser (richiesta manuale con curl) il server risponde `VALIDAZIONE` con l'errore per campo.

## 3. Limite proposte
1. Con lo stesso cliente invia 5 proposte (restano "In attesa"/"In votazione").
2. La 6ª mostra "Hai già 5 proposte attive: attendi che vengano valutate o scadano…". Una proposta scaduta / in valutazione / rifiutata libera un posto.

## 4. Avviso doppioni
1. Con la proposta approvata di un altro cliente ("Cuffie wireless" in votazione), scrivi "cuffie" nel nome: dopo ~400 ms compare "Esiste già una proposta simile: vota quella" con titolo, voti e link.
2. Con meno di 3 caratteri non compare nulla. Il tuo stesso titolo non compare. L'invio non è bloccato.
3. Il link "Vai alla proposta" chiude il modulo ed evidenzia la card.

## 5. Sicurezza file
- Metadati: invia una foto con GPS (da telefono) e verifica con `exiftool` il file in `uploads/proposals/`: nessun EXIF/GPS.
- Orientamento: foto scattata in verticale arriva dritta.
- Esecuzione script: crea `api/uploads/proposals/prova.php` con `<?php echo 'ESEGUITO';` e apri `http://localhost:8888/buypool/api/uploads/proposals/prova.php`: deve dare 403 (mai "ESEGUITO"). Poi cancellalo.
- Un file con nome `x.php.jpg` caricato come foto viene ricodificato e salvato con nome casuale.

## 6. Cancellazione / file orfani
1. Dry-run: `php scripts/pulisci_foto_proposte.php` elenca gli orfani (file più vecchi di 1 ora non referenziati).
2. Elimina l'utente di prova dal pannello admin (le sue proposte spariscono per cascata) → rilancia lo script: i suoi file compaiono come orfani; `php scripts/pulisci_foto_proposte.php --applica` li elimina; rilanciato di nuovo: 0.
3. Le foto di una proposta pubblicata (campagna) NON vengono considerate orfane.
