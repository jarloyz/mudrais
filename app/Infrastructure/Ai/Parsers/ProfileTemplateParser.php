<?php

namespace App\Infrastructure\Ai\Parsers;

class ProfileTemplateParser
{
    /**
     * Parse a structured Discord profile ficha into an array.
     * All fields are extracted via regex — no AI involved.
     *
     * @return array{
     *   age: int|null,
     *   nationality: string|null,
     *   experience_level: string|null,
     *   schedule: array{description:string,timezone:string}|null,
     *   verbosity: string|null,
     *   red_lines: list<string>|null,
     *   yellow_lines: list<string>|null,
     *   affinities: list<string>|null,
     *   raw_profile: string|null
     * }
     */
    public function parse(string $text): array
    {
        return [
            'age'              => $this->parseAge($text),
            'nationality'      => $this->parseNationality($text),
            'experience_level' => $this->parseExperience($text),
            'schedule'         => $this->parseSchedule($text),
            'verbosity'        => $this->parseVerbosity($text),
            'red_lines'        => $this->parseRedLines($text),
            'yellow_lines'     => $this->parseYellowLines($text),
            'affinities'       => $this->parseAffinities($text),
            'raw_profile'      => $this->parseRawProfile($text),
        ];
    }

    /**
     * Returns true when the fields critical for matchmaking are present.
     *
     * @param array<string, mixed> $parsed
     */
    public function isComplete(array $parsed): bool
    {
        return $parsed['age'] !== null
            && $parsed['experience_level'] !== null
            && ! empty($parsed['red_lines'])
            && ! empty($parsed['affinities'])
            && $parsed['raw_profile'] !== null;
    }

    // ── Field extractors ─────────────────────────────────────────────────────

    private function parseAge(string $text): ?int
    {
        if (preg_match('/\*{0,2}\s*Edad\s*:\s*(\d+)/iu', $text, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function parseNationality(string $text): ?string
    {
        if (preg_match('/\*{0,2}\s*Nacionalidad\s*:\s*(.+)/iu', $text, $m)) {
            return $this->cleanLine($m[1]);
        }

        return null;
    }

    private function parseExperience(string $text): ?string
    {
        if (preg_match('/\*{0,2}\s*Experiencia\s*:\s*(.+)/iu', $text, $m)) {
            return $this->normalizeExperience($this->cleanLine($m[1]));
        }

        return null;
    }

    /**
     * @return array{description:string,timezone:string}|null
     */
    private function parseSchedule(string $text): ?array
    {
        if (preg_match('/\*{0,2}\s*Horarios?\s+(?:disponibles?)?\s*:\s*(.+)/iu', $text, $m)) {
            $raw = $this->cleanLine($m[1]);

            return [
                'description' => $raw,
                'timezone'    => $this->extractTimezone($raw),
            ];
        }

        return null;
    }

    private function parseVerbosity(string $text): ?string
    {
        // Matches "Extensión:", "Extension:", "Verbosidad:" — all common variants
        if (preg_match('/\*{0,2}\s*(?:Extensi[oó]n|Verbosidad)\s*:\s*(.+)/iu', $text, $m)) {
            return $this->cleanLine($m[1]);
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function parseRedLines(string $text): ?array
    {
        if (! preg_match('/\*{0,2}\s*L[ií]neas?\s+Rojas?\s*:\s*(.+)/iu', $text, $m)) {
            return null;
        }

        $raw = $this->cleanLine($m[1]);

        if ($raw === '' || strtolower($raw) === 'ninguna' || strtolower($raw) === 'none') {
            return [];
        }

        // Split by comma, semicolon, " y ", " and "
        $items = preg_split('/\s*[,;]\s*|\s+(?:y|and)\s+/iu', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $items)));
    }

    /**
     * @return list<string>|null
     */
    private function parseYellowLines(string $text): ?array
    {
        // Matches "Líneas Amarillas:", "Lineas Amarillas:", "Yellow Lines:"
        if (! preg_match('/\*{0,2}\s*(?:L[ií]neas?\s+Amarillas?|Yellow\s+Lines?)\s*:\s*(.+)/iu', $text, $m)) {
            return null;
        }

        $raw = $this->cleanLine($m[1]);

        if ($raw === '' || strtolower($raw) === 'ninguna' || strtolower($raw) === 'none') {
            return [];
        }

        $items = preg_split('/\s*[,;]\s*|\s+(?:y|and)\s+/iu', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $items)));
    }

    /**
     * @return list<string>|null
     */
    private function parseAffinities(string $text): ?array
    {
        // Extract the "TUS AFINIDADES" section up to the next bold header or end
        if (! preg_match('/\*{0,2}TUS\s+AFINIDADES\*{0,2}\s*\n(.*?)(?=\n\s*\*{2}|\z)/isu', $text, $section)) {
            return null;
        }

        $block = $section[1];

        // Match numbered lines: "1. X", "1) X", "1- X"
        preg_match_all('/^\s*\d+\s*[.):-]\s*(.+)/mu', $block, $matches);

        if (empty($matches[1])) {
            return null;
        }

        return array_values(array_filter(array_map('trim', $matches[1])));
    }

    private function parseRawProfile(string $text): ?string
    {
        if (preg_match('/\*{0,2}ESTILO\s+NARRATIVO\*{0,2}\s*\n+(.*)/isu', $text, $m)) {
            $raw = trim($m[1]);

            return $raw !== '' ? $raw : null;
        }

        return null;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function cleanLine(string $value): string
    {
        // Strip trailing markdown markers, ellipsis artifacts and extra whitespace
        $clean = preg_replace('/[\*_]{1,2}$/', '', trim($value)) ?? $value;
        $clean = preg_replace('/\s*\.{2,}\s*$/', '', $clean) ?? $clean;

        return trim($clean);
    }

    private function normalizeExperience(string $value): string
    {
        $lower = mb_strtolower($value);

        if (str_contains($lower, 'master') || str_contains($lower, 'máster') || str_contains($lower, 'experto')) {
            return 'Master';
        }

        if (str_contains($lower, 'veteran') || str_contains($lower, 'veterano') || str_contains($lower, 'avanzado')) {
            return 'Veteran';
        }

        return 'Novice';
    }

    private function extractTimezone(string $schedule): string
    {
        // Match UTC±N or GMT±N or just a named zone at the end
        if (preg_match('/\b(UTC[+-]\d{1,2}(?::\d{2})?|GMT[+-]\d{1,2}(?::\d{2})?)\b/i', $schedule, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/\b([A-Z]{2,5})\s*$/', $schedule, $m)) {
            return $m[1];
        }

        return '';
    }
}
