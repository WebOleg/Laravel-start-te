<?php

/**
 * Admin controller for debtor management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DebtorResource;
use App\Models\Debtor;
use App\Services\DebtorValidationService;
use App\Services\IbanValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class DebtorController extends Controller
{
    private const FIELD_MAP = [
        'iban' => 'iban',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'email' => 'email',
        'phone' => 'phone',
        'telephone' => 'phone',
        'address' => 'address',
        'street' => 'street',
        'street_number' => 'street_number',
        'postcode' => 'postcode',
        'postal_code' => 'postcode',
        'city' => 'city',
        'province' => 'province',
        'country' => 'country',
        'amount' => 'amount',
        'currency' => 'currency',
        'bank_name' => 'bank_name',
        'bic' => 'bic',
        'external_reference' => 'external_reference',
    ];

    public function __construct(
        private DebtorValidationService $validationService,
        private IbanValidator $ibanValidator
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Debtor::with(['upload', 'latestVopLog', 'latestBillingAttempt'])
                    ->leftJoin('vop_logs', 'vop_logs.debtor_id', '=', 'debtors.id')
                    ->leftJoin('bank_references', 'bank_references.bic', '=', 'vop_logs.bic')
                    ->selectRaw('debtors.*, bank_references.bank_name AS bank_name_reference, bank_references.country_iso AS bank_country_iso_reference');

        if ($request->has('upload_id')) {
            $query->where('upload_id', $request->input('upload_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('validation_status')) {
            $query->where('validation_status', $request->input('validation_status'));
        }

        if ($request->has('country')) {
            $query->where('country', $request->input('country'));
        }

        if ($request->has('risk_class')) {
            $query->where('risk_class', $request->input('risk_class'));
        }

        $debtors = $query->latest()->paginate($request->input('per_page', 20));

        return DebtorResource::collection($debtors);
    }

    public function show(Debtor $debtor): DebtorResource
    {
        $debtor->load([
            'upload',
            'vopLogs',
            'billingAttempts',
            'latestVopLog',
            'latestBillingAttempt',
        ]);

        return new DebtorResource($debtor);
    }

    public function update(Request $request, Debtor $debtor): DebtorResource
    {
        $rawData = $request->input('raw_data', []);
        
        $debtor->raw_data = $rawData;
        
        $debtorFields = [];
        foreach ($rawData as $key => $value) {
            $normalizedKey = strtolower(str_replace([' ', '-'], '_', $key));
            if (isset(self::FIELD_MAP[$normalizedKey])) {
                $field = self::FIELD_MAP[$normalizedKey];
                $debtorFields[$field] = $this->castValue($field, $value);
            }
        }

        if (isset($debtorFields['iban'])) {
            $debtorFields['iban'] = $this->ibanValidator->normalize($debtorFields['iban']);
            $debtorFields['iban_hash'] = $this->ibanValidator->hash($debtorFields['iban']);
        }

        $debtor->fill($debtorFields);
        $debtor->save();

        $this->validationService->validateAndUpdate($debtor);
        $debtor->load(['upload', 'latestVopLog', 'latestBillingAttempt']);

        return new DebtorResource($debtor);
    }

    public function validate(Debtor $debtor): JsonResponse
    {
        $this->validationService->validateAndUpdate($debtor);

        return response()->json([
            'message' => 'Validation completed',
            'data' => [
                'id' => $debtor->id,
                'validation_status' => $debtor->validation_status,
                'validation_errors' => $debtor->validation_errors,
                'validated_at' => $debtor->validated_at?->toISOString(),
            ],
        ]);
    }

    public function destroy(Debtor $debtor): JsonResponse
    {
        $debtor->delete();

        return response()->json([
            'message' => 'Debtor deleted successfully',
        ]);
    }

    private function castValue(string $field, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'amount' => (float) str_replace([',', ' '], ['.', ''], $value),
            'country', 'currency' => strtoupper(trim($value)),
            default => trim($value),
        };
    }
}
