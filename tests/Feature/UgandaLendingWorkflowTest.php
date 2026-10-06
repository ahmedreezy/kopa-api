<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UgandaLendingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_locked_loan_can_be_issued_paid_and_receipted(): void
    {
        $workspace = app(TenantProvisioningService::class)->provision(['name' => 'Kopa Test Capital'], [
            'name' => 'Test Owner', 'email' => 'owner@kopa.test', 'password' => 'password',
        ]);
        $login = $this->postJson('/api/auth/login', [
            'tenant' => 'kopa-test-capital', 'email' => 'owner@kopa.test', 'password' => 'password',
        ])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token'), 'X-Tenant' => 'kopa-test-capital'];
        $identityDocuments = $this->temporaryDocuments($workspace['user']->id, ['national_id_front', 'national_id_back']);

        $borrower = $this->withHeaders($headers)->postJson('/api/borrowers', [
            'branch_id' => $workspace['user']->branch_id, 'borrower_type' => 'individual',
            'full_name' => 'Sarah Nakato', 'date_of_birth' => '1992-05-20', 'phone_number' => '0772123456',
            'email' => null, 'id_type' => 'national_id', 'nin' => 'CM920001234567',
            'address' => 'Kasangati, Wakiso', 'district' => 'Wakiso', 'sub_county' => 'Nangabo',
            'occupation' => 'Market trader', 'organization_name' => null, 'average_monthly_income' => 900000,
            'repayment_source' => 'Retail shop proceeds',
            'next_of_kin' => 'Peter Kato', 'next_of_kin_relationship' => 'Brother',
            'next_of_kin_phone' => '0701000000', 'document_ids' => $identityDocuments, 'consent_confirmed' => true,
        ])->assertCreated();

        $product = $this->withHeaders($headers)->postJson('/api/loan-products', [
            'name' => 'Trader Working Capital', 'is_active' => true,
            'principal_amount' => 500000,
            'interest_rate' => 2.8, 'interest_period' => 'monthly', 'interest_method' => 'simple',
            'duration' => 2, 'duration_unit' => 'months',
            'repayment_frequencies' => ['weekly', 'monthly'], 'processing_fee_type' => 'percentage', 'processing_fee_value' => 2,
            'minimum_guarantors' => 0, 'collateral_required' => false, 'required_documents' => ['identity_document'],
        ])->assertCreated();
        $this->assertMatchesRegularExpression('/^LP-[A-Z0-9]{6}$/', $product->json('data.code'));

        $terms = [
            'loan_product_id' => $product->json('data.id'), 'borrower_id' => $borrower->json('id'),
            'branch_id' => $workspace['user']->branch_id, 'principal_amount' => 500000,
            'duration' => 2, 'duration_unit' => 'months', 'repayment_frequency' => 'monthly',
            'disbursement_date' => '2026-09-14', 'first_repayment_date' => today()->toDateString(),
            'purpose' => 'Purchase shop inventory', 'source_of_repayment' => 'Retail shop proceeds',
            'declared_disposable_income' => 500000,
        ];
        $this->withHeaders($headers)->postJson('/api/loans/calculate', array_merge($terms, ['principal_amount' => 400000]))
            ->assertUnprocessable()->assertJsonValidationErrors('principal_amount');
        $this->withHeaders($headers)->postJson('/api/loans/calculate', array_merge($terms, ['duration' => 3]))
            ->assertUnprocessable()->assertJsonValidationErrors('duration');
        $quote = $this->withHeaders($headers)->postJson('/api/loans/calculate', $terms)
            ->assertOk()->assertJsonPath('total_interest', 28000)->assertJsonPath('fees_amount', 10000)
            ->assertJsonPath('net_disbursement_amount', 490000)->assertJsonPath('total_payable', 528000);
        $loan = $this->withHeaders($headers)->postJson('/api/loans', $terms + ['terms_confirmed' => true])
            ->assertCreated()->assertJsonPath('total_payable', $quote->json('total_payable'))
            ->assertJsonPath('net_disbursement_amount', 490000);

        $queue = $this->withHeaders($headers)->getJson('/api/collections?view=due_today')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($headers)->postJson('/api/collections/'.$loan->json('schedules.1.id').'/collect')
            ->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $payment = $this->withHeaders($headers)->postJson('/api/collections/'.$queue->json('data.0.id').'/collect')
            ->assertCreated()->assertJsonPath('amount', $quote->json('installment_amount'))
            ->assertJsonStructure(['receipt' => ['id', 'receipt_number', 'snapshot']]);
        $this->withHeaders($headers)->getJson('/api/collections?view=due_today')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->withHeaders($headers)->getJson('/api/receipts/'.$payment->json('receipt.id'))
            ->assertOk()->assertJsonPath('snapshot.borrower.name', 'Sarah Nakato')
            ->assertJsonPath('snapshot.payment.method', 'cash');
        $this->withHeaders($headers)->get('/api/borrowers/'.$borrower->json('id').'/export.csv')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_product_creation_ignores_license_rate_guidelines(): void
    {
        app(TenantProvisioningService::class)->provision(['name' => 'Cap Test Loans'], [
            'name' => 'Test Owner', 'email' => 'cap@kopa.test', 'password' => 'password',
        ]);
        $login = $this->postJson('/api/auth/login', [
            'tenant' => 'cap-test-loans', 'email' => 'cap@kopa.test', 'password' => 'password',
        ])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token'), 'X-Tenant' => 'cap-test-loans'];

        $this->withHeaders($headers)->postJson('/api/loan-products', [
            'name' => 'Non-compliant product', 'is_active' => true,
            'principal_amount' => 500000,
            'interest_rate' => 3, 'interest_period' => 'monthly', 'interest_method' => 'simple',
            'duration' => 3, 'duration_unit' => 'months',
            'repayment_frequencies' => ['monthly'], 'processing_fee_type' => 'fixed', 'processing_fee_value' => null,
            'minimum_guarantors' => 0, 'collateral_required' => false, 'required_documents' => [],
        ])->assertCreated();
    }

    public function test_borrower_registration_uses_simplified_fields_and_matching_identity_documents(): void
    {
        $workspace = app(TenantProvisioningService::class)->provision(['name' => 'Identity Test Capital'], [
            'name' => 'Identity Owner', 'email' => 'identity@kopa.test', 'password' => 'password',
        ]);
        $login = $this->postJson('/api/auth/login', [
            'tenant' => 'identity-test-capital', 'email' => 'identity@kopa.test', 'password' => 'password',
        ])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token'), 'X-Tenant' => 'identity-test-capital'];

        $payload = [
            'branch_id' => $workspace['user']->branch_id, 'borrower_type' => 'individual',
            'full_name' => 'Amina Nambasa', 'date_of_birth' => '1990-02-10', 'phone_number' => '0772000001',
            'email' => null, 'id_type' => 'passport', 'nin' => 'UG1234567',
            'address' => 'Kawempe', 'district' => 'Kampala', 'lc1_reference' => null,
            'occupation' => 'Consultant', 'organization_name' => null, 'average_monthly_income' => 1200000,
            'repayment_source' => 'Consulting income', 'next_of_kin' => 'Musa Nambasa',
            'next_of_kin_relationship' => 'Brother', 'next_of_kin_phone' => '0701000001',
            'consent_confirmed' => true,
        ];

        $passportDocuments = $this->temporaryDocuments($workspace['user']->id, ['passport']);
        $this->withHeaders($headers)->postJson('/api/borrowers', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('document_ids');

        $borrower = $this->withHeaders($headers)->postJson('/api/borrowers', $payload + ['document_ids' => $passportDocuments])
            ->assertCreated()
            ->assertJsonPath('email', null)
            ->assertJsonPath('lc1_reference', null)
            ->assertJsonPath('organization_name', null)
            ->assertJsonPath('average_monthly_income', 1200000)
            ->assertJsonMissingPath('alternative_phone')
            ->assertJsonMissingPath('monthly_expenses')
            ->assertJsonMissingPath('next_of_kin_alternative_phone');

        $incomplete = $payload;
        unset($incomplete['occupation'], $incomplete['average_monthly_income'], $incomplete['repayment_source']);
        $incomplete['nin'] = 'UG1234568';
        $incomplete['document_ids'] = $this->temporaryDocuments($workspace['user']->id, ['passport']);
        $this->withHeaders($headers)->postJson('/api/borrowers', $incomplete)
            ->assertUnprocessable()->assertJsonValidationErrors(['occupation', 'average_monthly_income', 'repayment_source']);

        $update = $payload + ['document_ids' => $passportDocuments];
        $update['id_type'] = 'refugee_id';
        $this->withHeaders($headers)->putJson('/api/borrowers/'.$borrower->json('id'), $update)
            ->assertUnprocessable()->assertJsonValidationErrors('document_ids');

        $refugeeDocuments = $this->temporaryDocuments($workspace['user']->id, ['refugee_id_front', 'refugee_id_back']);
        $update['document_ids'] = array_merge($passportDocuments, $refugeeDocuments);
        $this->withHeaders($headers)->putJson('/api/borrowers/'.$borrower->json('id'), $update)
            ->assertOk()->assertJsonPath('id_type', 'refugee_id');

        $csv = $this->withHeaders($headers)->get('/api/borrowers/'.$borrower->json('id').'/export.csv')->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Organization / company', $content);
        $this->assertStringContainsString('Average monthly income (UGX)', $content);
        $this->assertStringNotContainsString('Alternative phone', $content);
        $this->assertStringNotContainsString('Disposable income (UGX)', $content);
    }

    private function temporaryDocuments(string $userId, array $categories): array
    {
        return collect($categories)->map(function (string $category) use ($userId) {
            return Document::query()->create([
                'documentable_type' => 'temporary', 'documentable_id' => $userId,
                'category' => $category, 'disk' => 'local', 'path' => 'tests/'.Str::uuid().'.jpg',
                'original_name' => $category.'.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
                'uploaded_by' => $userId,
            ])->id;
        })->all();
    }
}
