<?php

namespace App\Concerns;

use Illuminate\Support\Facades\App;

/**
 * Minimaler, abhängigkeitsfreier Translatable-Support.
 *
 * Als `translatable` deklarierte Felder werden als JSON gespeichert
 * (`{"de": "…", "en": "…"}`). Beim Lesen wird die aktive App-Locale bevorzugt,
 * mit Fallback auf `base_locale` des Modells bzw. den ersten vorhandenen Wert.
 *
 * Das Modell definiert:
 *   protected array $translatable = ['title', 'description'];
 * und castet diese Felder auf 'array'.
 */
trait HasTranslations
{
    public function getTranslatableAttributes(): array
    {
        return $this->translatable ?? [];
    }

    /**
     * Roh gespeicherte Übersetzungen (locale => value) eines Feldes.
     */
    public function getTranslations(string $key): array
    {
        $value = $this->attributes[$key] ?? null;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Übersetzung in einer bestimmten (oder der aktiven) Locale mit Fallback.
     */
    public function getTranslation(string $key, ?string $locale = null): ?string
    {
        $translations = $this->getTranslations($key);
        if (empty($translations)) {
            return null;
        }

        $locale ??= App::getLocale();
        $base = $this->base_locale ?? config('app.fallback_locale', 'de');

        return $translations[$locale]
            ?? $translations[$base]
            ?? reset($translations)
            ?: null;
    }

    public function getAttribute($key)
    {
        if (in_array($key, $this->getTranslatableAttributes(), true)) {
            return $this->getTranslation($key);
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->getTranslatableAttributes(), true)) {
            // String -> in base_locale ablegen; Array (locale=>value) direkt übernehmen.
            if (is_string($value)) {
                $base = $this->base_locale ?? config('app.fallback_locale', 'de');
                $existing = $this->getTranslations($key);
                $existing[$base] = $value;
                $value = $existing;
            }
            $this->attributes[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Alle Übersetzungen eines Feldes explizit setzen (locale => value).
     */
    public function setTranslations(string $key, array $values): static
    {
        $this->attributes[$key] = json_encode($values, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    /**
     * Beim Serialisieren (z.B. Inertia/JSON) die aufgelöste Übersetzung liefern,
     * zusätzlich die Rohdaten unter `<feld>_translations` bereitstellen.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        foreach ($this->getTranslatableAttributes() as $key) {
            $array[$key] = $this->getTranslation($key);
            $array[$key . '_translations'] = $this->getTranslations($key);
        }

        return $array;
    }
}
