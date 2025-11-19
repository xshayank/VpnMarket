# Config Naming System Implementation

## Overview

This implementation adds a new human-readable naming pattern for reseller configs: `FP-{PT}-{RSL}-{MODE}-{SEQ}-{H5}`

## Pattern Breakdown

- `FP` - Configurable prefix (default: FastPanel)
- `{PT}` - Panel type code (EY=Eylandoo, MN=Marzneshin, MB=Marzban, XU=XUI)
- `{RSL}` - Reseller short code (base36 encoded reseller ID, padded to 3 chars)
- `{MODE}` - Mode code (W=Wallet, T=Traffic)
- `{SEQ}` - Sequential number (4 digits, padded with zeros)
- `{H5}` - 5-character hash for uniqueness

### Example Names
- `FP-EY-001-W-0042-abc12` - Eylandoo wallet reseller, 42nd config
- `FP-MN-00f-T-0123-xyz89` - Marzneshin traffic reseller, 123rd config

## Features

### Backward Compatibility
- Existing config names are retained (name_version=NULL for legacy configs)
- New configs use version 2 pattern (name_version=2)
- System can operate in both modes simultaneously

### Uniqueness Guarantee
- Per-reseller-panel sequence tracking in `config_name_sequences` table
- Transaction-based locking prevents race conditions
- 5-character hash suffix adds additional uniqueness layer
- Collision retry mechanism (up to 3 attempts)

### Feature Flag
- Enable/disable via `CONFIG_NAME_V2_ENABLED` environment variable
- When disabled, uses legacy naming convention
- Default: disabled (opt-in)

## Database Schema

### New Table: config_name_sequences
```sql
CREATE TABLE config_name_sequences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reseller_id BIGINT UNSIGNED NOT NULL,
    panel_id BIGINT UNSIGNED NOT NULL,
    next_seq INT UNSIGNED DEFAULT 1,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE KEY (reseller_id, panel_id),
    INDEX (reseller_id),
    INDEX (panel_id),
    FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,
    FOREIGN KEY (panel_id) REFERENCES panels(id) ON DELETE CASCADE
);
```

### Modified Table: resellers
```sql
ALTER TABLE resellers 
ADD COLUMN short_code VARCHAR(8) NULL,
ADD INDEX (short_code);
```

### Modified Table: reseller_configs
```sql
ALTER TABLE reseller_configs 
ADD COLUMN name_version TINYINT NULL,
ADD UNIQUE KEY (external_username);
```

## Configuration

### config/config_names.php
```php
return [
    'enabled' => env('CONFIG_NAME_V2_ENABLED', false),
    'prefix' => env('CONFIG_NAME_PREFIX', 'FP'),
    'panel_types' => [
        'eylandoo' => 'EY',
        'marzneshin' => 'MN',
        'marzban' => 'MB',
        'xui' => 'XU',
    ],
    'mode_codes' => [
        'wallet' => 'W',
        'traffic' => 'T',
    ],
    'collision_retry_limit' => 3,
];
```

### Environment Variables
```bash
# Enable new naming system
CONFIG_NAME_V2_ENABLED=true

# Custom prefix (optional)
CONFIG_NAME_PREFIX=FP
```

## Service Layer

### ConfigNameGenerator

The `App\Services\ConfigNameGenerator` service handles all name generation:

```php
use App\Services\ConfigNameGenerator;

$generator = new ConfigNameGenerator();
$result = $generator->generate($reseller, $panel, $mode);

// Returns: ['name' => 'FP-EY-001-W-0042-abc12', 'version' => 2]
```

#### Key Methods

- `generate(Reseller $reseller, Panel $panel, string $mode): array`
  - Main generation method
  - Returns array with 'name' and 'version' keys
  - Handles legacy fallback automatically

- `parseName(string $name, ?int $version): ?array`
  - Static method to parse config name into components
  - Returns null for legacy names or invalid formats

## Artisan Commands

### Backfill Reseller Short Codes
```bash
php artisan configs:backfill-short-codes
```

Generates short_code for all resellers that don't have one. Should be run once before enabling the new naming system in production.

## Integration Points

### Config Creation Flow

1. **Before** (Legacy):
```php
$config = ResellerConfig::create([
    'external_username' => $generatedUsername,
    // ...
]);
```

2. **After** (New Pattern):
```php
$generator = new ConfigNameGenerator();
$nameData = $generator->generate($reseller, $panel, $reseller->type);

$config = ResellerConfig::create([
    'external_username' => $nameData['name'],
    'name_version' => $nameData['version'],
    // ...
]);
```

### Files to Update

1. `Modules/Reseller/Http/Controllers/ConfigController.php` - Config creation endpoint
2. `app/Filament/Pages/AttachPanelConfigsToReseller.php` - Admin bulk import
3. Any other places where configs are programmatically created

## UI Enhancements

### Legacy Badge
Configs with `name_version=NULL` should display a "Legacy" badge:

```blade
@if(is_null($config->name_version))
    <span class="badge badge-secondary">Legacy</span>
@endif
```

### Parsed Name Display
For version 2 configs, display parsed components as chips:

```blade
@if($config->name_version === 2)
    @php
        $parsed = \App\Services\ConfigNameGenerator::parseName(
            $config->external_username, 
            $config->name_version
        );
    @endphp
    
    @if($parsed)
        <div class="flex gap-1">
            <span class="badge badge-info">{{ $parsed['panel_type'] }}</span>
            <span class="badge badge-primary">Seq: {{ $parsed['sequence'] }}</span>
            <span class="badge badge-success">{{ $parsed['mode'] === 'W' ? 'Wallet' : 'Traffic' }}</span>
        </div>
    @endif
@endif
```

## Logging

The service emits structured logs for monitoring:

- `config_name_seq_allocated` - Sequence number allocated
- `config_name_generated` - Config name successfully generated
- `config_name_legacy_generated` - Legacy name generated (feature disabled)
- `config_name_collision_retry` - Collision detected, retrying
- `config_name_generation_failed` - Failed after all retries
- `reseller_short_code_generated` - Short code auto-generated for reseller

Example:
```json
{
    "level": "info",
    "message": "config_name_generated",
    "context": {
        "reseller_id": 42,
        "panel_id": 3,
        "name": "FP-EY-00f-W-0123-abc45",
        "mode": "wallet",
        "seq": 123
    }
}
```

## Testing

### Unit Tests (15 tests)
Located in `tests/Unit/ConfigNameGeneratorTest.php`:
- Name generation with feature enabled/disabled
- Panel type and mode code mapping
- Sequence increment logic
- Reseller short_code auto-generation
- Name parsing
- Concurrency handling

### Feature Tests (7 tests)
Located in `tests/Feature/ConfigNamingSystemTest.php`:
- Config creation flow with new naming
- Legacy naming fallback
- Sequential config creation
- Backfill command
- Uniqueness constraints

### Running Tests
```bash
# All naming system tests
php artisan test --filter=ConfigName

# Just unit tests
php artisan test --filter=ConfigNameGeneratorTest

# Just feature tests
php artisan test --filter=ConfigNamingSystemTest
```

## Rollout Plan

### Phase 1: Preparation (Staging)
1. Deploy migrations
2. Run backfill command: `php artisan configs:backfill-short-codes`
3. Verify all resellers have short_code

### Phase 2: Staging Testing
1. Enable feature flag: `CONFIG_NAME_V2_ENABLED=true`
2. Create test configs
3. Verify name format and uniqueness
4. Monitor logs for any issues

### Phase 3: Production Rollout
1. Deploy to production (feature flag OFF)
2. Run backfill command
3. Monitor for 24 hours
4. Enable feature flag: `CONFIG_NAME_V2_ENABLED=true`
5. Monitor logs and config creation

### Phase 4: Validation
1. Check sequence table growth
2. Verify no duplicate names
3. Confirm legacy configs still work
4. Gather user feedback

## Troubleshooting

### Issue: Short code not generated
**Solution**: Run backfill command or create config (auto-generates on first use)

### Issue: Name collision error
**Check**: 
- Unique constraint on `reseller_configs.external_username`
- Sequence table has proper unique index
- Review `config_name_collision_retry` logs

### Issue: Feature flag not working
**Check**:
- `.env` file has `CONFIG_NAME_V2_ENABLED=true`
- Config cache cleared: `php artisan config:clear`

### Issue: Tests failing with "Unknown format 'name'"
**Solution**: Faker locale issue - change `APP_FAKER_LOCALE` to `en_US` for tests

## Performance Considerations

- Sequence lookup is indexed (reseller_id, panel_id)
- Transaction locks are brief (milliseconds)
- Hash generation is lightweight (SHA256 truncated)
- No impact on existing config queries

## Security Considerations

- Short codes reveal reseller IDs (by design, for support purposes)
- Sequential numbers expose creation order (acceptable for internal use)
- Hash prevents trivial guessing of valid names
- Unique constraint prevents name collision attacks

## Future Enhancements

- Analytics dashboard grouping by prefix components
- Bulk filter by panel code or mode code
- Admin UI to preview next N names for reseller/panel
- Custom naming templates per reseller
- Name format versioning support (v3, v4, etc.)

## References

- Issue: Rework Config Naming System
- PR: #[number]
- Migration files: `database/migrations/2025_11_19_*`
- Service: `app/Services/ConfigNameGenerator.php`
- Tests: `tests/Unit/ConfigNameGeneratorTest.php`, `tests/Feature/ConfigNamingSystemTest.php`
