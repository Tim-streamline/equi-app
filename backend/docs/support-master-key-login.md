# Support master-key login

Support can sign into an existing, enabled customer account using that customer's email and a master key in the **password** field of the normal website or mobile login form. The customer's own password remains valid and is never replaced. Use a separate browser profile for support work so your normal customer session stays separate.

The feature is disabled by default and only works in `local`, `testing`, and `staging`. Production rejects the key even if its configuration is copied there. It does not grant access to the admin login, disabled accounts, or features the customer cannot access through their subscription. Changes made while signed in affect the customer's actual data.

## Configuration

1. Generate a random key of at least 32 characters in your password manager and share it only with authorized support staff.
2. Compute its SHA-256 digest using a hidden prompt, without putting the key in command arguments or shell history:

   ```sh
   python3 -c 'import getpass, hashlib; key = getpass.getpass("Support master key: "); assert len(key) >= 32, "Use at least 32 random characters"; print(hashlib.sha256(key.encode()).hexdigest())'
   ```

3. Add only the digest to the backend's private environment configuration:

   ```dotenv
   SUPPORT_MASTER_KEY_ENABLED=true
   SUPPORT_MASTER_KEY_HASH=<64-character lowercase SHA-256 digest>
   ```

4. Rebuild Laravel's configuration cache and reload long-running application workers through the normal deployment process. For staging deployments, keep these settings in the private `deploy/staging.env` as well: deployment installs that file as the runtime configuration. Never put the plaintext key in source code, frontend configuration, or `EXPO_PUBLIC_*` variables.

## Sessions and audit

- Browser support sessions expire after one hour. Renewal does not extend that deadline, and no persistent remember cookie is issued for support access.
- Support JWTs expire after at most five minutes. The mobile app never stores the master key: login returns a device token that the app keeps in the phone's secure storage and exchanges for new JWTs. A support device token ends one hour after login and is never extended, so support then signs in again. Signing out of the mobile app revokes it immediately; do so when support work is done.
- Failed customer login attempts are limited to ten per minute per account and IP, shared by the website and native API, and to one hundred per minute per IP across all accounts. Other people's failed logins on a shared network therefore do not block an account, while one IP still cannot try many accounts or master-key guesses. Successful logins, including token refreshes by older app versions, do not count. Browser login still requires CSRF protection.
- Each master-key authentication creates a `support_login` record in the existing `audit_logs` table with the target user, channel, timestamp, IP, and user agent. The key and digest are never recorded. A shared key identifies support access, not the individual staff member holding it.
- Support login and logout do not rotate the customer's remember token or invalidate the customer's own sessions. Subscription and account-access rules remain effective.

To revoke support access, disable `SUPPORT_MASTER_KEY_ENABLED` or replace the digest, then rebuild configuration and reload workers. Laravel rejects existing support JWTs, support device tokens and browser renewal after the new configuration is active. PowerSync verifies already-issued JWTs independently, so its existing synchronization access can last until token expiry (at most five minutes). Cached data already downloaded to a device is not remotely erased.

## Verification

From `backend/`, run:

```sh
./vendor/bin/sail artisan test --compact tests/Feature/MasterKeyLoginTest.php tests/Feature/DeviceTokenTest.php tests/Feature/WebSessionTest.php tests/Feature/PasswordRecoveryTest.php
```

The regression coverage includes both login channels, customer credential preservation, expiry, rotation and disabling, audit records, production rejection, CSRF, and shared throttling of failed attempts.
