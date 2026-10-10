<div class="toast">
    <div @if($id ?? null) id="{{ $id }}" @endif class="notice{{ ($error ?? false) ? ' error-message' : '' }}" role="{{ ($error ?? false) ? 'alert' : 'status' }}" aria-live="{{ ($error ?? false) ? 'assertive' : 'polite' }}" @if($hidden ?? false) hidden @endif @if($transient ?? false) data-transient-notice @endif>{{ $text ?? '' }}</div>
    <button type="button" class="toast-dismiss" data-dismiss-notice aria-label="Dismiss notification"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
</div>
