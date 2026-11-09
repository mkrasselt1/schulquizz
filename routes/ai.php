<?php

use App\Mcp\Servers\SchulquizzServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * MCP-Schnittstelle für KI-Assistenten (Claude, ChatGPT, Cursor …).
 * Anmeldung per OAuth: Der Assistent registriert sich selbst, die Lehrkraft meldet sich
 * im Browser an und klickt „Erlauben“ – kein Token-Kopieren nötig.
 * Zugriff nur auf die EIGENEN Spiele, nur zum Anlegen/Bearbeiten von Rätseln & Räumen.
 */
Mcp::oauthRoutes();

Mcp::web('/mcp', SchulquizzServer::class)
    ->middleware(['auth:api', 'throttle:120,1']);
