<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\ConsentRecord;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BorrowerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->query($request)->with([
            'documents',
            'loans' => fn ($q) => $q->whereIn('status', ['active', 'overdue'])->latest(),
        ]);

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPerform('borrowers.manage'), 403);
        $data = $request->validate($this->rules());
        $this->assertIdentityDocuments($request, $data);
        $borrower = Borrower::query()->create(Arr::except($data, ['document_ids', 'consent_confirmed']) + [
            'consent_given_at' => now(), 'consent_notice_version' => '2026-09',
        ]);
        $this->attachDocuments($request, $borrower, $data['document_ids'] ?? []);
        ConsentRecord::query()->create([
            'borrower_id' => $borrower->id, 'purpose' => 'credit_application_and_servicing',
            'notice_version' => '2026-09', 'granted_at' => now(), 'recorded_by' => $request->user()->id,
            'ip_address' => $request->ip(),
        ]);
        $this->audit($request, 'borrower.created', $borrower, ['full_name', 'borrower_type', 'district']);

        return response()->json($borrower->load('documents'), 201);
    }

    public function update(Request $request, string $borrower): JsonResponse
    {
        abort_unless($request->user()->canPerform('borrowers.manage'), 403);
        $borrower = Borrower::query()->findOrFail($borrower);
        $before = $borrower->toArray();
        $data = $request->validate($this->rules($borrower->id));
        $this->assertIdentityDocuments($request, $data, $borrower);
        $borrower->update(Arr::except($data, ['document_ids', 'consent_confirmed']));
        $this->attachDocuments($request, $borrower, $data['document_ids'] ?? []);
        $this->audit($request, 'borrower.updated', $borrower, [], $before);

        return response()->json($borrower->fresh()->load('documents', 'loans.schedules'));
    }

    public function show(string $borrower): JsonResponse
    {
        $borrower = Borrower::query()->findOrFail($borrower);

        return response()->json($borrower->load('documents', ['loans' => fn ($q) => $q->with('product', 'schedules')->latest()]));
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->canPerform('borrowers.export_bulk'), 403);
        $rows = $this->query($request)->with('documents')->orderBy('full_name')->get();
        $this->audit($request, 'borrowers.exported', null, [], [], ['count' => $rows->count()]);

        return $this->csv('kopa-borrowers-'.now()->format('Ymd-His').'.csv', $rows);
    }

    public function exportOne(Request $request, string $borrower): StreamedResponse
    {
        abort_unless($request->user()->canPerform('borrowers.export'), 403);
        $borrower = Borrower::query()->with('documents')->findOrFail($borrower);
        $this->audit($request, 'borrower.exported', $borrower);

        return $this->csv('borrower-'.$borrower->id.'.csv', collect([$borrower]));
    }

    private function query(Request $request): Builder
    {
        $query = Borrower::query();
        if ($search = $request->string('search')->trim()->toString()) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where(fn ($q) => $q->where('full_name', 'like', "%{$escaped}%")
                ->orWhere('phone_number', 'like', "%{$escaped}%")
                ->orWhere('nin', 'like', "%{$escaped}%"));
        }

        return $query;
    }

    private function rules(?string $ignore = null): array
    {
        $adult = now()->subYears(18)->toDateString();
        $ninUnique = 'unique:tenant.borrowers,nin'.($ignore ? ','.$ignore : '');

        return [
            'branch_id' => ['required', 'uuid', 'exists:tenant.branches,id'],
            'borrower_type' => ['required', 'in:individual,sole_trader'],
            'full_name' => ['required', 'string', 'max:150'], 'date_of_birth' => ['required', 'date', 'before_or_equal:'.$adult],
            'phone_number' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'], 'id_type' => ['required', 'in:national_id,passport,refugee_id'],
            'nin' => ['required', 'string', 'max:30', $ninUnique], 'address' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:100'], 'sub_county' => ['nullable', 'string', 'max:100'],
            'parish' => ['nullable', 'string', 'max:100'], 'village' => ['nullable', 'string', 'max:100'],
            'lc1_reference' => ['nullable', 'string', 'max:150'], 'occupation' => ['required', 'string', 'max:120'],
            'organization_name' => ['nullable', 'string', 'max:150'],
            'average_monthly_income' => ['required', 'integer', 'min:0'], 'repayment_source' => ['required', 'string', 'max:1000'],
            'next_of_kin' => ['required', 'string', 'max:150'], 'next_of_kin_relationship' => ['required', 'string', 'max:80'],
            'next_of_kin_phone' => ['required', 'string', 'max:30'],
            'next_of_kin_address' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
            'document_ids' => ['sometimes', 'array'], 'document_ids.*' => ['uuid'],
            'consent_confirmed' => ['accepted'],
        ];
    }

    private function assertIdentityDocuments(Request $request, array $data, ?Borrower $borrower = null): void
    {
        $required = match ($data['id_type']) {
            'passport' => ['passport'],
            'refugee_id' => ['refugee_id_front', 'refugee_id_back'],
            default => ['national_id_front', 'national_id_back'],
        };
        $ids = $data['document_ids'] ?? [];
        $categories = $borrower ? $borrower->documents()->pluck('category') : collect();
        if ($ids !== []) {
            $categories = $categories->merge(Document::query()
                ->whereIn('id', $ids)
                ->where('documentable_type', 'temporary')
                ->where('documentable_id', $request->user()->id)
                ->pluck('category'));
        }
        $categories = $categories->unique()->all();
        $missing = array_values(array_diff($required, $categories));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'document_ids' => 'Upload '.implode(' and ', array_map(fn ($category) => str_replace('_', ' ', $category), $missing)).' before saving the borrower.',
            ]);
        }
    }

    private function attachDocuments(Request $request, Borrower $borrower, array $ids): void
    {
        if ($ids === []) return;
        Document::query()->whereIn('id', $ids)->where('documentable_type', 'temporary')
            ->where('documentable_id', $request->user()->id)
            ->update(['documentable_type' => Borrower::class, 'documentable_id' => $borrower->id]);
    }

    private function audit(Request $request, string $action, ?Borrower $borrower, array $only = [], array $old = [], array $values = []): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()->id, 'action' => $action, 'entity_type' => $borrower ? Borrower::class : 'borrower_export',
            'entity_id' => $borrower?->id ?? 'bulk', 'old_values' => $old ?: null,
            'new_values' => $values ?: ($borrower ? ($only ? $borrower->only($only) : ['full_name' => $borrower->full_name]) : null),
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }

    private function csv(string $name, $rows): StreamedResponse
    {
        $headers = ['ID', 'Type', 'Full name', 'Date of birth', 'Phone', 'Email', 'ID type', 'NIN / ID number', 'Address', 'District', 'Sub-county', 'Parish', 'Village', 'Occupation', 'Organization / company', 'Average monthly income (UGX)', 'Repayment source', 'Next of kin', 'Relationship', 'Next of kin phone', 'Document checklist', 'Consent date'];

        return response()->streamDownload(function () use ($rows, $headers) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            foreach ($rows as $row) {
                $values = [$row->id, $row->borrower_type, $row->full_name, $row->date_of_birth?->toDateString(), $row->phone_number, $row->email, $row->id_type, $row->nin, $row->address, $row->district, $row->sub_county, $row->parish, $row->village, $row->occupation, $row->organization_name, $row->average_monthly_income, $row->repayment_source, $row->next_of_kin, $row->next_of_kin_relationship, $row->next_of_kin_phone, $row->documents->pluck('category')->unique()->sort()->implode('|'), $row->consent_given_at?->toIso8601String()];
                fputcsv($output, array_map(fn ($value) => is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value, $values));
            }
            fclose($output);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
