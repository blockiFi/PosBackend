# PIN Login System - Quick Reference

## Overview
Fast login system using 6-digit numeric PIN codes for quick cashier authentication in POS environments.

## API Endpoints

### 1. PIN Login (Public)
```http
POST /api/pin-login
```

**Request:**
```json
{
  "pin_code": "123456"
}
```

**Response (200):**
```json
{
  "message": "Login successful",
  "token": "1|abc123...",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

**Errors:**
- `401`: Invalid PIN code
- `422`: Validation error (must be 6 numeric digits)

---

### 2. Set/Update PIN (Protected)
```http
POST /api/pin/set
Authorization: Bearer {token}
```

**Request:**
```json
{
  "pin_code": "654321",
  "password": "your_password"
}
```

**Response (200):**
```json
{
  "message": "PIN code set successfully"
}
```

**Errors:**
- `401`: Invalid password or unauthorized
- `422`: PIN already in use or validation error

---

### 3. Remove PIN (Protected)
```http
POST /api/pin/remove
Authorization: Bearer {token}
```

**Request:**
```json
{
  "password": "your_password"
}
```

**Response (200):**
```json
{
  "message": "PIN code removed successfully"
}
```

**Errors:**
- `401`: Invalid password or unauthorized

---

## Security Features

✓ **6-Digit Numeric Only** - Only accepts 6 numeric digits (000000-999999)
✓ **Unique PINs** - Each PIN can only be assigned to one user
✓ **Password Protection** - Setting/removing PIN requires password verification
✓ **Hidden from API** - PIN codes never returned in user responses
✓ **Indexed for Speed** - Database index for fast PIN lookups
✓ **Token-Based** - Returns same Sanctum tokens as email/password login

---

## Usage Examples

### Setting a PIN for the First Time
```bash
curl -X POST http://localhost:8000/api/pin/set \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "pin_code": "123456",
    "password": "mypassword"
  }'
```

### Fast Login with PIN
```bash
curl -X POST http://localhost:8000/api/pin-login \
  -H "Content-Type: application/json" \
  -d '{
    "pin_code": "123456"
  }'
```

### Updating PIN
```bash
curl -X POST http://localhost:8000/api/pin/set \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "pin_code": "654321",
    "password": "mypassword"
  }'
```

### Removing PIN
```bash
curl -X POST http://localhost:8000/api/pin/remove \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "password": "mypassword"
  }'
```

---

## Database Schema

### users Table (Updated)
```sql
ALTER TABLE users 
ADD COLUMN pin_code VARCHAR(6) NULL AFTER password,
ADD INDEX idx_pin_code (pin_code);
```

**Field:** `pin_code`
- Type: VARCHAR(6)
- Nullable: Yes
- Indexed: Yes
- Unique: Via application logic

---

## Validation Rules

### PIN Code
- **Required**: Yes (for login and set operations)
- **Type**: String
- **Length**: Exactly 6 characters
- **Pattern**: `^[0-9]{6}$` (only numeric digits)
- **Examples**: 
  - ✓ Valid: "123456", "000000", "999999"
  - ✗ Invalid: "12345" (too short), "1234567" (too long), "abcdef" (not numeric)

### Password (for set/remove)
- **Required**: Yes
- **Type**: String
- **Must match**: User's current password

---

## Test Coverage

**19 comprehensive tests** covering:
- ✓ Successful PIN login
- ✓ Invalid PIN rejection
- ✓ 6-digit validation
- ✓ Numeric-only validation
- ✓ Setting/updating PIN
- ✓ Password verification for PIN changes
- ✓ Duplicate PIN prevention
- ✓ PIN removal
- ✓ Security (PIN hidden in responses)
- ✓ Token generation and validity
- ✓ Multiple users with different PINs

**Total Test Results:** 171/171 tests passing ✓

---

## Common Use Cases

### 1. Quick Cashier Login
Cashiers can quickly clock in using their 6-digit PIN instead of typing email/password.

### 2. Shift Changes
Fast authentication for multiple employees during shift transitions.

### 3. Manager Override
Managers can use PIN for quick approval of discounts, voids, etc.

### 4. Kiosk Mode
Self-service kiosks where staff need quick authentication.

---

## Best Practices

1. **Choose Unique PINs**: Avoid common codes like 123456, 000000, 111111
2. **Regular Updates**: Encourage users to change PINs periodically
3. **Secure Storage**: PINs are stored as plain text (for lookup) but hidden in API responses
4. **Combine with Shifts**: Associate PIN logins with sales shifts for accountability
5. **Audit Trail**: Monitor PIN login attempts for security

---

## Security Considerations

⚠️ **Important Notes:**
- PINs are less secure than passwords - use only in controlled environments
- Stored as plain text for lookup (necessary for PIN-based auth)
- Hidden from all API responses to prevent exposure
- Rate limiting recommended for production (prevent brute force)
- Consider implementing:
  - Login attempt tracking
  - Auto-lockout after failed attempts
  - Session timeouts
  - Activity logging

---

## Integration with Shift System

Users can login with PIN and immediately open a shift:

```javascript
// 1. Login with PIN
const loginResponse = await fetch('/api/pin-login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ pin_code: '123456' })
});

const { token } = await loginResponse.json();

// 2. Open shift
const shiftResponse = await fetch('/api/shifts', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'X-Business-Id': '1',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    branch_id: 1,
    opening_balance: 100.00
  })
});
```

---

## Troubleshooting

### "Invalid PIN code" Error
- Verify PIN is exactly 6 digits
- Check user has a PIN set (not null)
- Ensure PIN matches database value

### "This PIN code is already in use" Error
- Another user has that PIN
- Choose a different PIN
- Admin may need to reassign PINs

### "Invalid password" Error
- User password doesn't match
- Required for PIN set/remove operations
- Use correct account password

---

## Files Modified/Created

1. **Migration**: `database/migrations/2026_01_25_142549_add_pin_code_to_users_table.php`
2. **Model**: `app/Models/User.php` (added pin_code to fillable and hidden)
3. **Controller**: `app/Http/Controllers/Api/AuthenticationController.php` (3 new methods)
4. **Routes**: `routes/api.php` (3 new endpoints)
5. **Tests**: `tests/Feature/PinLoginTest.php` (19 tests)
