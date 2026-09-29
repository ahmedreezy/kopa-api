<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class OperationsController extends Controller
{
    public function collections(Request $request): JsonResponse
    {
        $view = $request->input('view', 'due_today');
        $query = RepaymentSchedule::query()->with('loan.borrower')->where('status', '!=', 'paid');
        $query->whereNotExists(function ($earlier) {
            $earlier->selectRaw('1')->from('repayment_schedules as earlier')
                ->whereColumn('earlier.loan_id', 'repayment_schedules.loan_id')
                ->where('earlier.status', '!=', 'paid')
                ->whereColumn('earlier.installment_number', '<', 'repayment_schedules.installment_number');
        });
        match ($view) {
            'overdue' => $query->whereDate('due_date', '<', today()),
            'upcoming' => $query->whereDate('due_date', '>', today()),
            default => $query->whereDate('due_date', today()),
        };

        return response()->json($query->orderBy('due_date')->paginate(25));
    }

    public function branches(): JsonResponse
    {
        return response()->json(Branch::query()->orderBy('name')->get());
    }

    public function storeBranch(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('staff.view'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'unique:tenant.branches,code'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(Branch::query()->create($data + ['is_active' => true]), 201);
    }

    public function staff(): JsonResponse
    {
        return response()->json(User::query()->with([])->orderBy('name')->get());
    }

    public function storeStaff(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'owner', 403);
        $data = $request->validate([
            'branch_id' => ['nullable', 'uuid', 'exists:tenant.branches,id'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:tenant.users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', 'in:manager,loan_officer,collector,accountant'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = true;

        return response()->json(User::query()->create($data), 201);
    }

    public function reportSummary(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('reports.view') || $request->user()->role === 'owner', 403);
        $from = Carbon::parse($request->input('from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->input('to', now()->endOfMonth()->toDateString()));
        $loans = Loan::query()->whereBetween('disbursement_date', [$from, $to])->get();
        $payments = Repayment::query()->where('entry_type', 'payment')->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])->get();
        $activeLoans = Loan::query()->where('status', 'active')->get();
        $overdue = RepaymentSchedule::query()->whereDate('due_date', '<', today())->where('status', '!=', 'paid')->get();
        $staff = User::query()->orderBy('name')->get()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'amount' => $payments->where('recorded_by', $user->id)->sum('amount'),
            'payments' => $payments->where('recorded_by', $user->id)->count(),
        ])->filter(fn (array $row) => $row['payments'] > 0)->values();

        return response()->json([
            'money_lent' => $loans->sum('net_disbursement_amount'),
            'money_collected' => $payments->sum('amount'),
            'interest_expected' => $loans->sum('total_interest'),
            'outstanding' => $activeLoans->sum(fn (Loan $loan) => $loan->outstanding_amount),
            'overdue' => $overdue->sum(fn ($item) => $item->amount_due - $item->amount_paid),
            'collections_by_staff' => $staff,
        ]);
    }

    public function company(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
            'regulatory_class' => $tenant->regulatory_class,
            'umra_license_number' => $tenant->umra_license_number,
            'umra_license_expires_on' => $tenant->umra_license_expires_on?->toDateString(),
            'pdpo_registration_number' => $tenant->pdpo_registration_number,
            'settings' => $tenant->settings ?? [],
        ]);
    }

    public function updateCompany(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless($request->user()->role === 'owner', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'in:UGX'],
            'regulatory_class' => ['sometimes', 'in:money_lender,non_deposit_mfi,sacco,other'],
            'umra_license_number' => ['nullable', 'string', 'max:100'],
            'umra_license_expires_on' => ['nullable', 'date'],
            'pdpo_registration_number' => ['nullable', 'string', 'max:100'],
        ]);
        $tenant = $context->tenant();
        $tenant->forceFill([
            'name' => $data['name'],
            'regulatory_class' => $data['regulatory_class'] ?? $tenant->regulatory_class,
            'umra_license_number' => array_key_exists('umra_license_number', $data) ? $data['umra_license_number'] : $tenant->umra_license_number,
            'umra_license_expires_on' => array_key_exists('umra_license_expires_on', $data) ? $data['umra_license_expires_on'] : $tenant->umra_license_expires_on,
            'pdpo_registration_number' => array_key_exists('pdpo_registration_number', $data) ? $data['pdpo_registration_number'] : $tenant->pdpo_registration_number,
            'settings' => collect($tenant->settings ?? [])->merge(collect($data)->only(['phone', 'email', 'address', 'currency']))->all(),
        ])->save();

        return $this->company($context);
    }

    public function audit(): JsonResponse
    {
        return response()->json(AuditLog::query()->latest('created_at')->paginate(30));
    }
}
