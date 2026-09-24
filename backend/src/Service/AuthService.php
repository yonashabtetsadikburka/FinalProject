<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\AppException;
use Symfony\Component\HttpFoundation\RequestStack;

class AuthService
{
    public function __construct(
        private RequestStack $requestStack,
        private DatabaseService $db
    ) {}

    private function getSession(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->hasSession()) {
            return $request->getSession();
        }
        // fallback to native session for CLI/tests
        throw new AppException('SESSIONE_NON_DISPONIBILE', 'Sessione non disponibile', 500);
    }

    /** Supporte à la fois session Symfony et header Authorization Bearer session_{id} pour compatibilité frontend */
    public function utenteCorrenteId(): ?int
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->getSession();

        if ($session && $session->has('utente_id')) {
            return (int)$session->get('utente_id');
        }

        // Fallback: Authorization Bearer token (frontend state.js stocke "session_{id}")
        if ($request) {
            $auth = $request->headers->get('Authorization', '');
            if (preg_match('/Bearer\s+(?:session|google|microsoft)_(\d+)/i', $auth, $m)) {
                return (int)$m[1];
            }
            // Also support raw numeric token
            if (preg_match('/Bearer\s+(\d+)/', $auth, $m)) {
                return (int)$m[1];
            }
        }

        // Also check native $_SESSION for legacy
        if (isset($_SESSION['utente_id'])) return (int)$_SESSION['utente_id'];

        return null;
    }

    public function utenteEmail(): string
    {
        $id = $this->utenteCorrenteId();
        if (!$id) return '';
        $st = $this->db->getPdo()->prepare('SELECT email FROM utenti WHERE id = ?');
        $st->execute([$id]);
        return (string)$st->fetchColumn();
    }

    public function sonoAdmin(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->getSession();
        if ($session && $session->has('ruolo')) {
            return $session->get('ruolo') === 'admin';
        }
        // fallback via DB lookup from id
        $id = $this->utenteCorrenteId();
        if (!$id) return false;
        // Check bearer-provided role? fallback to DB
        $st = $this->db->getPdo()->prepare('SELECT ruolo FROM utenti WHERE id = ?');
        $st->execute([$id]);
        return $st->fetchColumn() === 'admin';
    }

    public function sonoFornitore(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->getSession();
        if ($session && $session->has('ruolo')) {
            return $session->get('ruolo') === 'fornitore';
        }
        $id = $this->utenteCorrenteId();
        if (!$id) return false;
        $st = $this->db->getPdo()->prepare('SELECT ruolo FROM utenti WHERE id = ?');
        $st->execute([$id]);
        return $st->fetchColumn() === 'fornitore';
    }

    public function fornitoreIdCorrente(): ?int
    {
        $id = $this->utenteCorrenteId();
        if ($id === null) return null;
        $st = $this->db->getPdo()->prepare('SELECT id FROM fornitori WHERE id_utente = ?');
        $st->execute([$id]);
        $fid = $st->fetchColumn();
        return $fid !== false ? (int)$fid : null;
    }

    public function richiediFornitore(): int
    {
        $io = $this->richiediLogin();
        if (!$this->sonoFornitore()) throw new AppException('NON_AUTORIZZATO', 'Accesso riservato ai fornitori', 403);
        $fid = $this->fornitoreIdCorrente();
        if ($fid === null) throw new AppException('FORNITORE_NON_COLLEGATO', 'Nessuna anagrafica fornitore collegata a questo account', 403);
        return $fid;
    }

    public function richiediLogin(): int
    {
        $id = $this->utenteCorrenteId();
        if ($id === null) throw new AppException('NON_AUTENTICATO', 'Devi effettuare il login', 401);
        return $id;
    }

    public function richiediUtenteAttivo(): int
    {
        $id = $this->richiediLogin();
        $st = $this->db->getPdo()->prepare('SELECT stato FROM utenti WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() !== 'attivo') {
            throw new AppException('UTENTE_SOSPESO', 'Account sospeso: puoi consultare storico e profilo ma non effettuare nuove operazioni', 403);
        }
        return $id;
    }

    public function setSession(int $utenteId, string $ruolo): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->getSession();
        if ($session) {
            $session->set('utente_id', $utenteId);
            $session->set('ruolo', $ruolo);
        }
        // Also set native for compatibility
        $_SESSION['utente_id'] = $utenteId;
        $_SESSION['ruolo'] = $ruolo;
    }

    public function clearSession(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->getSession();
        if ($session) {
            $session->clear();
            $session->invalidate();
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }
}
