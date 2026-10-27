<?php

namespace App\Services\Requests;

use App\Models\AuditLog;
use App\Models\DepartmentRequest;
use App\Models\Module;
use App\Models\RequestComment;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Support\YearlySequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates requests and moves them through their lifecycle. Every change is checked against RequestAccess,
 * audited, and announced through NotificationService.
 */
class RequestService
{
    public function __construct(
        private RequestAccess $access,
        private RequestContext $context,
        private YearlySequence $sequence,
        private SettingsService $settings,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array{type?: mixed, subject?: mixed, body?: mixed, priority?: mixed, to_role_id?: mixed, due_date?: mixed, related_type?: mixed, related_id?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function create(User $sender, array $input): DepartmentRequest
    {
        abort_unless($this->access->canSendRequests($sender), 403);

        $validated = Validator::make($input, [
            'type' => ['required', Rule::in(array_map(fn (RequestType $type): string => $type->value, RequestType::selectable()))],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::enum(RequestPriority::class)],
            'to_role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'related_type' => ['nullable', 'required_with:related_id', Rule::in(array_keys(RequestContext::RELATED_TYPES))],
            'related_id' => ['nullable', 'required_with:related_type', 'integer'],
        ], [
            'to_role_id.required' => 'Choose the department to send this to.',
        ])->validate();

        // Built from the record on the server, so the client can never choose what the recipient sees.
        $snapshot = isset($validated['related_type'])
            ? $this->context->snapshotFor($sender, $validated['related_type'], (int) $validated['related_id'])
            : null;

        return $this->store($sender, [
            'type' => $validated['type'],
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'priority' => $validated['priority'],
            'to_role_id' => (int) $validated['to_role_id'],
            'due_date' => $validated['due_date'] ?? $this->defaultDueDate(),
            'related_type' => $validated['related_type'] ?? null,
            'related_id' => isset($validated['related_id']) ? (int) $validated['related_id'] : null,
            'context_snapshot' => $snapshot,
        ]);
    }

    /**
     * An access request from the 403 page, addressed to administrators. Reuses the requester's open
     * request for the same module instead of creating a duplicate.
     */
    public function requestAccess(User $requester, Module $module, ?string $attemptedPage): DepartmentRequest
    {
        $administratorRoleId = Role::query()->where('slug', 'administrator')->valueOrFail('id');
        $existing = DepartmentRequest::query()
            ->where('type', RequestType::AccessRequest->value)
            ->where('from_user_id', $requester->id)
            ->whereIn('status', RequestStatus::activeValues())
            ->where('context_snapshot->module', $module->slug)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $page = $this->safePath($attemptedPage);

        return $this->store($requester, [
            'type' => RequestType::AccessRequest->value,
            'subject' => "Access to {$module->name}",
            'body' => "{$requester->name} asked for access to {$module->name}."
                .($page !== null ? " They were trying to open {$page}." : '')
                .' Review the role in Roles & Permissions; access is not granted automatically.',
            'priority' => RequestPriority::Normal->value,
            'to_role_id' => $administratorRoleId,
            'due_date' => $this->defaultDueDate(),
            'related_type' => null,
            'related_id' => null,
            'context_snapshot' => [
                'type' => 'access_request',
                'module' => $module->slug,
                'label' => "Access request for {$module->name}",
                'fields' => [
                    ['label' => 'Requested by', 'value' => $requester->name],
                    ['label' => 'Current role', 'value' => $requester->role?->name ?? 'Unassigned'],
                    ['label' => 'Module', 'value' => $module->name],
                    ['label' => 'Page', 'value' => $page ?? 'Not recorded'],
                ],
                'captured_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function changeStatus(User $actor, DepartmentRequest $request, RequestStatus $status, ?string $note = null): DepartmentRequest
    {
        abort_unless($this->access->canManage($actor, $request), 403);

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($status === RequestStatus::Declined && $note === null) {
            throw ValidationException::withMessages(['resolutionNote' => 'Say why the request is declined so the sender knows what to do next.']);
        }

        if ($note !== null && mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['resolutionNote' => 'Keep the note under 2,000 characters.']);
        }

        return $this->transition($actor, $request, $status, function (DepartmentRequest $locked, RequestStatus $from) use ($status, $note): void {
            if (! in_array($status, $from->recipientTransitions(), true)) {
                throw ValidationException::withMessages(['status' => "A {$from->label()} request cannot be marked {$status->label()}."]);
            }

            $locked->status = $status;

            if (in_array($status, [RequestStatus::Done, RequestStatus::Declined], true)) {
                $locked->resolved_at = now();
                $locked->resolution_note = $note;
            }
        });
    }

    public function cancel(User $sender, DepartmentRequest $request): DepartmentRequest
    {
        abort_unless($this->access->canCancel($sender, $request), 403);

        return $this->transition($sender, $request, RequestStatus::Cancelled, function (DepartmentRequest $locked, RequestStatus $from): void {
            abort_unless($from === RequestStatus::Open, 409, 'Only open requests can be cancelled.');
            $locked->status = RequestStatus::Cancelled;
            $locked->resolved_at = now();
        });
    }

    public function reopen(User $sender, DepartmentRequest $request): DepartmentRequest
    {
        abort_unless($this->access->canReopen($sender, $request), 403);

        return $this->transition($sender, $request, RequestStatus::Open, function (DepartmentRequest $locked, RequestStatus $from): void {
            abort_unless($from === RequestStatus::Declined && $locked->reopened_at === null, 409, 'This request was already reopened once.');
            $locked->status = RequestStatus::Open;
            $locked->reopened_at = now();
            $locked->resolved_at = null;
        });
    }

    public function assignToSelf(User $actor, DepartmentRequest $request): DepartmentRequest
    {
        abort_unless($this->access->canManage($actor, $request) && $request->status->isActive(), 403);

        $request->forceFill(['assigned_to_user_id' => $actor->id])->save();

        return $request;
    }

    /**
     * @throws ValidationException
     */
    public function comment(User $author, DepartmentRequest $request, string $body): RequestComment
    {
        abort_unless($this->access->canComment($author, $request), 403);

        $validated = Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:5000']])->validate();
        $comment = RequestComment::query()->create([
            'request_id' => $request->id,
            'user_id' => $author->id,
            'body' => trim($validated['body']),
        ]);
        $request->touch();
        $this->notifications->requestCommented($request, $comment, $author);

        return $comment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(User $sender, array $attributes): DepartmentRequest
    {
        $request = DB::transaction(function () use ($sender, $attributes): DepartmentRequest {
            $request = new DepartmentRequest([
                ...$attributes,
                'reference' => $this->sequence->next('request_number_sequences', 'REQ', (int) now()->format('Y')),
                'from_user_id' => $sender->id,
            ]);
            $request->status = RequestStatus::Open;
            $request->save();

            AuditLog::query()->create([
                'user_id' => $sender->id,
                'action' => 'requests.created',
                'table_name' => 'requests',
                'old_values' => null,
                'new_values' => [
                    'reference' => $request->reference,
                    'type' => $request->type->value,
                    'to_role_id' => $request->to_role_id,
                    'related_type' => $request->related_type,
                    'related_id' => $request->related_id,
                ],
            ]);

            return $request;
        });

        $this->notifications->requestCreated($request, $sender);

        return $request;
    }

    /**
     * @param  callable(DepartmentRequest, RequestStatus): void  $apply
     */
    private function transition(User $actor, DepartmentRequest $request, RequestStatus $status, callable $apply): DepartmentRequest
    {
        [$updated, $from] = DB::transaction(function () use ($actor, $request, $status, $apply): array {
            $locked = DepartmentRequest::query()->lockForUpdate()->findOrFail($request->id);
            $from = $locked->status;
            $apply($locked, $from);
            $locked->save();

            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => $status === RequestStatus::Declined ? 'requests.declined' : 'requests.status_changed',
                'table_name' => 'requests',
                'old_values' => ['reference' => $locked->reference, 'status' => $from->value],
                'new_values' => ['reference' => $locked->reference, 'status' => $status->value, 'note' => $locked->resolution_note],
            ]);

            return [$locked, $from];
        });

        $this->notifications->requestStatusChanged($updated, $from, $actor);

        return $updated;
    }

    private function defaultDueDate(): string
    {
        return today()->addDays(max(0, (int) $this->settings->get('requests.default_due_days', 2)))->toDateString();
    }

    /**
     * Keeps only a same-site path, so a forged "page" value cannot smuggle an external link into the thread.
     */
    private function safePath(?string $page): ?string
    {
        if ($page === null || $page === '') {
            return null;
        }

        $path = parse_url($page, PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//')
            ? mb_substr($path, 0, 200)
            : null;
    }
}
