<?php
namespace App\Http\Controllers;

use App\Contracts\CreditPaymentGateway;
use App\Models\User;
use App\Support\CreditLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreditController extends Controller
{
    private function user(Request $request): User
    {
        $user = User::find($request->attributes->get('powersync_user_id'));
        abort_unless($user, 401); abort_if($user->disabled_at, 403);
        return $user;
    }
    public function index(Request $request, CreditLedger $ledger, CreditPaymentGateway $payments)
    {
        return response()->json($ledger->summary($this->user($request)) + [
            'checkoutAvailable' => $payments->configured(),
            'validityMonths' => (int) $ledger->settings()->purchase_validity_months,
            'bundles' => DB::table('credit_bundles')->where('active', true)->orderBy('order')->orderBy('credits')->get(),
        ]);
    }
    /** Temporary replacement for checkout while live payments are deferred. */
    public function temporaryTopUp(Request $request, CreditLedger $ledger)
    {
        $user = $this->user($request);
        $data = $request->validate(['requestKey' => ['required', 'uuid']]);
        $ledger->locked($user, function () use ($user, $data, $ledger) {
            // The account lock serializes duplicate requests, including a retry
            // after the response was lost. A fresh deliberate click uses a new key.
            $reason = 'Temporary seven-credit top-up: '.$data['requestKey'];
            if (DB::table('credit_transactions')->where('user_id', $user->id)
                ->where('type', 'promotional')->where('reason', $reason)->exists()) return;
            $ledger->grant($user, 7, 'promotional', reason: $reason);
        });
        return response()->json($ledger->summary($user));
    }

    public function purchase(Request $request, CreditLedger $ledger, CreditPaymentGateway $payments)
    {
        $user = $this->user($request);
        $data = $request->validate(['bundleId' => ['required', 'uuid'], 'requestKey' => ['required', 'uuid'], 'itemId' => ['nullable', 'uuid', 'exists:library_items,id']]);
        abort_unless($payments->configured(), 503, 'Credits bijkopen is nog niet beschikbaar.');
        $order = $ledger->locked($user, function () use ($user, $data, $ledger) {
            $previous = DB::table('credit_orders')->where('user_id', $user->id)->where('request_key', $data['requestKey'])->first();
            if ($previous) {
                abort_unless($previous->bundle_id === $data['bundleId'] && $previous->item_id === ($data['itemId'] ?? null), 409);
                return $previous;
            }
            abort_unless($ledger->basic($user), 403, 'Je hebt Basic nodig om credits bij te kopen.');
            $bundle = DB::table('credit_bundles')->where('id', $data['bundleId'])->where('active', true)->first();
            abort_unless($bundle, 404, 'Deze bundel is niet meer beschikbaar.');
            $id = (string) Str::uuid();
            DB::table('credit_orders')->insert([
                'id' => $id, 'user_id' => $user->id, 'bundle_id' => $bundle->id, 'item_id' => $data['itemId'] ?? null,
                'kind' => 'purchased', 'credits' => $bundle->credits, 'price_cents' => $bundle->price_cents,
                'currency' => $bundle->currency, 'validity_months' => $ledger->settings()->purchase_validity_months,
                'request_key' => $data['requestKey'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('credit_orders')->find($id);
        });
        // Provider must use order ID for idempotency, including timeouts and concurrent retries.
        $checkout = $payments->checkout($order);
        return response()->json(['orderId' => $order->id] + $checkout);
    }
    public function order(Request $request, string $order, CreditPaymentGateway $payments)
    {
        $user = $this->user($request);
        $record = DB::table('credit_orders')->where('user_id', $user->id)->where('id', $order)->first();
        abort_unless($record, 404);
        $payments->refresh($record);
        $record = DB::table('credit_orders')->find($order);
        return response()->json(['id' => $record->id, 'status' => $record->status, 'itemId' => $record->item_id]);
    }
    public function cancel(Request $request, CreditLedger $ledger)
    {
        $user = $this->user($request);
        $ledger->locked($user, function () use ($user, $ledger) {
            $subscription = $ledger->basic($user);
            abort_unless($subscription, 404, 'Geen actief Basic-abonnement.');
            $subscription->update(['cancel_requested_at' => $subscription->cancel_requested_at ?? now()]);
        });
        return response()->json($ledger->summary($user));
    }
}
