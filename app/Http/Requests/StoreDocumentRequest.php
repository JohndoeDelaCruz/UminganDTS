<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('received_at')) && $this->input('received_at') !== '') {
            try {
                $this->merge(['received_at' => Carbon::parse($this->input('received_at'), 'Asia/Manila')->utc()->toIso8601String()]);
            } catch (\Exception) {
                // Invalid input is handled by the date validation rule.
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('create', Document::class);
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:5000'],
            'document_type_id' => ['required', Rule::exists('document_types', 'id')->where('is_active', true)],
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'priority' => ['required', Rule::in(['normal', 'urgent'])],
            'sender_name' => ['required', 'string', 'max:180'],
            'contact_private' => ['nullable', 'string', 'max:1000'],
            'originating_reference' => ['nullable', 'string', 'max:100'],
            'confidentiality' => ['required', Rule::in(['internal', 'confidential'])],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
