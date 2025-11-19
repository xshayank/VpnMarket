# Config Naming System Implementation

## Overview

This implementation adds a new human-readable naming pattern for reseller configs: `FP_{PT}_{RSL}_{MODE}_{SEQ}_{H5}`

## Pattern Breakdown

- `FP` - Configurable prefix (default: FastPanel)
- `{PT}` - Panel type code (EY=Eylandoo, MN=Marzneshin, MB=Marzban, XU=XUI)
- `{RSL}` - Reseller short code (base36 encoded reseller ID, padded to 3 chars)
- `{MODE}` - Mode code (W=Wallet, T=Traffic)
- `{SEQ}` - Sequential number (4 digits, padded with zeros)
- `{H5}` - 5-character hash for uniqueness

### Example Names
- `FP_EY_001_W_0042_abc12` - Eylandoo wallet reseller, 42nd config
- `FP_MN_00f_T_0123_xyz89` - Marzneshin traffic reseller, 123rd config

**Note:** Underscores are used instead of hyphens for compatibility with Marzneshin and other panel types.

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

// Returns: ['name' => 'FP_EY_001_W_0042_abc12', 'version' => 2]
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

#### ConfigController (Reseller UI)
**File**: `Modules/Reseller/Http/Controllers/ConfigController.php`

**Implementation**:
```php
use App\Services\ConfigNameGenerator;

// In store() method:
if ($customName) {
    // Custom name overrides everything
    $username = $customName;
    $nameVersion = null;
} else {
    // Use ConfigNameGenerator
    $generator = new ConfigNameGenerator();
    $nameData = $generator->generate($reseller, $panel, $reseller->type);
    $username = $nameData['name'];
    $nameVersion = $nameData['version'];
}

$config = ResellerConfig::create([
    'external_username' => $username,
    'name_version' => $nameVersion,
    // ... other fields
]);
```

**Status**: ✅ INTEGRATED

#### AttachPanelConfigsToReseller (Admin Bulk Import)
**File**: `app/Filament/Pages/AttachPanelConfigsToReseller.php`

**Implementation**:
```php
// Imported configs from panels are legacy (not V2 generated)
$config = ResellerConfig::create([
    'external_username' => $remoteUsername,
    'name_version' => null, // Legacy import
    // ... other fields
]);
```

**Status**: ✅ INTEGRATED

#### ResellerProvisioner (Legacy Fallback)
**File**: `Modules/Reseller/Services/ResellerProvisioner.php`

**Note**: The `generateUsername()` method remains unchanged as a fallback for:
- Order-based naming (uses index parameter)
- Legacy systems that call it directly
- Custom prefix/name handling

ConfigController now calls ConfigNameGenerator directly instead of using this method.

**Status**: ✅ NO CHANGES NEEDED (used only for orders)

### Files Modified

1. ✅ `Modules/Reseller/Http/Controllers/ConfigController.php` - Integrated ConfigNameGenerator
2. ✅ `app/Filament/Pages/AttachPanelConfigsToReseller.php` - Set name_version=null for imports
3. ✅ `.env.example` - Added CONFIG_NAME_V2_ENABLED and CONFIG_NAME_PREFIX
4. ✅ Tests added: `tests/Feature/ConfigControllerNamingTest.php`

### Existing Files (No Changes Needed)

- `app/Services/ConfigNameGenerator.php` - Already implemented
- `app/Models/ConfigNameSequence.php` - Already created
- `app/Models/Reseller.php` - short_code already in fillable
- `app/Models/ResellerConfig.php` - name_version already in fillable
- `app/Console/Commands/BackfillResellerShortCodes.php` - Already implemented
- Database migrations - Already created
- `config/config_names.php` - Already configured
- `tests/Unit/ConfigNameGeneratorTest.php` - 15 tests, all passing
- `tests/Feature/ConfigNamingSystemTest.php` - 7 tests, all passing

### Integration Status

| Component | Status | Notes |
|-----------|--------|-------|
| ConfigNameGenerator Service | ✅ Complete | Fully implemented with tests |
| Database Migrations | ✅ Complete | 3 migrations created |
| Config File | ✅ Complete | config/config_names.php |
| Backfill Command | ✅ Complete | php artisan configs:backfill-short-codes |
| ConfigController Integration | ✅ Complete | Uses ConfigNameGenerator |
| Admin Bulk Import | ✅ Complete | Sets name_version=null for imports |
| Environment Configuration | ✅ Complete | Added to .env.example |
| Unit Tests | ✅ Complete | 15 tests passing |
| Feature Tests | ✅ Complete | 12 tests passing (7 + 5 new) |
| Documentation | ✅ Complete | Updated with activation steps |
| UI Updates | ⏭️ Optional | Badges for legacy/V2 configs |

## Activation Checklist

- [x] ConfigNameGenerator service implemented
- [x] Database migrations created
- [x] Config file created
- [x] Backfill command created
- [x] ConfigController integrated
- [x] Admin bulk import updated
- [x] Environment variables documented
- [x] Unit tests passing (15/15)
- [x] Feature tests passing (12/12)
- [x] Documentation updated
- [ ] UI badges/components (optional enhancement)
- [ ] Production deployment
- [ ] Feature flag enabled in production

## Next Steps

1. **Deploy to Staging**: Deploy code with flag OFF
2. **Run Migrations**: `php artisan migrate`
3. **Backfill**: `php artisan configs:backfill-short-codes`
4. **Enable Flag**: Set `CONFIG_NAME_V2_ENABLED=true`
5. **Test**: Create configs and verify V2 pattern
6. **Monitor**: Check logs for `config_name_generated` events
7. **Production**: Repeat steps 1-6 in production

### UI Files to Update (Optional Enhancement)

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
        "name": "FP_EY_00f_W_0123_abc45",
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
1. Deploy migrations (already included in codebase)
2. Run migrations: `php artisan migrate`
3. Run backfill command: `php artisan configs:backfill-short-codes`
4. Verify all resellers have short_code: `SELECT COUNT(*) FROM resellers WHERE short_code IS NULL;` should return 0

### Phase 2: Staging Testing
1. Enable feature flag in `.env`: `CONFIG_NAME_V2_ENABLED=true`
2. Clear config cache: `php artisan config:clear`
3. Create test configs via UI or API
4. Verify name format matches pattern: `FP_{PT}_{RSL}_{MODE}_{SEQ}_{H5}`
5. Check logs for `config_name_generated` events
6. Verify `name_version=2` in database
7. Test custom name override (should set `name_version=null`)

### Phase 3: Production Rollout
1. Deploy to production with feature flag OFF (`CONFIG_NAME_V2_ENABLED=false`)
2. Run migrations: `php artisan migrate --force`
3. Run backfill command: `php artisan configs:backfill-short-codes`
4. Verify all resellers have short_code
5. Monitor for 24-48 hours
6. Enable feature flag: Set `CONFIG_NAME_V2_ENABLED=true` in `.env`
7. Clear config cache: `php artisan config:clear`
8. Monitor logs for `config_name_generated` events
9. Verify new configs have V2 pattern
10. Verify legacy configs remain unchanged

### Phase 4: Validation
1. Check sequence table growth: `SELECT * FROM config_name_sequences ORDER BY updated_at DESC;`
2. Verify no duplicate names: `SELECT external_username, COUNT(*) FROM reseller_configs GROUP BY external_username HAVING COUNT(*) > 1;`
3. Confirm legacy configs still work (name_version=NULL)
4. Test custom name path (name_version=NULL)
5. Verify sequence increments sequentially
6. Check collision retry logs (should be rare/none)
7. Gather user feedback

## Activation Steps (Quick Reference)

### Development/Staging
```bash
# 1. Run migrations
php artisan migrate

# 2. Backfill short codes
php artisan configs:backfill-short-codes

# 3. Enable V2 naming
echo "CONFIG_NAME_V2_ENABLED=true" >> .env
php artisan config:clear

# 4. Verify
php artisan test --filter=ConfigName
```

### Production
```bash
# 1. Deploy code (flag OFF by default)
git pull origin main

# 2. Run migrations
php artisan migrate --force

# 3. Backfill short codes
php artisan configs:backfill-short-codes

# 4. Wait 24-48 hours, then enable
nano .env  # Set CONFIG_NAME_V2_ENABLED=true
php artisan config:clear

# 5. Monitor logs
tail -f storage/logs/laravel.log | grep config_name
```

## Troubleshooting

### Issue: Short code not generated
**Symptom**: Error when creating config with V2 naming enabled
**Solution**: 
- Run backfill command: `php artisan configs:backfill-short-codes`
- Or create config (auto-generates on first use)
- Verify reseller has short_code: `SELECT short_code FROM resellers WHERE id = ?;`

### Issue: Name collision error
**Symptom**: Config creation fails with collision error
**Check**: 
- Unique constraint exists: `SHOW INDEXES FROM reseller_configs WHERE Column_name = 'external_username';`
- Sequence table has proper unique index: `SHOW INDEXES FROM config_name_sequences;`
- Review collision retry logs: `grep config_name_collision_retry storage/logs/laravel.log`
- Check for duplicate usernames: `SELECT external_username, COUNT(*) FROM reseller_configs GROUP BY external_username HAVING COUNT(*) > 1;`

### Issue: Feature flag not working
**Symptom**: Still generating legacy names even with flag ON
**Check**:
- `.env` file has `CONFIG_NAME_V2_ENABLED=true`
- Config cache cleared: `php artisan config:clear`
- Verify config value: `php artisan tinker` then `config('config_names.enabled')`
- Check for typos in .env file

### Issue: Tests failing with "Unknown format 'name'"
**Symptom**: Faker locale errors in tests
**Solution**: 
- Change `APP_FAKER_LOCALE=en_US` in phpunit.xml or .env.testing
- Or use `APP_FAKER_LOCALE=fa_IR` for Persian locale (default)

### Issue: Custom names not working
**Symptom**: Custom name is being transformed or V2 pattern applied
**Check**:
- User has permission: `configs.set_custom_name`
- custom_name field is provided in request
- Verify name_version is NULL for custom names
- Check ConfigController logic for custom_name handling

### Issue: Sequence not incrementing
**Symptom**: Multiple configs have same sequence number
**Check**:
- Database transaction isolation level
- config_name_sequences table has unique constraint
- No deadlocks in database logs
- Concurrent config creation handling

### Issue: Legacy configs showing incorrectly
**Symptom**: Old configs display errors or wrong data
**Check**:
- name_version column exists: `DESCRIBE reseller_configs;`
- Legacy configs have NULL name_version
- UI correctly handles NULL name_version (shows "Legacy" badge)
- parseName returns null for legacy configs

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
