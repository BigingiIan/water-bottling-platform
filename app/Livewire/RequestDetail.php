<?php

namespace App\Livewire;

use App\Models\DepartmentRequest;
use App\Models\User;
use App\Services\Requests\RequestAccess;
use App\Services\Requests\RequestContext;
use App\Services\Requests\RequestService;
use App\Services\Requests\RequestStatus;
use App\Services\Requests\RequestType;
use App\Support\ModuleAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.app-layout', ['title' => 'Request', 'breadcrumbSection' => 'Requests', 'activeItem' => 'Requests'])]
class RequestDetail extends Component
{
    #[Locked]
    public int $requestId;

    public string $commentBody = '';

    /**
     * The status waiting for a resolution note before it is applied (done or declined).
     */
    public ?string $pendingStatus = null;

    public string $resolutionNote = '';

    public function mount(DepartmentRequest $departmentRequest, RequestAccess $access): void
    {
        $access->authorizeView($this->currentUser(), $departmentRequest);
        $this->requestId = $departmentRequest->id;
    }

    public function changeStatus(string $status, RequestService $requests): void
    {
        $targetStatus = RequestStatus::tryFrom($status);
        abort_if($targetStatus === null, 422);

        if (in_array($targetStatus, [RequestStatus::Done, RequestStatus::Declined], true) && $this->pendingStatus !== $targetStatus->value) {
            $this->resetValidation();
            $this->pendingStatus = $targetStatus->value;
            $this->resolutionNote = '';

            return;
        }

        $this->resetValidation();
        $requests->changeStatus($this->currentUser(), $this->departmentRequest(), $targetStatus, $this->resolutionNote);
        $this->reset(['pendingStatus', 'resolutionNote']);
    }

    public function cancelPendingStatus(): void
    {
        $this->resetValidation();
        $this->reset(['pendingStatus', 'resolutionNote']);
    }

    public function cancelRequest(RequestService $requests): void
    {
        $requests->cancel($this->currentUser(), $this->departmentRequest());
    }

    public function reopenRequest(RequestService $requests): void
    {
        $requests->reopen($this->currentUser(), $this->departmentRequest());
    }

    public function assignToMe(RequestService $requests): void
    {
        $requests->assignToSelf($this->currentUser(), $this->departmentRequest());
    }

    public function addComment(RequestService $requests): void
    {
        $this->resetValidation();
        $requests->comment($this->currentUser(), $this->departmentRequest(), $this->commentBody);
        $this->reset('commentBody');
    }

    public function render(RequestAccess $access, RequestContext $context): View
    {
        $user = $this->currentUser();
        $request = $this->departmentRequest()->load(['sender.role', 'toRole', 'assignee', 'comments.author.role']);

        return view('livewire.request-detail', [
            'request' => $request,
            'liveUrl' => $context->liveUrlFor($user, $request),
            'canManage' => $access->canManage($user, $request),
            'canComment' => $access->canComment($user, $request),
            'canCancel' => $access->canCancel($user, $request),
            'canReopen' => $access->canReopen($user, $request),
            'canReviewAccess' => $request->type === RequestType::AccessRequest && app(ModuleAccess::class)->can($user, 'users-roles', write: true),
            'transitions' => $access->canManage($user, $request) ? $request->status->recipientTransitions() : [],
        ])->title($request->reference.' · Requests');
    }

    /**
     * Re-resolved on every call so visibility is checked against the current role, not the one at mount.
     */
    private function departmentRequest(): DepartmentRequest
    {
        $request = DepartmentRequest::query()->findOrFail($this->requestId);
        app(RequestAccess::class)->authorizeView($this->currentUser(), $request);

        return $request;
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
