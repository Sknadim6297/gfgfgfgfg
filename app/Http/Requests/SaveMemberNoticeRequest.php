<?php

namespace App\Http\Requests;

use App\Models\MemberNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveMemberNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'type' => ['required', 'string', Rule::in(MemberNotice::TYPES)],
            'status' => ['required', 'string', Rule::in(MemberNotice::STATUSES)],
            'priority' => ['required', 'string', Rule::in(MemberNotice::PRIORITIES)],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'popup_enabled' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $publishAt = $this->input('publish_at');
            $expiresAt = $this->input('expires_at');

            if (! is_string($publishAt) || $publishAt === '' || ! is_string($expiresAt) || $expiresAt === '') {
                return;
            }

            if (strtotime($expiresAt) === false || strtotime($publishAt) === false || strtotime($expiresAt) <= strtotime($publishAt)) {
                $validator->errors()->add('expires_at', 'The expiry date must be after the publish date.');
            }
        });
    }

    public function noticeAttributes(): array
    {
        $validated = $this->validated();

        return [
            'title' => $validated['title'],
            'description' => $validated['description'],
            'type' => $validated['type'],
            'status' => $validated['status'],
            'priority' => $validated['priority'],
            'publish_at' => $validated['publish_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'popup_enabled' => $this->boolean('popup_enabled'),
        ];
    }
}
