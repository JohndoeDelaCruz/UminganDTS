<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentTransfer;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ConfigurationController extends Controller
{
    public function departments()
    {
        return Inertia::render('admin/configuration', ['kind' => 'departments', 'records' => Department::orderBy('name')->paginate(15)]);
    }

    public function types()
    {
        return Inertia::render('admin/configuration', ['kind' => 'types', 'records' => DocumentType::orderBy('name')->paginate(15)]);
    }

    public function users()
    {
        return Inertia::render('admin/users', ['users' => User::with('department')->orderBy('name')->paginate(15), 'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function saveDepartment(Request $request, ?Department $department = null)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('departments')->ignore($department?->id)],
            'name' => ['required', 'string', 'max:150'], 'is_active' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $department, $data) {
            if ($department) {
                $department = Department::lockForUpdate()->findOrFail($department->id);
                if (! $data['is_active'] && (User::where('department_id', $department->id)->where('is_active', true)->exists() || Document::where('current_department_id', $department->id)->whereNotIn('status', ['completed', 'released', 'cancelled'])->exists() || DocumentTransfer::where('to_department_id', $department->id)->where('status', 'pending')->exists())) {
                    throw ValidationException::withMessages(['is_active' => 'Reassign active staff, open documents and pending handoffs before deactivating this office.']);
                }
                $department->update($data);
            } else {
                $department = Department::create($data);
            }
            AuditLog::record($request->user(), 'department.saved', $department);
        }, 3);

        return to_route('departments')->with('success', 'Department saved.');
    }

    public function saveType(Request $request, ?DocumentType $type = null)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('document_types')->ignore($type?->id)],
            'name' => ['required', 'string', 'max:150'], 'is_active' => ['required', 'boolean'],
            'default_processing_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        DB::transaction(function () use ($request, $type, $data) {
            if ($type) {
                $type->update($data);
            } else {
                $type = DocumentType::create($data);
            }
            AuditLog::record($request->user(), 'document_type.saved', $type);
        });

        return to_route('types')->with('success', 'Document category saved. Targets apply to newly registered documents.');
    }

    public function saveUser(Request $request, ?User $user = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in(['admin', 'staff'])],
            'department_id' => ['nullable', 'required_if:role,staff', Rule::exists('departments', 'id')->where('is_active', true)],
            'is_active' => ['required', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
        ]);
        DB::transaction(function () use ($request, $user, $data) {
            $user = $user ? User::lockForUpdate()->findOrFail($user->id) : null;
            if ($user?->id === $request->user()->id && (! $data['is_active'] || $data['role'] !== 'admin')) {
                throw ValidationException::withMessages(['role' => 'You cannot deactivate or demote your own administrator account.']);
            }
            if (empty($data['password'])) {
                unset($data['password']);
            }
            $changedPassword = isset($data['password']);
            if ($user) {
                $data['remember_token'] = Str::random(60);
                $user->forceFill($data)->save();
                DB::table('sessions')->where('user_id', $user->id)->when($user->id === $request->user()->id, fn ($q) => $q->where('id', '!=', $request->session()->getId()))->delete();
            } else {
                $user = User::create($data);
            }
            AuditLog::record($request->user(), 'user.saved', $user, ['role' => $user->role, 'active' => $user->is_active, 'password_changed' => $changedPassword]);
        }, 3);

        return to_route('users')->with('success', 'Account saved.');
    }

    public function audit(Request $request)
    {
        return Inertia::render('admin/audit', ['entries' => AuditLog::query()->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')->select('audit_logs.*', 'users.name as actor_name')->latest('audit_logs.id')->paginate(30)]);
    }
}
