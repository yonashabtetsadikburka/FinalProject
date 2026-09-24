<?php
declare(strict_types=1);

namespace App\Exception;

use Exception;

class AppException extends Exception
{
    public string $codice;
    public int $http;

    public function __construct(string $codice, string $messaggio = '', int $http = 400)
    {
        parent::__construct($messaggio !== '' ? $messaggio : $codice);
        $this->codice = $codice;
        $this->http = $http;
    }
}
