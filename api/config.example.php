<?php
// Copiare questo file in config.php e mettere i valori veri.
// config.php e' nel .gitignore: NON deve mai finire su GitHub.
//
// Database: schema di Abdu -> "collette_acquisto_gruppo"
//   (importare schema.sql in phpMyAdmin prima di avviare l'API).
//
// Valori di default di MAMP:
//   porta MySQL di solito 8889, ma su alcune installazioni e' 3306.
//   Controllare in MAMP > Preferences > Ports e mettere qui quella giusta.
//   Utente root, password root.
//   Usare 127.0.0.1 e non 'localhost': con 'localhost' PHP cerca il socket
//   Unix, che in MAMP sta in un percorso non standard.

return [
    'host' => '127.0.0.1',
    'port' => 8889,   // <-- METTERE la porta MySQL reale del proprio MAMP (spesso 8889 o 3306)
    'db'   => 'collette_acquisto_gruppo',
    'user' => 'root',
    'pass' => 'root',
];
