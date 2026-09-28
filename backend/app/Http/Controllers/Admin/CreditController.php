<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CreditLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CreditController extends Controller
{
    public function index(Request $request, CreditLedger $ledger)
    {
        $request->validate(['user' => ['nullable', 'uuid', 'exists:users,id']]);
        $user = $request->filled('user') ? User::findOrFail($request->input('user')) : null;
        return Inertia::render('Credits/Index', [
            'settings' => $ledger->settings(), 'bundles' => DB::table('credit_bundles')->orderBy('order')->get(),
            'account' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            'balance' => $user ? $ledger->summary($user) : null,
            'orders' => DB::table('credit_orders')->when($user, fn ($q) => $q->where('user_id', $user->id))->orderByDesc('created_at')->paginate(25)->withQueryString(),
        ]);
    }
    public function settings(Request $request)
    {
        $data = $request->validate(['monthly_credits' => ['required', 'integer', 'min:0', 'max:10000'], 'membership_cap' => ['required', 'integer', 'min:0', 'max:10000'], 'purchase_validity_months' => ['required', 'integer', 'min:1', 'max:120']]);
        DB::table('credit_settings')->where('id', 1)->update($data);
        return back()->with('success', 'Creditinstellingen opgeslagen. Bestaande aankopen behouden hun vervaldatum.');
    }
    public function bundle(Request $request, ?string $bundle = null)
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:120'], 'credits' => ['required', 'integer', 'min:1', 'max:10000'], 'price_cents' => ['required', 'integer', 'min:1', 'max:1000000'], 'currency' => ['required', 'in:EUR'], 'active' => ['required', 'boolean'], 'order' => ['required', 'integer', 'min:0', 'max:10000']]);
        if ($bundle) {
            abort_unless(DB::table('credit_bundles')->where('id', $bundle)->exists(), 404);
            DB::table('credit_bundles')->where('id', $bundle)->update($data + ['updated_at' => now()]);
        } else DB::table('credit_bundles')->insert($data + ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', 'Creditbundel opgeslagen.');
    }
}
