# Eylandoo Max Clients Implementation Summary

## Overview
This PR implements support for the `max_clients` field in the reseller UI for Eylandoo panels, allowing resellers to specify the maximum number of simultaneous clients that can connect to each config.

## Problem Statement
The Eylandoo panel API supports a `max_clients` field on create and edit user endpoints, but this was not exposed in the VpnMarket reseller UI. Resellers needed the ability to set and modify this value for better control over client connections.

## Solution
Added a numeric input field for "Max clients" in the reseller config create and edit forms, visible only for Eylandoo panels. The field:
- Appears next to the Eylandoo Nodes selector
- Defaults to 1 if not specified
- Validates as integer ≥ 1
- Is stored in config meta for traceability
- Is sent to the Eylandoo API on create and update

## Files Changed

### 1. Modules/Reseller/resources/views/configs/create.blade.php
- Added max_clients input field (shows only for Eylandoo)
- Added JavaScript to show/hide field based on panel type
- Field is required when visible, defaults to 1

### 2. Modules/Reseller/resources/views/configs/edit.blade.php
- Added max_clients input field for Eylandoo configs
- Pre-fills value from config meta or defaults to 1

### 3. Modules/Reseller/Http/Controllers/ConfigController.php
- Added validation: `'max_clients' => 'nullable|integer|min:1'`
- Store method: accepts max_clients, stores in meta, passes to provisioner
- Update method: validates max_clients, updates meta, uses updateUser() for Eylandoo
- Added logging for debugging
- Event and audit logs track max_clients changes

### 4. Modules/Reseller/Services/ResellerProvisioner.php
- Added `updateUser()` method for flexible user updates
- Supports max_clients updates for Eylandoo
- Has retry logic (3 attempts with exponential backoff)
- Works for all panel types

### 5. tests/Feature/EylandooMaxClientsTest.php (new)
- Comprehensive test suite with 8 test cases
- Tests creation, updates, validation, and API integration
- Uses HTTP fakes to mock Eylandoo API

### 6. EYLANDOO_MAX_CLIENTS_TESTING_GUIDE.md (new)
- Detailed manual testing guide
- 10 test scenarios with step-by-step instructions
- Database verification queries
- API call examples
- Troubleshooting tips

## Key Design Decisions

### 1. Storage in Meta Field
We store max_clients in the `meta` JSON field rather than adding a dedicated column because:
- It's an Eylandoo-specific field
- Meta already contains other panel-specific data (e.g., node_ids)
- Easier to extend in the future without migrations
- Maintains data traceability

### 2. Default Value of 1
Chosen because:
- Matches common use case (single device per config)
- Safe default that doesn't restrict access
- Explicit in the UI (shown in input field)

### 3. Validation Rules
- `nullable`: Field is optional (defaults to 1)
- `integer`: Ensures numeric value
- `min:1`: At least one client must be allowed

### 4. Using updateUser() for All Eylandoo Updates
- Initially considered using updateUser() only when max_clients changes
- Refactored to always use updateUser() for Eylandoo
- Benefits: simpler code, consistent behavior, future-proof

### 5. Conditional Field Visibility
- Field shows only for Eylandoo panels (like Nodes selector)
- Uses JavaScript for dynamic show/hide on panel change
- Server-side rendering for single-panel resellers

## API Integration

### Data Limit Unit Handling

The API automatically selects the appropriate unit (MB or GB) based on the data limit value:

| Input (bytes) | Output Value | Output Unit |
|---------------|--------------|-------------|
| 52428800 (50 MB) | 50 | MB |
| 524288000 (500 MB) | 500 | MB |
| 1073741824 (1 GB) | 1.0 | GB |
| 1610612736 (1.5 GB) | 1.5 | GB |

**Rules:**
- Values < 1 GB (1073741824 bytes): Sent as MB
- Values >= 1 GB: Sent as GB with 2 decimal precision
- Minimum: 1 MB for any positive value

### Create User Request
```json
POST /api/v1/users
{
  "username": "resell_1_cfg_123",
  "activation_type": "fixed_date",
  "data_limit": 10.0,
  "data_limit_unit": "GB",
  "expiry_date_str": "2025-12-07",
  "max_clients": 3
}
```

**Example with MB (500 MB limit):**
```json
POST /api/v1/users
{
  "username": "resell_1_cfg_456",
  "activation_type": "fixed_date",
  "data_limit": 500,
  "data_limit_unit": "MB",
  "expiry_date_str": "2025-12-07",
  "max_clients": 1
}
```

### Update User Request
```json
PUT /api/v1/users/resell_1_cfg_123
{
  "data_limit": 15.0,
  "data_limit_unit": "GB",
  "expiry_date_str": "2025-12-10",
  "max_clients": 5
}
```

## Testing

### Automated Tests
8 test cases covering:
- ✅ Storage in meta field
- ✅ Default value (1)
- ✅ Custom values
- ✅ Updates
- ✅ Provisioner integration
- ✅ API payload validation
- ✅ Input validation (min value)
- ✅ Input validation (type)

### Manual Testing
Comprehensive guide with 10 scenarios:
1. Field visibility
2. Default value
3. Custom value
4. Validation (zero, negative, non-integer)
5. Edit form visibility
6. Edit form updates
7. No-change updates
8. Multi-panel dynamic behavior
9. Debug logging
10. End-to-end API integration

## Security Considerations
- ✅ Field is validated server-side
- ✅ User permissions checked (only reseller can edit their configs)
- ✅ No sensitive data in logs
- ✅ Integer normalization prevents injection
- ✅ Minimum value enforced (≥ 1)

## Performance Considerations
- ✅ No additional database queries
- ✅ Uses existing meta JSON field
- ✅ Conditional API calls (only for Eylandoo)
- ✅ Retry logic prevents transient failures

## Backward Compatibility
- ✅ Existing configs without max_clients default to 1
- ✅ Non-Eylandoo panels unaffected
- ✅ No database migrations required
- ✅ Optional field (doesn't break existing forms)

## Logging and Debugging
- Debug logs when APP_DEBUG=true
- Includes reseller_id, panel_id, max_clients
- Event logs track old/new values
- Audit logs for compliance
- No sensitive data exposed

## Code Quality
- ✅ PHP syntax validated
- ✅ Follows existing patterns (Eylandoo nodes selector)
- ✅ Code review feedback addressed
- ✅ Consistent naming conventions
- ✅ Proper error handling
- ✅ Retry logic for resilience

## Documentation
- ✅ Inline code comments
- ✅ Comprehensive testing guide
- ✅ API examples
- ✅ Troubleshooting tips
- ✅ Success criteria defined

## Future Enhancements
Potential improvements for future PRs:
- Add max_clients to Filament admin panel
- Bulk update max_clients for multiple configs
- Display max_clients in config list view
- Add max_clients to config export/import
- Support max_clients for other panel types if they add support

## Acceptance Criteria
All criteria met:
- ✅ Eylandoo reseller config create page shows Max clients field
- ✅ Field appears only for Eylandoo resellers
- ✅ Default value is 1
- ✅ Creating a config sends max_clients to Eylandoo API
- ✅ Creating a config stores max_clients in meta
- ✅ Editing a config shows Max clients prefilled
- ✅ Saving edit updates Eylandoo user with new max_clients
- ✅ Saving edit updates meta
- ✅ Validation enforces integer ≥ 1
- ✅ IDs normalized to integers
- ✅ Logs include values for debugging

## Related PRs
This implementation follows the same pattern as:
- Eylandoo Nodes Selector (#previous-pr)
- Marzneshin Services Selector (#previous-pr)

## Migration Notes
No database migrations required. The meta field already exists and is cast to JSON.

## Rollback Plan
If issues arise:
1. The field can be hidden via frontend without backend changes
2. Existing configs continue working with default value of 1
3. No data loss - meta field retains all values
4. Can revert commits cleanly

## Monitoring
After deployment, monitor:
- Eylandoo API success rates
- Config creation/update failures
- Validation errors
- Log entries for max_clients
- User feedback on field behavior

## Conclusion
This implementation adds robust support for the Eylandoo max_clients field with:
- Minimal code changes (surgical modifications)
- Comprehensive testing
- Clear documentation
- Backward compatibility
- Proper validation and error handling
- Following established patterns

The feature is ready for production deployment.
