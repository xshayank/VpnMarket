# Panel Assignment Regression Fix - Deployment Guide

## Overview
This PR fixes the regression introduced by migration `2025_11_17_203600_add_reseller_only_architecture_fields.php` where `panel_id` was renamed to `primary_panel_id`, breaking admin forms and causing SQL errors.

## Changes Summary

### Database Changes
1. **New Migration**: `2025_11_17_214935_add_panel_id_alias_to_resellers_table.php`
   - Adds `panel_id` column as backward-compatible alias
   - Backfills `panel_id` from `primary_panel_id` for existing records
   - Adds foreign key constraint and index

### Code Changes
1. **Reseller Model** (`app/Models/Reseller.php`)
   - Added `panelId()` accessor/mutator to sync `panel_id` ↔ `primary_panel_id`
   - Updated `panel()` relationship to use `primary_panel_id`
   - Added `hasPrimaryPanel()` method for access checks
   - Both fields now work interchangeably

2. **ResellerResource Form** (`app/Filament/Resources/ResellerResource.php`)
   - Changed form fields from `panel_id` to `primary_panel_id`
   - Updated all references to use `primary_panel_id` as canonical field
   - Updated relationship binding from `panel` to `primaryPanel`

3. **EditReseller Page** (`app/Filament/Resources/ResellerResource/Pages/EditReseller.php`)
   - Updated validation to use `primary_panel_id`
   - Added audit logging for panel changes

4. **CreateReseller Page** (`app/Filament/Resources/ResellerResource/Pages/CreateReseller.php`)
   - Updated validation to use `primary_panel_id`

5. **Backfill Command** (`app/Console/Commands/BackfillResellerPrimaryPanel.php`)
   - New artisan command to restore `primary_panel_id` from configs/meta
   - Supports dry-run mode for testing
   - Provides detailed summary of changes

### Tests
- Added comprehensive test suite (`tests/Feature/ResellerPanelAssignmentTest.php`)
- 11 tests covering:
  - Accessor/mutator functionality
  - Relationship integrity
  - Backfill command behavior
  - CRUD operations
- All tests passing ✅

## Deployment Steps

### Pre-Deployment Checklist
- [ ] Backup production database
- [ ] Review this guide with team
- [ ] Ensure maintenance window is scheduled
- [ ] Notify users of potential brief downtime

### Deployment Process

#### Step 1: Deploy Code
```bash
# Pull latest changes
git checkout copilot/fix-reseller-panel-linkage
git pull origin copilot/fix-reseller-panel-linkage

# Install/update dependencies (if needed)
composer install --optimize-autoloader --no-dev
```

#### Step 2: Run Migrations
```bash
# Run the new migration
php artisan migrate --force

# Expected output:
# INFO  Running migrations.
# 2025_11_17_214935_add_panel_id_alias_to_resellers_table .............. [timestamp]ms DONE
```

**What this does:**
- Adds `panel_id` column to `resellers` table
- Backfills existing records: `UPDATE resellers SET panel_id = primary_panel_id WHERE panel_id IS NULL`
- Adds index and foreign key constraint

#### Step 3: Run Backfill Command (Dry Run First)
```bash
# Test with dry-run to see what would be changed
php artisan resellers:backfill-primary-panel --dry

# Example output:
# 🔍 DRY RUN MODE - No changes will be made
# 
# Found 5 resellers without primary_panel_id
# 
# Processing Reseller #1 (User: user@example.com)
#   ✓ Found panel_id=3 from configs (most common)
# ...
# Summary:
# ┌─────────────┬───────┐
# │ Status      │ Count │
# ├─────────────┼───────┤
# │ Fixed       │ 3     │
# │ Skipped     │ 1     │
# │ Ambiguous   │ 1     │
# └─────────────┴───────┘
```

#### Step 4: Run Backfill Command (Live)
```bash
# Apply the changes
php artisan resellers:backfill-primary-panel

# Expected output:
# Found X resellers without primary_panel_id
# Processing Reseller #1...
#   ✓ Found panel_id=Y from configs
# ...
# Summary shows fixed/skipped/ambiguous counts
```

**Backfill Strategy (in order of preference):**
1. Existing `panel_id` column value (if present from old data)
2. Most common `panel_id` from reseller's configs
3. `panel_id` from reseller's meta field
4. Skip plan-based resellers (they may not need a panel)

#### Step 5: Clear Caches
```bash
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

#### Step 6: Verify Deployment
Test the following scenarios:

1. **Admin Edit Reseller Panel**
   - Go to Admin → Resellers
   - Edit a traffic-based or wallet-based reseller
   - Change the panel selection
   - Save successfully ✅ (No SQL errors)

2. **View Reseller Details**
   - Check that panel name displays correctly in table
   - Verify panel relationship works

3. **Create New Reseller**
   - Create a new traffic-based reseller with panel
   - Create a new wallet-based reseller with panel
   - Verify both save correctly

4. **Check Logs**
   ```bash
   tail -f storage/logs/laravel.log
   ```
   - Look for any SQL errors related to `panel_id`
   - Verify audit logs are created for panel changes

### Post-Deployment Monitoring

#### Monitor for Issues
```bash
# Watch for SQL errors
tail -f storage/logs/laravel.log | grep -i "panel_id\|sql"

# Check for "no-panel" warnings (if implemented)
tail -f storage/logs/laravel.log | grep -i "no.*panel"
```

#### Key Metrics to Monitor
- [ ] Admin panel edit operations complete successfully
- [ ] No SQL errors in logs
- [ ] Resellers can access their dashboards
- [ ] Panel assignment is visible in admin UI
- [ ] Audit logs show panel changes

## Rollback Procedure

If issues occur, follow these steps:

### Option 1: Code Rollback (Safest)
```bash
# The migration is backward-compatible, so just revert the code
git checkout main
composer install --optimize-autoloader --no-dev
php artisan optimize:clear
```

**Note**: The `panel_id` column will remain in the database (safe), and the old code can still reference `primary_panel_id`.

### Option 2: Full Rollback (Including Migration)
```bash
# Rollback the migration
php artisan migrate:rollback --step=1

# This will:
# - Drop the panel_id column
# - Drop the foreign key and index
# - Keep primary_panel_id intact

# Then revert code
git checkout main
composer install --optimize-autoloader --no-dev
php artisan optimize:clear
```

**Warning**: This will lose the `panel_id` column data. Only use if absolutely necessary.

## Troubleshooting

### Issue: SQL Error "Unknown column 'panel_id'"
**Cause**: Migration hasn't run yet.
**Solution**: 
```bash
php artisan migrate --force
```

### Issue: SQL Error "Column not found: 1054 Unknown column 'primary_panel_id'"
**Cause**: Running old code with the older migration.
**Solution**: 
```bash
# Deploy the new code first
git checkout copilot/fix-reseller-panel-linkage
composer install --optimize-autoloader --no-dev
php artisan optimize:clear
```

### Issue: Reseller shows "you don't have a panel"
**Cause**: `primary_panel_id` is NULL for existing reseller.
**Solution**:
```bash
# Run the backfill command
php artisan resellers:backfill-primary-panel

# If still NULL, manually set it in database or admin panel
```

### Issue: "Ambiguous" resellers in backfill command
**Cause**: No panel reference found in configs, meta, or old column.
**Solution**:
1. Check logs for reseller IDs
2. Manually assign panel via admin UI
3. Or set directly in database:
```sql
UPDATE resellers SET primary_panel_id = X, panel_id = X WHERE id = Y;
```

## Database Schema Reference

### Before (Old Schema)
```sql
CREATE TABLE resellers (
  id BIGINT UNSIGNED PRIMARY KEY,
  primary_panel_id BIGINT UNSIGNED NULL,
  -- other fields...
  FOREIGN KEY (primary_panel_id) REFERENCES panels(id)
);
```

### After (New Schema)
```sql
CREATE TABLE resellers (
  id BIGINT UNSIGNED PRIMARY KEY,
  panel_id BIGINT UNSIGNED NULL,           -- NEW: backward-compat alias
  primary_panel_id BIGINT UNSIGNED NULL,   -- canonical field
  -- other fields...
  FOREIGN KEY (panel_id) REFERENCES panels(id),
  FOREIGN KEY (primary_panel_id) REFERENCES panels(id),
  INDEX (panel_id),
  INDEX (primary_panel_id)
);
```

## API Compatibility

### Backward Compatibility
- ✅ Code reading `$reseller->panel_id` works (returns `primary_panel_id`)
- ✅ Code writing `$reseller->panel_id = X` works (updates both fields)
- ✅ `$reseller->panel` relationship works (uses `primary_panel_id`)
- ✅ Legacy code continues to function

### Forward Compatibility
- ✅ New code uses `primary_panel_id` as canonical
- ✅ Forms bind to `primary_panel_id`
- ✅ `$reseller->primaryPanel` relationship available
- ✅ `$reseller->hasPrimaryPanel()` method for checks

## Success Criteria

Deployment is successful when:
- [ ] All migrations complete without errors
- [ ] Backfill command completes successfully (or with only expected ambiguous cases)
- [ ] Admin can edit reseller panels without SQL errors
- [ ] Resellers can access their dashboards
- [ ] No SQL errors in logs related to `panel_id` or `primary_panel_id`
- [ ] All 11 new tests pass
- [ ] Audit logs show panel changes correctly

## Support

If you encounter issues not covered in this guide:
1. Check `storage/logs/laravel.log` for error details
2. Review the test suite for expected behavior
3. Consult the code changes in the PR
4. Contact the development team

## Additional Notes

### Why Both Columns?
- `primary_panel_id`: Canonical field, used by new code
- `panel_id`: Backward-compatible alias for legacy code
- Both are kept in sync via model accessor/mutator
- This approach allows gradual migration of legacy code

### Future Cleanup
After all legacy code is updated to use `primary_panel_id`:
1. Remove the `panel_id` accessor/mutator from model
2. Create migration to drop `panel_id` column
3. Update this document to reflect changes

This approach ensures zero-downtime deployment and maintains backward compatibility.
