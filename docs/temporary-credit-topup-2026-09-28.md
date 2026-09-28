# Temporary seven-credit button

At the user's request, both `Credits bijkopen` buttons (Account and insufficient-credit library items) immediately add seven credits to the signed-in account and refresh the displayed balance/access. Basic is not required. Credits are recorded as promotional grants with the history description `Tijdelijke credits toegevoegd`; no payment or paid order is fabricated.

`POST /api/library/credits/temporary-top-up` accepts a UUID `requestKey`. The server fixes the amount at seven and resolves the account from authentication. Its existing account lock serializes grants; the ledger reason records the key so retries cannot grant twice, even after those credits were spent. The shared button prevents overlapping requests, keeps the key after uncertain failures and creates a new one after success.

To remove this temporary feature, remove the route and `CreditController::temporaryTopUp`, restore the purchase-navigation buttons in CreditsScreen and LibraryContent, and remove TemporaryCreditButton and its focused tests. Existing promotional grants remain valid ledger history.

Validation: 15 credit API/ledger tests (91 assertions), 21 focused UI/request tests, native/shared TypeScript passed. Backend reloaded locally; no database migration needed.

Web TypeScript and production export also passed; Android assembleRelease succeeded. The new APK was installed on Pixel 7 with data preserved (28 September, 04:39:56 local time), and launch returned Status: ok. The device was locked, so no visual interaction was verified. Pixel 7 Pro was not discoverable and was not updated. APK SHA-256: `7f479d90be1f29d1e79819dd82eda2239fe9dd6e59b3f11e5b98a41cf7ef3166`.
