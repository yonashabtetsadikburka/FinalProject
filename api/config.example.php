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

    // URL pubblico del front-end (usato nei link di email/Stripe/inviti).
    // Con MAMP: http://localhost:8888/<cartella-del-progetto>/frontend
    'frontend_url' => 'http://localhost:8888/FinalProject/frontend',

    // Costo fisso della consegna a domicilio, in euro.
    'costo_spedizione' => 5.00,

    // Stripe (chiavi di TEST dalla dashboard Stripe). Senza queste il
    // pagamento risponde con un errore chiaro, il resto dell'API funziona.
    'stripe_secret_key'     => '',
    'stripe_webhook_secret' => '',

    // Accesso con Google / Microsoft. L'ID client Google e' pubblico (sta
    // gia' nel front-end). Se microsoft_client_id e' vuoto il login
    // Microsoft e' disabilitato.
    'google_client_id'    => '22127010556-mss3ikiiugra9aqv3im8m1jenn3jj8cd.apps.googleusercontent.com',
    'microsoft_client_id' => '',
];
