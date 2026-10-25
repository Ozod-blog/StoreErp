<?php
namespace App\Http\Requests\Finance;
use Illuminate\Foundation\Http\FormRequest; use Illuminate\Validation\Rule;
class BankAccountRequest extends FormRequest { public function authorize(): bool{return true;} public function rules(): array { $id=$this->route('bank_account')?->id; return ['name'=>['required','string','max:120'],'account_number'=>['required','string','max:64',Rule::unique('bank_accounts','account_number')->ignore($id)],'currency'=>['required','string','size:3','alpha','in:UZS,USD,EUR'],'branch_id'=>['nullable','integer','exists:branches,id'],'opening_balance'=>['sometimes','numeric','min:0','max:9999999999999999.99'],'is_active'=>['sometimes','boolean']]; } }
