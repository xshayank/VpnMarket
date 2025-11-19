# Multi-Panel Reseller Access Implementation

## Overview

This implementation allows resellers to access multiple V2Ray panels (both Eylandoo and Marzneshin) simultaneously. During registration, resellers no longer need to select a panel - they automatically get access to all panels marked with `auto_assign_to_resellers=true`.

## Database Changes

### New Tables

1. **reseller_panel_access** - Pivot table for many-to-many relationship
   - `id` - Primary key
   - `reseller_id` - Foreign key to resellers table
   - `panel_id` - Foreign key to panels table
   - `allowed_node_ids` - JSON array of allowed node IDs (for Eylandoo)
   - `allowed_service_ids` - JSON array of allowed service IDs (for Marzneshin)
   - `timestamps`
   - Unique constraint on `(reseller_id, panel_id)`

### Modified Tables

1. **panels** table
   - Added `auto_assign_to_resellers` boolean column (default: false)
   - When enabled, panel is automatically assigned to all existing and new resellers

## Code Changes

### Models

1. **Reseller Model** (`app/Models/Reseller.php`)
   - Added `panels()` BelongsToMany relationship
   - Added `hasPanelAccess(int $panelId): bool` helper method
   - Added `panelAccess(int $panelId)` helper to get pivot data

2. **Panel Model** (`app/Models/Panel.php`)
   - Added `auto_assign_to_resellers` to fillable and casts
   - Added `scopeAutoAssign()` query scope

### Controllers

1. **RegisteredUserController** (`app/Http/Controllers/Auth/RegisteredUserController.php`)
   - Removed panel selection from registration form
   - Auto-assigns all panels with `auto_assign_to_resellers=true` to new resellers
   - Uses panel's `getRegistrationDefaultNodeIds()` and `getRegistrationDefaultServiceIds()` for defaults

2. **ConfigController** (`Modules/Reseller/Http/Controllers/ConfigController.php`)
   - Modified `create()` to fetch panels from reseller's pivot table
   - Modified `store()` to validate panel access via `hasPanelAccess()`
   - Uses pivot data for node/service validation instead of reseller's direct fields

### Admin UI

1. **PanelResource** (`app/Filament/Resources/PanelResource.php`)
   - Added `auto_assign_to_resellers` toggle field

2. **ManagePanels Page** (`app/Filament/Resources/PanelResource/Pages/ManagePanels.php`)
   - Added `assignPanelToAllResellers()` method
   - Hooks into create and save actions to auto-assign when toggle is enabled

### Views

1. **Registration View** (`resources/views/auth/register.blade.php`)
   - Removed panel selection dropdown
   - Removed node/service selection fields
   - Simplified JavaScript to remove panel-related logic

2. **Landing Page** (`resources/views/landing/index.blade.php`)
   - Updated messaging to highlight "Both protocols under one roof"
   - Changed step 2 from "Select Panel" to "Access All Panels"

### Commands

1. **AssignPanelsToResellers** (`app/Console/Commands/AssignPanelsToResellers.php`)
   - New artisan command for bulk panel assignment
   - Supports multiple flags:
     - `--all-panels` - Assign all panels with auto_assign_to_resellers=true
     - `--panel_id=X` - Assign specific panel(s) (repeatable)
     - `--reseller_id=X` - Assign to specific reseller(s) (repeatable)
   - Idempotent operation (safe to run multiple times)

## Deployment Steps

### 1. Backup Database
```bash
# Create a backup before migrating
mysqldump -u user -p database_name > backup_before_multi_panel.sql
```

### 2. Run Migrations
```bash
php artisan migrate
```

This will create:
- `reseller_panel_access` table
- `auto_assign_to_resellers` column in `panels` table

### 3. Enable Auto-Assign on Panels (Optional)

Via Tinker:
```bash
php artisan tinker
```

```php
// Enable auto-assign on all active panels
Panel::where('is_active', true)->update(['auto_assign_to_resellers' => true]);

// Or enable for specific panels
Panel::whereIn('id', [1, 2, 3])->update(['auto_assign_to_resellers' => true]);
```

Via Admin UI:
1. Go to Admin → V2Ray Panels
2. Edit each panel
3. Enable "Auto-assign to resellers" toggle
4. Save

### 4. Assign Panels to Existing Resellers

Use the artisan command to assign panels to existing resellers:

```bash
# Assign all auto-assign panels to all resellers
php artisan resellers:assign-panels --all-panels

# Or assign specific panels
php artisan resellers:assign-panels --panel_id=1 --panel_id=2
```

### 5. Clear Caches
```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

## Testing Checklist

### Registration Flow
- [ ] New user can register without selecting a panel
- [ ] New reseller automatically gets access to panels with `auto_assign_to_resellers=true`
- [ ] Check database: `reseller_panel_access` table has correct entries
- [ ] Verify allowed_node_ids and allowed_service_ids are populated from panel defaults

### Admin Panel Management
- [ ] Admin can see "Auto-assign to resellers" toggle in panel edit form
- [ ] When creating a panel with auto-assign enabled, it's assigned to all existing resellers
- [ ] When editing a panel and enabling auto-assign, it's assigned to all existing resellers
- [ ] Check notification appears confirming number of resellers assigned

### Config Creation
- [ ] Reseller dashboard shows list of accessible panels
- [ ] Config creation form shows panel dropdown
- [ ] When panel is selected, appropriate fields appear (nodes for Eylandoo, services for Marzneshin)
- [ ] Cannot select a panel the reseller doesn't have access to
- [ ] Cannot select nodes/services not allowed in pivot table
- [ ] Config creation succeeds with proper validation

### Artisan Command
- [ ] `php artisan resellers:assign-panels --help` shows usage
- [ ] `--all-panels` assigns all auto-assign panels to all resellers
- [ ] `--panel_id=X` assigns specific panel to all resellers
- [ ] `--panel_id=X --reseller_id=Y` assigns specific panel to specific reseller
- [ ] Running command multiple times is idempotent (no duplicates)

### Backward Compatibility
- [ ] Existing resellers can still access their configs
- [ ] Existing configs continue to work
- [ ] `primary_panel_id` field is still present (for backward compatibility)
- [ ] No breaking changes to existing functionality

## Rollback Plan

If issues arise, you can rollback:

### 1. Restore Database Backup
```bash
mysql -u user -p database_name < backup_before_multi_panel.sql
```

### 2. Rollback Git Changes
```bash
git revert <commit-hash>
git push
```

### 3. Run Migrations Rollback (if needed)
```bash
php artisan migrate:rollback --step=2
```

This will remove:
- `reseller_panel_access` table
- `auto_assign_to_resellers` column from panels table

## API Documentation

### Reseller Model Methods

```php
// Check if reseller has access to a panel
$reseller->hasPanelAccess(int $panelId): bool

// Get pivot data for a panel
$access = $reseller->panelAccess(int $panelId);
$allowedNodes = json_decode($access->allowed_node_ids, true);
$allowedServices = json_decode($access->allowed_service_ids, true);

// Get all panels reseller has access to
$panels = $reseller->panels;
```

### Panel Model Methods

```php
// Get panels with auto-assign enabled
$panels = Panel::autoAssign()->get();

// Check if panel has auto-assign enabled
$panel->auto_assign_to_resellers; // boolean
```

## Troubleshooting

### Issue: Resellers don't have access to any panels after registration

**Solution**: 
1. Check if any panels have `auto_assign_to_resellers=true`
2. Enable auto-assign on at least one panel
3. Run: `php artisan resellers:assign-panels --all-panels`

### Issue: Config creation fails with "You do not have access to the selected panel"

**Solution**:
1. Verify the reseller has access: `$reseller->hasPanelAccess($panelId)`
2. If not, assign the panel: 
```php
$reseller->panels()->attach($panelId, [
    'allowed_node_ids' => json_encode([1, 2, 3]),
    'allowed_service_ids' => json_encode([1, 2, 3])
]);
```

### Issue: Migration fails with "table already exists"

**Solution**:
The migrations have safety checks. If tables exist, they won't be recreated. You can safely run the migrations again.

## Security Considerations

1. **Panel Access Validation**: All config creation requests validate that the reseller has access to the selected panel
2. **Node/Service Validation**: Server-side validation ensures resellers can only use nodes/services allowed in their pivot access
3. **Backward Compatibility**: Existing `primary_panel_id` field is kept but no longer used in new flows
4. **SQL Injection Protection**: All database queries use Eloquent ORM with parameter binding
5. **Authorization**: Admin-only access to panel management and auto-assignment features

## Performance Considerations

1. **Chunked Processing**: The artisan command processes resellers in chunks of 500 to avoid memory issues
2. **Eager Loading**: Panel relationships are eager-loaded where appropriate to avoid N+1 queries
3. **Caching**: Panel node/service lists are cached for 5 minutes in the Panel model
4. **Indexing**: Unique index on `(reseller_id, panel_id)` in pivot table for fast lookups

## Future Enhancements

1. **Per-Reseller Panel Priority**: Allow resellers to set a default/preferred panel
2. **Panel-Specific Pricing**: Different pricing per panel in the pivot table
3. **Panel Usage Analytics**: Track which panels are most used by resellers
4. **Dynamic Panel Selection**: Auto-select best panel based on load/availability
5. **Panel Groups**: Group panels by region or performance tier

## Support

For issues or questions:
1. Check this documentation first
2. Review the implementation code in the PR
3. Test in a staging environment before production
4. Contact the development team for assistance
