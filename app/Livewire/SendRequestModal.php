<?php

namespace App\Livewire;

use App\Models\Role;
use App\Models\User;
use App\Services\Requests\RequestAccess;
use App\Services\Requests\RequestContext;
use App\Services\Requests\RequestPriority;
use App\Services\Requests\RequestService;
use App\Services\Requests\RequestType;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Send request" dialog, opened from a record (prefilled with its type and snapshot) or blank.
 */
class SendRequestModal extends Component
{
    public bool $isOpen = false;

    #[Locked]
    public ?string $relatedType = null;

    #[Locked]
    public ?int $relatedId = null;

    public string $type = 'general';

    public string $toRoleId = '';

    public string $priority = 'normal';

    public string $subject = '';

    public string $body = '';

    public string $dueDate = '';

    public ?string $sentReference = null;

    public ?string $sentUrl = null;

    #[On('open-request-modal')]
    public function open(?string $relatedType = null, ?int $relatedId = null): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(RequestAccess::class)->canSendRequests($user), 403);
        $context = app(RequestContext::class);

        $this->resetValidation();
        $this->reset(['toRoleId', 'priority', 'subject', 'body', 'sentReference', 'sentUrl']);
        $this->relatedType = null;
        $this->relatedId = null;
        $this->type = RequestType::General->value;

        if ($relatedType !== null && $relatedId !== null) {
            $snapshot = $context->snapshotFor($user, $relatedType, $relatedId);
            $this->relatedType = $relatedType;
            $this->relatedId = $relatedId;
            $this->type = $context->suggestedType($relatedType)->value;
            $this->subject = mb_substr('About '.$snapshot['label'], 0, 160);
        }

        $this->dueDate = today()->addDays(max(0, (int) app(SettingsService::class)->get('requests.default_due_days', 2)))->toDateString();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function send(RequestService $requests): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $request = $requests->create($user, [
            'type' => $this->type,
            'subject' => trim($this->subject),
            'body' => trim($this->body),
            'priority' => $this->priority,
            'to_role_id' => $this->toRoleId === '' ? null : (int) $this->toRoleId,
            'due_date' => $this->dueDate === '' ? null : $this->dueDate,
            'related_type' => $this->relatedType,
            'related_id' => $this->relatedId,
        ]);

        $this->sentReference = $request->reference;
        $this->sentUrl = route('requests.show', $request);
        $this->dispatch('request-sent', reference: $request->reference);
    }

    public function render(RequestContext $context): View
    {
        $user = auth()->user();
        $isReady = $this->isOpen && $user instanceof User;

        return view('livewire.send-request-modal', [
            'departments' => $isReady ? Role::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'types' => RequestType::selectable(),
            'priorities' => RequestPriority::cases(),
            'snapshot' => $isReady && $this->relatedType !== null && $this->relatedId !== null && $this->sentReference === null
                ? $context->snapshotFor($user, $this->relatedType, $this->relatedId)
                : null,
        ]);
    }
}
