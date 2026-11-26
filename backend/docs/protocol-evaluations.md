# EquiBoost evaluation

The evaluation notice is an additional Home card above intake and protocol cards;
the daily protocol action remains available. It opens 14 days before the last
scheduled day. End date = owner start date + total scheduled weeks - 1 day,
using the longest phase week if that exceeds the protocol total. Unstarted,
unpublished, paused and archived protocols do not open an evaluation. The
discount display ends after the seventh calendar day after the last protocol
day (Europe/Amsterdam), inclusive. Price is read from the admin EquiBoost price.

This feature does not create purchases, payments, renewal requests or new
protocols. The follow-up page is informational and explicitly directs users to
contact their therapist outside checkout. There is no payment integration.

Questions are managed in `/admin/evaluation-questions`: edit, add, order,
deactivate/remove and reactivate. At least one active question remains. Questions
are all required on submission. Drafts may be incomplete.

A question snapshot is stored with the first draft/submission. Future catalog
edits apply to new evaluations; old answers keep their original question text.
A version check rejects stale first-time submissions if the catalog changed
after the user opened it. Stable question IDs, not labels, identify answers.

Only the active horse's owner can read/write their evaluation. Submitted records
appear in the horse detail page (all protocols grouped together) and protocol
detail/editor. Drafts are not exposed in the therapist view. The owner can amend
submitted answers, preserving the original submission timestamp and updating the
last-saved timestamp. There is no email delivery or therapist chat integration.

Admin can export submitted evaluations with their answers as PDF from the horse's
page. Exports use the stored question snapshot, never the live catalog.
Horse/protocol consistency is checked by the PDF route. No photo/file uploads or
remote document resources are part of the evaluation PDF.

Deployment requires migration `2026_11_18_000004_create_protocol_evaluations.php`
before the new backend/admin code. It initializes six editable default questions.
Run `php artisan test --filter=ProtocolEvaluationTest` in the PHP/PostgreSQL
environment, including PDF generation and authorization checks.
