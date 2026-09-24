<?php
declare(strict_types=1);

namespace App\Service;

class RipartizioneService
{
    /**
     * @param array $richieste [ ['id_prenotazione'=>int, 'utente_id'=>int, 'quantita'=>int, 'created_at'=>string], ... ]
     */
    public function ripartisci(array $richieste, int $pezzi_disponibili): array
    {
        $totale = array_sum(array_column($richieste, 'quantita'));
        if ($totale <= 0 || $pezzi_disponibili <= 0) return [];

        $calcolo = [];
        foreach ($richieste as $r) {
            $esatta = ($r['quantita'] / $totale) * $pezzi_disponibili;
            $base   = (int)floor($esatta);
            $calcolo[] = [
                'id_prenotazione' => (int)$r['id_prenotazione'],
                'utente_id'       => (int)$r['utente_id'],
                'quantita'        => $base,
                'resto'           => $esatta - $base,
                'created_at'      => $r['created_at'],
            ];
        }
        $residue = $pezzi_disponibili - array_sum(array_column($calcolo, 'quantita'));
        usort($calcolo, function ($a, $b) {
            if ($a['resto'] === $b['resto']) return strcmp($a['created_at'], $b['created_at']);
            return $b['resto'] <=> $a['resto'];
        });
        for ($i = 0; $i < $residue; $i++) {
            $calcolo[$i]['quantita']++;
        }
        return array_map(
            fn($c) => ['id_prenotazione' => $c['id_prenotazione'], 'utente_id' => $c['utente_id'], 'quantita' => $c['quantita']],
            $calcolo
        );
    }
}
