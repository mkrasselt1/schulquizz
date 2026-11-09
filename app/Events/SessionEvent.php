<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ein generisches Realtime-Event für Sessions – hält die Zahl der Event-Klassen klein.
 *
 * `scope` bestimmt die Ziel-Kanäle:
 *  - players : ÖFFENTLICHER Channel `session.{key}` (Schüler, kein Login) + Board
 *  - teacher : privater Lehrer-Channel (authentifiziert)
 *  - board   : nur Board
 *
 * Schüler nutzen bewusst öffentliche Kanäle (unratbarer session_key) – so ist keine
 * Gast-Autorisierung nötig, was Presence-Kanäle ohne Konten unzuverlässig machen würde.
 *
 * ShouldBroadcastNow → synchron ohne Queue-Worker (wichtig für niedrige Latenz + kein Redis).
 */
class SessionEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $sessionKey,
        public string $event,
        public array $payload = [],
        public string $scope = 'players',
    ) {
    }

    /**
     * Sicher senden: Ist der WebSocket-Server gerade weg (Neustart, Absturz), soll die
     * eigentliche Aktion trotzdem gelingen – der Fehler wird nur protokolliert.
     */
    public static function send(string $sessionKey, string $event, array $payload = [], string $scope = 'players'): void
    {
        try {
            event(new self($sessionKey, $event, $payload, $scope));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function broadcastOn(): array
    {
        return match ($this->scope) {
            'teacher' => [new PrivateChannel("session.{$this->sessionKey}.teacher")],
            'board' => [new Channel("board.{$this->sessionKey}")],
            default => [
                new Channel("session.{$this->sessionKey}"),
                new Channel("board.{$this->sessionKey}"),
            ],
        };
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
