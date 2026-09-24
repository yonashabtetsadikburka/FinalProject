<?php
declare(strict_types=1);

namespace App\Controller;

use App\Exception\AppException;
use App\Service\ApiResponse;
use App\Service\AuthService;
use App\Service\DatabaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    public function __construct(private DatabaseService $db, private AuthService $auth) {}

    #[Route('/api/salute', methods: ['GET'])]
    #[Route('/salute', methods: ['GET'])]
    public function salute(): JsonResponse
    {
        return ApiResponse::ok(['stato' => 'ok', 'php' => PHP_VERSION]);
    }

    #[Route('/api/registrazione', methods: ['POST'])]
    #[Route('/registrazione', methods: ['POST'])]
    public function registrazione(Request $request): JsonResponse
    {
        $d = ApiResponse::corpo($request);
        $nome = ApiResponse::campo($d, 'nome');
        $email = ApiResponse::campo($d, 'email');
        $pass = ApiResponse::campo($d, 'password');
        $cognome = trim((string)($d['cognome'] ?? ''));
        $tipo = trim((string)($d['tipo'] ?? 'privato'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppException('EMAIL_NON_VALIDA', 'Indirizzo email non valido');
        if (strlen($pass) < 8) throw new AppException('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri');
        if (!in_array($tipo, ['privato', 'b2b'], true)) $tipo = 'privato';
        if (empty($d['privacy'])) throw new AppException('PRIVACY_NON_ACCETTATA', "Devi accettare l'informativa privacy per registrarti");

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        try {
            $st = $this->db->getPdo()->prepare('INSERT INTO utenti (nome, cognome, email, password_hash, tipo, privacy_accettata_at) VALUES (?,?,?,?,?,NOW())');
            $st->execute([$nome, $cognome, $email, $hash, $tipo]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') throw new AppException('EMAIL_GIA_USATA', "Esiste gia' un account con questa email", 409);
            throw $e;
        }
        return ApiResponse::ok(['id' => (int)$this->db->getPdo()->lastInsertId(), 'nome' => $nome, 'cognome' => $cognome, 'ruolo' => 'cliente'], 201);
    }

    #[Route('/api/login', methods: ['POST'])]
    #[Route('/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $d = ApiResponse::corpo($request);
        $email = ApiResponse::campo($d, 'email');
        $pass = ApiResponse::campo($d, 'password');
        $st = $this->db->getPdo()->prepare('SELECT id, nome, cognome, email, ruolo, stato, password_hash FROM utenti WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            throw new AppException('CREDENZIALI_NON_VALIDE', 'Email o password non corretti', 401);
        }
        $request->getSession()->migrate(true);
        $this->auth->setSession((int)$u['id'], $u['ruolo']);
        return ApiResponse::ok(['id' => (int)$u['id'], 'nome' => $u['nome'], 'cognome' => $u['cognome'] ?? '', 'email' => $u['email'], 'ruolo' => $u['ruolo'], 'stato' => $u['stato']]);
    }

    #[Route('/api/auth/google', methods: ['POST'])]
    #[Route('/auth/google', methods: ['POST'])]
    public function googleLogin(Request $request): JsonResponse
    {
        $d = ApiResponse::corpo($request);
        $token = ApiResponse::campo($d, 'token');
        $configId = $this->db->getConfigValue('google_client_id', '');
        $client = new \Google\Client(['client_id' => $configId]);
        $client->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
        $payload = $client->verifyIdToken($token);
        if (!$payload) throw new AppException('TOKEN_GOOGLE_NON_VALIDO', 'Token Google non valido', 401);
        $googleId = $payload['sub'];
        $email = $payload['email'];
        $nome = $payload['given_name'] ?? '';
        $cognome = $payload['family_name'] ?? '';
        $pdo = $this->db->getPdo();
        $st = $pdo->prepare('SELECT id, nome, cognome, ruolo, stato, google_id FROM utenti WHERE google_id = ? OR email = ?');
        $st->execute([$googleId, $email]);
        $utente = $st->fetch();
        if ($utente) {
            if (empty($utente['google_id'])) {
                $stUp = $pdo->prepare('UPDATE utenti SET google_id = ? WHERE id = ?');
                $stUp->execute([$googleId, $utente['id']]);
            }
        } else {
            $st = $pdo->prepare('INSERT INTO utenti (nome, cognome, email, google_id, ruolo) VALUES (?, ?, ?, ?, ?)');
            $st->execute([$nome, $cognome, $email, $googleId, 'cliente']);
            $utente = ['id' => (int)$pdo->lastInsertId(), 'nome' => $nome, 'cognome' => $cognome, 'ruolo' => 'cliente', 'stato' => 'attivo'];
        }
        $request->getSession()->migrate(true);
        $this->auth->setSession((int)$utente['id'], $utente['ruolo']);
        return ApiResponse::ok(['id' => (int)$utente['id'], 'nome' => $utente['nome'], 'cognome' => $utente['cognome'] ?? '', 'ruolo' => $utente['ruolo'], 'stato' => $utente['stato'] ?? 'attivo']);
    }

    #[Route('/api/auth/microsoft', methods: ['POST'])]
    #[Route('/auth/microsoft', methods: ['POST'])]
    public function microsoftLogin(Request $request): JsonResponse
    {
        $d = ApiResponse::corpo($request);
        $token = ApiResponse::campo($d, 'token');
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new AppException('TOKEN_MICROSOFT_NON_VALIDO', 'Token Microsoft non valido', 401);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload || !isset($payload['oid'])) throw new AppException('TOKEN_MICROSOFT_NON_VALIDO', 'Token Microsoft non valido', 401);
        $microsoftId = $payload['oid'];
        $email = $payload['email'] ?? $payload['preferred_username'] ?? '';
        $nome = $payload['given_name'] ?? '';
        $cognome = $payload['family_name'] ?? '';
        $pdo = $this->db->getPdo();
        $st = $pdo->prepare('SELECT id, nome, cognome, ruolo, stato, microsoft_id FROM utenti WHERE microsoft_id = ? OR email = ?');
        $st->execute([$microsoftId, $email]);
        $utente = $st->fetch();
        if ($utente) {
            if (empty($utente['microsoft_id'])) {
                $stUp = $pdo->prepare('UPDATE utenti SET microsoft_id = ? WHERE id = ?');
                $stUp->execute([$microsoftId, $utente['id']]);
            }
        } else {
            $st = $pdo->prepare('INSERT INTO utenti (nome, cognome, email, microsoft_id, ruolo) VALUES (?, ?, ?, ?, ?)');
            $st->execute([$nome, $cognome, $email, $microsoftId, 'cliente']);
            $utente = ['id' => (int)$pdo->lastInsertId(), 'nome' => $nome, 'cognome' => $cognome, 'ruolo' => 'cliente', 'stato' => 'attivo'];
        }
        $request->getSession()->migrate(true);
        $this->auth->setSession((int)$utente['id'], $utente['ruolo']);
        return ApiResponse::ok(['id' => (int)$utente['id'], 'nome' => $utente['nome'], 'cognome' => $utente['cognome'] ?? '', 'ruolo' => $utente['ruolo'], 'stato' => $utente['stato'] ?? 'attivo']);
    }

    #[Route('/api/logout', methods: ['POST'])]
    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $this->auth->clearSession();
        return ApiResponse::ok(['uscito' => true]);
    }

    #[Route('/api/io', methods: ['GET'])]
    #[Route('/io', methods: ['GET'])]
    public function io(): JsonResponse
    {
        $id = $this->auth->richiediLogin();
        $st = $this->db->getPdo()->prepare('SELECT id, nome, email, ruolo, stato FROM utenti WHERE id = ?');
        $st->execute([$id]);
        return ApiResponse::ok($st->fetch());
    }
}
