<?php
namespace App\Http\Requests\Finance;
use Illuminate\Foundation\Http\FormRequest;
class ExpenseRequest extends FormRequest { public function authorize(): bool{return true;} public function rules(): array { return ['category'=>['required','string','max:100'],'expense_date'=>['required','date_format:Y-m-d'],'amount'=>['required','numeric','gt:0','max:9999999999999999.99'],'payment_method'=>['required','in:cash,bank'],'cashbox_id'=>['required_if:payment_method,cash','nullable','integer','exists:cashboxes,id'],'bank_account_id'=>['required_if:payment_method,bank','nullable','integer','exists:bank_accounts,id'],'branch_id'=>['nullable','integer','exists:branches,id'],'employee_id'=>['nullable','integer','exists:employees,id'],'description'=>['nullable','string','max:1000']]; } }
