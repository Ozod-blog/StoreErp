<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\CancelPaymentRequest;
use App\Http\Requests\Parties\PaymentRequest;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AuditLogger;
use App\Services\DebtPaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

/** Qarz to'lovlari (mijozdan qabul qilish, yetkazib beruvchiga to'lash) va bekor qilish. */
class PartyPaymentController extends Controller
{
    public function __construct(private DebtPaymentService $service)
    {
    }

    public function storeCustomer(PaymentRequest $request, Customer $customer): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(
            fn () => $this->service->receiveFromCustomer($customer->id, $data, $request->user()),
            fn () => CustomerPayment::where('client_token', $data['client_token'] ?? '-')->first(),
            $request->user()->id, $data['client_token'] ?? null, 'customer_payments.create', $customer,
        );
    }

    public function storeSupplier(PaymentRequest $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(
            fn () => $this->service->payToSupplier($supplier->id, $data, $request->user()),
            fn () => SupplierPayment::where('client_token', $data['client_token'] ?? '-')->first(),
            $request->user()->id, $data['client_token'] ?? null, 'supplier_payments.create', $supplier,
        );
    }

    public function cancelCustomer(CancelPaymentRequest $request, int $id): JsonResponse
    {
        $payment = CustomerPayment::findOrFail($id);
        try {
            return response()->json($this->service->cancelCustomerPayment($id, $request->validated('reason'), $request->user()));
        } catch (BusinessRuleException $e) {
            AuditLogger::log('customer_payments.cancel', $payment, null, ['number' => $payment->number, 'error' => $e->getMessage()], 'failed');

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancelSupplier(CancelPaymentRequest $request, int $id): JsonResponse
    {
        $payment = SupplierPayment::findOrFail($id);
        try {
            return response()->json($this->service->cancelSupplierPayment($id, $request->validated('reason'), $request->user()));
        } catch (BusinessRuleException $e) {
            AuditLogger::log('supplier_payments.cancel', $payment, null, ['number' => $payment->number, 'error' => $e->getMessage()], 'failed');

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Idempotency: bir xil client_token bilan qayta yuborilgan so'rov ikkinchi to'lov yaratmaydi. */
    private function guarded(callable $create, callable $findByToken, int $userId, ?string $token, string $action, $subject): JsonResponse
    {
        $existing = $token ? $findByToken() : null;
        if ($existing) {
            return $this->replay($existing, $userId);
        }

        try {
            return response()->json($create(), 201);
        } catch (BusinessRuleException $e) {
            AuditLogger::log($action, $subject, null, ['error' => $e->getMessage()], 'failed');

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $token ? $findByToken() : null;
            if (! $existing) {
                throw $e;
            }

            return $this->replay($existing, $userId);
        }
    }

    private function replay($payment, int $userId): JsonResponse
    {
        if ((int) $payment->created_by !== $userId) {
            return response()->json(['message' => 'Bu so‘rov identifikatori boshqa foydalanuvchiga tegishli.'], 409);
        }

        return response()->json($payment);
    }
}
