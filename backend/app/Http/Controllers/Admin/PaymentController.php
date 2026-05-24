<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $payments = Payment::query()
            ->when($request->string('status')->toString(), fn ($query, $s) => $query->where('status', $s))
            ->with('subscription.user:id,name,email')
            ->latest('date')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Payment $p) => [
                'id' => $p->id,
                'date' => $p->date,
                'amount_cents' => $p->amount_cents,
                'currency' => $p->currency,
                'status' => $p->status,
                'receipt_url' => $p->receipt_url,
                'user' => $p->subscription?->user
                    ? ['id' => $p->subscription->user->id, 'name' => $p->subscription->user->name]
                    : null,
            ]);

        return Inertia::render('Payments/Index', [
            'payments' => $payments,
            'filters' => $request->only('status'),
            'totals' => [
                'paid' => (int) Payment::where('status', 'paid')->sum('amount_cents'),
                'failed' => Payment::where('status', 'failed')->count(),
                'refunded' => (int) Payment::where('status', 'refunded')->sum('amount_cents'),
            ],
        ]);
    }
}
