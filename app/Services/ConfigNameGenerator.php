<?php

namespace App\Services;

use App\Models\ConfigNameSequence;
use App\Models\Panel;
use App\Models\Reseller;
use App\Models\ResellerConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfigNameGenerator
{
    /**
     * Generate a unique config name for the given reseller, panel, and mode.
     *
     * @param Reseller $reseller The reseller who owns the config
     * @param Panel $panel The panel where the config will be created
     * @param string $mode The reseller mode ('wallet' or 'traffic')
     * @return array ['name' => string, 'version' => int]
     * @throws \Exception If unable to generate a unique name after retries
     */
    public function generate(Reseller $reseller, Panel $panel, string $mode): array
    {
        // Check if new naming system is enabled
        if (!config('config_names.enabled', false)) {
            return $this->generateLegacyName($reseller, $panel);
        }

        $retryLimit = config('config_names.collision_retry_limit', 3);
        $lastException = null;

        for ($attempt = 1; $attempt <= $retryLimit; $attempt++) {
            try {
                return DB::transaction(function () use ($reseller, $panel, $mode, $attempt) {
                    // Lock and get/create sequence record
                    $sequence = ConfigNameSequence::lockForUpdate()
                        ->firstOrCreate(
                            [
                                'reseller_id' => $reseller->id,
                                'panel_id' => $panel->id,
                            ],
                            ['next_seq' => 1]
                        );

                    // Get current sequence number and increment
                    $seq = $sequence->next_seq;
                    $sequence->increment('next_seq');

                    // Build the name
                    $name = $this->buildName($reseller, $panel, $mode, $seq);

                    // Check if name is unique (shouldn't happen, but safety check)
                    if (ResellerConfig::where('external_username', $name)->exists()) {
                        Log::warning('config_name_collision_retry', [
                            'attempt' => $attempt,
                            'reseller_id' => $reseller->id,
                            'panel_id' => $panel->id,
                            'name' => $name,
                            'seq' => $seq,
                        ]);
                        
                        throw new \RuntimeException("Config name collision detected: {$name}");
                    }

                    Log::info('config_name_seq_allocated', [
                        'reseller_id' => $reseller->id,
                        'panel_id' => $panel->id,
                        'seq' => $seq,
                    ]);

                    Log::info('config_name_generated', [
                        'reseller_id' => $reseller->id,
                        'panel_id' => $panel->id,
                        'name' => $name,
                        'mode' => $mode,
                        'seq' => $seq,
                    ]);

                    return [
                        'name' => $name,
                        'version' => 2,
                    ];
                });
            } catch (\Exception $e) {
                $lastException = $e;
                
                if ($attempt < $retryLimit) {
                    // Small delay before retry
                    usleep(100000); // 100ms
                    continue;
                }
            }
        }

        // All retries exhausted
        Log::error('config_name_generation_failed', [
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'error' => $lastException ? $lastException->getMessage() : 'Unknown error',
        ]);

        throw new \Exception(
            "Failed to generate unique config name after {$retryLimit} attempts: " . 
            ($lastException ? $lastException->getMessage() : 'Unknown error')
        );
    }

    /**
     * Build the config name using the pattern: FP-{PT}-{RSL}-{MODE}-{SEQ}-{H5}
     *
     * @param Reseller $reseller
     * @param Panel $panel
     * @param string $mode
     * @param int $seq
     * @return string
     */
    protected function buildName(Reseller $reseller, Panel $panel, string $mode, int $seq): string
    {
        // Get prefix (default: FP)
        $prefix = config('config_names.prefix', 'FP');

        // Get panel type code
        $panelType = strtolower($panel->panel_type ?? 'xui');
        $panelCode = config("config_names.panel_types.{$panelType}", 'XX');

        // Get reseller short code (ensure it exists)
        $resellerCode = $this->ensureResellerShortCode($reseller);

        // Get mode code
        $modeCode = config("config_names.mode_codes.{$mode}", 'U'); // U = Unknown

        // Format sequence with padding
        $seqPadded = str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        // Generate hash suffix (5 chars)
        // Use a combination of reseller_id, panel_id, and seq for uniqueness
        $hashInput = "{$reseller->id}-{$panel->id}-{$seq}-" . microtime(true);
        $hash = substr(hash('sha256', $hashInput), 0, 5);

        // Build final name
        return "{$prefix}-{$panelCode}-{$resellerCode}-{$modeCode}-{$seqPadded}-{$hash}";
    }

    /**
     * Ensure reseller has a short_code, generate if missing
     *
     * @param Reseller $reseller
     * @return string
     */
    protected function ensureResellerShortCode(Reseller $reseller): string
    {
        if (empty($reseller->short_code)) {
            // Generate short_code using base36 encoding
            $shortCode = $this->generateShortCode($reseller->id);
            
            // Update reseller with short_code
            $reseller->update(['short_code' => $shortCode]);
            
            Log::info('reseller_short_code_generated', [
                'reseller_id' => $reseller->id,
                'short_code' => $shortCode,
            ]);
            
            return $shortCode;
        }

        return $reseller->short_code;
    }

    /**
     * Generate a short code from reseller ID using base36
     *
     * @param int $resellerId
     * @return string
     */
    protected function generateShortCode(int $resellerId): string
    {
        // Convert to base36 (0-9, a-z)
        $base36 = strtolower(base_convert((string)$resellerId, 10, 36));
        
        // Pad to at least 3 characters
        return str_pad($base36, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a legacy config name (fallback when feature is disabled)
     *
     * @param Reseller $reseller
     * @param Panel $panel
     * @return array ['name' => string, 'version' => null]
     */
    protected function generateLegacyName(Reseller $reseller, Panel $panel): array
    {
        // Generate a unique legacy name
        // Use reseller prefix if available, otherwise use reseller ID
        $prefix = $reseller->username_prefix ?? "R{$reseller->id}";
        
        // Generate unique suffix using timestamp and random string
        $timestamp = now()->format('YmdHis');
        $random = substr(md5(uniqid()), 0, 6);
        
        $name = "{$prefix}_{$timestamp}_{$random}";

        Log::info('config_name_legacy_generated', [
            'reseller_id' => $reseller->id,
            'panel_id' => $panel->id,
            'name' => $name,
        ]);

        return [
            'name' => $name,
            'version' => null, // NULL indicates legacy
        ];
    }

    /**
     * Parse a config name into its components (for display purposes)
     *
     * @param string $name
     * @param int|null $version
     * @return array|null Array of components or null if not parseable
     */
    public static function parseName(string $name, ?int $version): ?array
    {
        if ($version !== 2) {
            return null; // Legacy name, not parseable
        }

        // Pattern: FP-{PT}-{RSL}-{MODE}-{SEQ}-{H5}
        $pattern = '/^([A-Z]{2,})-([A-Z]{2})-([a-z0-9]+)-([A-Z])-(\d+)-([a-z0-9]{5})$/';
        
        if (preg_match($pattern, $name, $matches)) {
            return [
                'prefix' => $matches[1],
                'panel_type' => $matches[2],
                'reseller_code' => $matches[3],
                'mode' => $matches[4],
                'sequence' => (int)$matches[5],
                'hash' => $matches[6],
            ];
        }

        return null;
    }
}
