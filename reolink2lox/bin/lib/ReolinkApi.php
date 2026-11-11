<?php
/**
 * Reolink-HTTP-API einer Kamera: POST /cgi-bin/api.cgi mit JSON-Liste, Anmeldung mit Token.
 *
 * - Das Token (Login) wird wiederverwendet und erneuert, bevor es ablaeuft; beim Beenden wird abgemeldet (die Kamera erlaubt nur wenige Sitzungen).
 * - Zwischen zwei Anfragen liegt ein Mindestabstand: eine schnelle Folge von Anfragen hat am RLC-823S1 den Webdienst der Kamera zum Absturz gebracht.
 * - Zugangsdaten stehen nur im Anmelde-Koerper, nie in der Adresse, nie in Fehlermeldungen.
 */
final class ReolinkApi
{
    public const string ART_VERBINDUNG = 'verbindung';     // Kamera nicht erreichbar
    public const string ART_ANMELDUNG = 'anmeldung';       // Benutzer oder Passwort falsch
    public const string ART_SITZUNGEN = 'sitzungen';       // zu viele Anmeldungen an der Kamera
    public const string ART_DIENST = 'dienst';             // Webdienst der Kamera antwortet mit Fehler (z. B. 502)
    public const string ART_ANTWORT = 'antwort';           // unverstaendliche Antwort
    public const string ART_RECHT = 'recht';               // Benutzer darf das nicht (ability error)

    public string $lastError = '';
    public string $lastArt = '';
    private ?string $token = null;
    private int $tokenBis = 0;
    /** @var array{0:int,1:bool}|null [Port, TLS] des erfolgreichen Zugangs */
    private ?array $ziel = null;
    private float $letzte = 0.0;

    public function __construct(private readonly string $host, private readonly int $port, private readonly string $schema,
                                private readonly string $benutzer, private readonly string $passwort,
                                private readonly float $verbindungsZeit = 3.0, private readonly float $zeit = 10.0, private readonly float $mindestAbstand = 0.25)
    {
    }

    public function eingeloggt(): bool
    {
        return $this->token !== null && time() < $this->tokenBis;
    }

    /** Zugang der Reihe nach: [Port, TLS]. */
    private function ziele(): array
    {
        if ($this->ziel !== null) {
            return [$this->ziel];
        }
        $z = [];
        if ($this->schema !== 'http') {
            $z[] = [$this->port > 0 ? $this->port : 443, true];
        }
        if ($this->schema !== 'https') {
            $z[] = [$this->port > 0 ? $this->port : 80, false];
        }
        return $z;
    }

    private function fehler(string $art, string $text): void
    {
        $this->lastArt = $art;
        $this->lastError = $text;
    }

    private function abstand(): void
    {
        $warte = $this->letzte + $this->mindestAbstand - microtime(true);
        if ($warte > 0) {
            usleep((int)($warte * 1000000));
        }
    }

    /** @return array{status:int,body:string,error:string} */
    private function senden(string $query, array $liste, bool $tls, int $port): array
    {
        $this->abstand();
        $r = ReolinkHttp::post($this->host, $port, $tls, '/cgi-bin/api.cgi' . $query, json_encode($liste, JSON_UNESCAPED_UNICODE), $this->verbindungsZeit, $this->zeit);
        $this->letzte = microtime(true);
        return $r;
    }

    public function login(): bool
    {
        $this->token = null;
        $body = [['cmd' => 'Login', 'action' => 0, 'param' => ['User' => ['userName' => $this->benutzer, 'password' => $this->passwort]]]];
        $erster = null;
        foreach ($this->ziele() as [$port, $tls]) {
            $r = $this->senden('?cmd=Login', $body, $tls, $port);
            if ($r['status'] === 0) {
                $erster ??= [self::ART_VERBINDUNG, $r['error']];
                continue;
            }
            if ($r['status'] !== 200) {
                $erster ??= [self::ART_DIENST, 'Webdienst der Kamera antwortet mit HTTP ' . $r['status'] . ' (Kamera überlastet oder startet neu)'];
                continue;
            }
            $j = json_decode($r['body'], true);
            if (!is_array($j) || !is_array($j[0] ?? null)) {
                $erster ??= [self::ART_ANTWORT, 'Unverständliche Antwort der Kamera bei der Anmeldung'];
                continue;
            }
            $a = $j[0];
            if (($a['code'] ?? 1) === 0 && isset($a['value']['Token']['name'])) {
                $this->token = (string)$a['value']['Token']['name'];
                $this->tokenBis = time() + max(60, (int)($a['value']['Token']['leaseTime'] ?? 3600)) - 120;
                $this->ziel = [$port, $tls];
                $this->lastError = '';
                $this->lastArt = '';
                return true;
            }
            $detail = strtolower((string)($a['error']['detail'] ?? ''));
            if (str_contains($detail, 'max') || str_contains($detail, 'session')) {
                $this->fehler(self::ART_SITZUNGEN, 'Zu viele Anmeldungen an der Kamera (Sitzungsgrenze erreicht)');
            } else {
                $this->fehler(self::ART_ANMELDUNG, 'Anmeldung abgelehnt: Benutzer oder Passwort falsch?');
            }
            return false;      // Kamera hat geantwortet: weitere Ziele (HTTP statt HTTPS) bringen nichts
        }
        [$art, $text] = $erster ?? [self::ART_VERBINDUNG, 'Kamera nicht erreichbar'];
        $this->fehler($art, $text);
        return false;
    }

    public function logout(): void
    {
        if ($this->token === null || $this->ziel === null) {
            return;
        }
        $this->senden('?cmd=Logout&token=' . rawurlencode($this->token), [['cmd' => 'Logout', 'action' => 0, 'param' => new stdClass()]], $this->ziel[1], $this->ziel[0]);
        $this->token = null;
    }

    /** Ohne Abmeldung vergessen (z. B. nach einem Neustart der Kamera). */
    public function vergiss(): void
    {
        $this->token = null;
        $this->ziel = null;
    }

    /**
     * Befehle in einer Anfrage senden.
     * @param list<array{cmd:string,param?:array|object,action?:int}> $cmds
     * @return list<array{ok:bool,value:array,range:array,rsp:int,detail:string}>|null  null bei Fehler (siehe lastError / lastArt)
     */
    public function call(array $cmds, bool $nochmal = true): ?array
    {
        if (!$this->eingeloggt() && !$this->login()) {
            return null;
        }
        $liste = [];
        foreach ($cmds as $c) {
            $liste[] = ['cmd' => $c['cmd'], 'action' => $c['action'] ?? 0, 'param' => $c['param'] ?? new stdClass()];
        }
        [$port, $tls] = $this->ziel;
        $r = $this->senden('?token=' . rawurlencode((string)$this->token), $liste, $tls, $port);
        if ($r['status'] === 0) {
            $this->fehler(self::ART_VERBINDUNG, $r['error']);
            $this->vergiss();
            return null;
        }
        if ($r['status'] !== 200) {
            $this->fehler(self::ART_DIENST, 'Webdienst der Kamera antwortet mit HTTP ' . $r['status'] . ' (Kamera überlastet oder startet neu)');
            $this->vergiss();
            return null;
        }
        $j = json_decode($r['body'], true);
        if (!is_array($j) || !array_is_list($j)) {
            $this->fehler(self::ART_ANTWORT, 'Unverständliche Antwort der Kamera');
            return null;
        }
        $out = [];
        $tokenAbgelaufen = false;
        foreach ($cmds as $i => $c) {
            $a = is_array($j[$i] ?? null) ? $j[$i] : [];
            $ok = ($a['code'] ?? 1) === 0;
            $rsp = $ok ? (int)($a['value']['rspCode'] ?? 200) : (int)($a['error']['rspCode'] ?? 0);
            $detail = $ok ? '' : (string)($a['error']['detail'] ?? 'Fehler');
            if (!$ok && ($rsp === -6 || str_contains(strtolower($detail), 'login first'))) {
                $tokenAbgelaufen = true;
            }
            $out[] = ['ok' => $ok, 'value' => is_array($a['value'] ?? null) ? $a['value'] : [], 'range' => is_array($a['range'] ?? null) ? $a['range'] : [], 'rsp' => $rsp, 'detail' => $detail];
        }
        if ($tokenAbgelaufen) {
            $this->token = null;
            if ($nochmal) {
                return $this->call($cmds, false);
            }
            $this->fehler(self::ART_ANMELDUNG, 'Die Kamera verwirft das Anmelde-Token');
            return null;
        }
        $this->lastError = '';
        $this->lastArt = '';
        return $out;
    }

    /** Ein Befehl; null bei Fehler der Verbindung, sonst die Antwort (auch mit ok=false, z. B. "not support"). */
    public function einzeln(string $cmd, array $param = []): ?array
    {
        $r = $this->call([['cmd' => $cmd, 'param' => $param ?: new stdClass()]]);
        return $r === null ? null : $r[0];
    }
}
