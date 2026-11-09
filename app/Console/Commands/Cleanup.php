<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GameSession;
use App\Services\SessionService;
use App\Support\EscapeRoom;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Regelmäßiges Aufräumen (läuft stündlich über den Scheduler):
 *  - verwaiste Live-Sessions beenden (Lehrkraft hat einfach den Tab geschlossen)
 *  - beendete Sessions nach einiger Zeit löschen
 *  - nicht mehr verwendete Bilder löschen (ersetzt, Frage/Spiel gelöscht)
 *  - abgelaufene Cache-Einträge löschen (Datenbank-Cache räumt nicht selbst auf)
 */
class Cleanup extends Command
{
    protected $signature = 'schulquizz:cleanup
        {--stale-hours=8 : Live-Sessions ohne Aktivität nach so vielen Stunden beenden}
        {--keep-days=30 : Beendete Sessions so viele Tage aufbewahren}
        {--image-grace-hours=24 : Unbenutzte Bilder erst nach dieser Zeit löschen (noch nicht gespeicherte Uploads)}
        {--dry-run : Nur anzeigen, nichts ändern}';

    protected $description = 'Verwaiste Sessions, alte Sessions, unbenutzte Bilder und abgelaufene Cache-Einträge aufräumen';

    public function handle(SessionService $sessions): int
    {
        $dry = (bool) $this->option('dry-run');

        // 1) Verwaiste Live-Sessions beenden
        $stale = GameSession::whereIn('status', ['lobby', 'active', 'paused'])
            ->where('updated_at', '<', Carbon::now()->subHours((int) $this->option('stale-hours')))
            ->get()
            // Dauerhaft geöffnete Escape-Rooms bleiben offen.
            ->reject(fn (GameSession $s) => (bool) ($s->settings['permanent'] ?? false));
        if (! $dry) {
            $stale->each(fn (GameSession $s) => $sessions->end($s));
        }

        // 2) Alte beendete Sessions löschen
        $old = GameSession::where('status', 'ended')
            ->where('updated_at', '<', Carbon::now()->subDays((int) $this->option('keep-days')));
        $oldCount = $dry ? $old->count() : $old->delete();

        // 3) Unbenutzte Bilder
        [$dirs, $files] = $this->cleanImages($dry, (int) $this->option('image-grace-hours'));

        // 4) Abgelaufene Cache-Einträge (+ abgelaufene DB-Sitzungen, falls jemand
        //    SESSION_DRIVER=database gesetzt hat – sie enthielten Schülernamen und IPs)
        $cache = $dry ? 0 : $this->pruneCache() + $this->pruneSessions();

        $this->info(sprintf(
            '%sSessions beendet: %d · alte Sessions gelöscht: %d · Bild-Ordner gelöscht: %d · Bilder gelöscht: %d · Cache-Einträge: %d',
            $dry ? '[dry-run] ' : '',
            $stale->count(),
            $oldCount,
            $dirs,
            $files,
            $cache,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} gelöschte Ordner, gelöschte Dateien
     */
    private function cleanImages(bool $dry, int $graceHours): array
    {
        $disk = Storage::disk('public');
        $cutoff = Carbon::now()->subHours($graceHours)->getTimestamp();
        $dirs = 0;
        $files = 0;

        foreach ($disk->directories('game-images') as $dir) {
            $gameId = (int) basename($dir);
            $game = Game::with('questions.answerOptions')->find($gameId);

            if (! $game) {
                $dirs++;
                if (! $dry) {
                    $disk->deleteDirectory($dir);
                }

                continue;
            }

            $used = array_flip($this->referencedPaths($game));
            foreach ($disk->files($dir) as $file) {
                if (isset($used[$file]) || $disk->lastModified($file) > $cutoff) {
                    continue;
                }
                $files++;
                if (! $dry) {
                    $disk->delete($file);
                }
            }
        }

        return [$dirs, $files];
    }

    /** @return string[] */
    private function referencedPaths(Game $game): array
    {
        $paths = [];
        foreach ($game->questions as $q) {
            $paths[] = $q->prompt_image_path;
            foreach ($q->answerOptions as $o) {
                $paths[] = $o->image_path;
            }
            foreach (['left', 'right'] as $side) {
                $paths[] = $q->config[$side]['image_path'] ?? null;
            }
        }
        if ($game->escape) {
            $paths = array_merge($paths, EscapeRoom::imagePaths($game->escape));
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    private function pruneSessions(): int
    {
        $table = config('session.table', 'sessions');
        if (config('session.driver') !== 'database' || ! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)
            ->where('last_activity', '<', Carbon::now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp())
            ->delete();
    }

    private function pruneCache(): int
    {
        if (config('cache.default') !== 'database') {
            return 0;
        }
        $deleted = 0;
        $now = Carbon::now()->getTimestamp();
        $store = config('cache.stores.database');
        foreach ([$store['table'] ?? 'cache', $store['lock_table'] ?? 'cache_locks'] as $table) {
            if (Schema::hasTable($table)) {
                $deleted += DB::table($table)->where('expiration', '<=', $now)->delete();
            }
        }

        return $deleted;
    }
}
