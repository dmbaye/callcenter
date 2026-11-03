<?php

namespace App\Services;

use App\Enums\ContactStatus;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SplFileObject;

/**
 * CSV lead loader (comma or semicolon, UTF-8 or Excel's UTF-8 with BOM).
 * Columns are recognised by name in French or English; any other column is
 * kept on the contact and usable in the script as {column_name}.
 * Duplicates (same number in the campaign), invalid numbers and numbers on
 * the team's Do-Not-Call list are skipped and counted.
 */
class CampaignContactsImporter
{
    public const MAX_ROWS = 100_000;

    private const CHUNK = 500;

    /** Normalised header → contact field. */
    private const ALIASES = [
        'phone' => [
            'phone',
            'telephone',
            'tel',
            'numero',
            'numero_de_telephone',
            'mobile',
            'portable',
            'msisdn',
            'number',
            'phone_number',
            'contact',
        ],
        'first_name' => ['first_name', 'firstname', 'prenom', 'prenoms'],
        'last_name' => ['last_name', 'lastname', 'nom', 'nom_de_famille', 'surname'],
        'full_name' => ['name', 'full_name', 'nom_complet', 'client'],
        'company' => ['company', 'entreprise', 'societe', 'organisation', 'organization', 'structure'],
        'email' => ['email', 'e_mail', 'mail', 'courriel'],
        'priority' => ['priority', 'priorite'],
    ];

    /**
     * @return array{imported: int, duplicates: int, invalid: int, dnc: int, total: int}
     */
    public function import(Campaign $campaign, string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $headerLine = (string) $file->fgets();
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine) ?? $headerLine;
        $delimiter = substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';

        $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), str_getcsv(trim($headerLine), $delimiter, '"', ''));
        $map = $this->mapHeaders($headers);

        if (! isset($map['phone'])) {
            throw ValidationException::withMessages([
                'file' => __('No phone column found. Name it "telephone", "phone" or "numero".'),
            ]);
        }

        $stats = ['imported' => 0, 'duplicates' => 0, 'invalid' => 0, 'dnc' => 0, 'total' => 0];
        $batch = [];

        // Read on from the line after the header (a foreach would rewind to it).
        while (! $file->eof()) {
            $row = $file->fgetcsv($delimiter, '"', '');

            if (! is_array($row) || $row === [null] || $row === [] || implode('', array_map('strval', $row)) === '') {
                continue;
            }

            if (++$stats['total'] > self::MAX_ROWS) {
                $stats['total']--;
                break;
            }

            $contact = $this->contactFrom($row, $headers, $map);

            if ($contact === null) {
                $stats['invalid']++;

                continue;
            }

            $batch[$contact['phone']] = $contact;

            if (count($batch) >= self::CHUNK) {
                $this->flush($campaign, $batch, $stats);
                $batch = [];
            }
        }

        $this->flush($campaign, $batch, $stats);
        $stats['duplicates'] = $stats['total'] - $stats['imported'] - $stats['invalid'] - $stats['dnc'];

        return $stats;
    }

    /**
     * @param  array<string, array<string, mixed>>  $batch  keyed by phone
     * @param  array{imported: int, duplicates: int, invalid: int, dnc: int, total: int}  $stats
     */
    private function flush(Campaign $campaign, array $batch, array &$stats): void
    {
        if ($batch === []) {
            return;
        }

        $blocked = $campaign->team->dncNumbers()->whereIn('phone', array_keys($batch))->pluck('phone')->all();
        $stats['dnc'] += count($blocked);
        $rows = array_values(array_diff_key($batch, array_flip($blocked)));

        if ($rows === []) {
            return;
        }

        $now = now();
        $stats['imported'] += DB::table('campaign_contacts')->insertOrIgnore(array_map(fn (array $row) => [
            ...$row,
            'data' => $row['data'] === [] ? null : json_encode($row['data'], JSON_UNESCAPED_UNICODE),
            'campaign_id' => $campaign->id,
            'status' => ContactStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows));
    }

    /**
     * @param  list<string|null>  $row
     * @param  list<string>  $headers
     * @param  array<string, int>  $map
     * @return array<string, mixed>|null
     */
    private function contactFrom(array $row, array $headers, array $map): ?array
    {
        $value = fn (string $field): ?string => isset($map[$field]) && isset($row[$map[$field]])
            ? (trim((string) $row[$map[$field]]) ?: null)
            : null;

        $phone = PhoneNumbers::normalize($value('phone'));

        if ($phone === null) {
            return null;
        }

        $first = $value('first_name');
        $last = $value('last_name');

        if ($first === null && $last === null && ($full = $value('full_name')) !== null) {
            [$first, $last] = array_pad(explode(' ', $full, 2), 2, null);
        }

        $email = $value('email');
        $data = [];

        foreach ($headers as $index => $header) {
            if ($header !== '' && ! in_array($index, $map, true) && isset($row[$index]) && trim((string) $row[$index]) !== '') {
                $data[$header] = Str::limit(trim((string) $row[$index]), 500, '');
            }
        }

        return [
            'phone' => $phone,
            'first_name' => $first ? Str::limit($first, 80, '') : null,
            'last_name' => $last ? Str::limit($last, 80, '') : null,
            'company' => ($company = $value('company')) ? Str::limit($company, 120, '') : null,
            'email' => $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? Str::limit($email, 160, '') : null,
            'priority' => max(0, min(100, (int) ($value('priority') ?? 0))),
            'data' => $data,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int> field → column index
     */
    private function mapHeaders(array $headers): array
    {
        $map = [];

        foreach (self::ALIASES as $field => $aliases) {
            foreach ($headers as $index => $header) {
                if (in_array($header, $aliases, true) && ! in_array($index, $map, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(trim($header)))), '_');
    }
}
