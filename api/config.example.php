<?php
// Copiare questo file in config.php e mettere i valori veri.
// config.php e' nel .gitignore: NON deve mai finire su GitHub.
//
// Valori di default di MAMP:
//   porta MySQL 8889 (non 3306), utente root, password root.
//   Usare 127.0.0.1 e non 'localhost': con 'localhost' PHP cerca il socket
//   Unix, che in MAMP sta in un percorso non standard.

return [
    'host' => '127.0.0.1',
    'port' => 8889,
    'db'   => 'buypool',
    'user' => 'root',
    'pass' => 'root',

    // Google OAuth — Client ID ottenuto da Google Cloud Console
    'google_client_id' => '22127010556-mss3ikiiugra9aqv3im8m1jenn3jj8cd.apps.googleusercontent.com',
];
