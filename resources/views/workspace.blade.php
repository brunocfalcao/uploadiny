@extends('layouts.app', ['title' => ($project?->name ?? 'Projects').' · Uploadiny'])
@section('content')
<div class="workspace" data-workspace data-project="{{ $project?->slug }}" data-latest="{{ $chunks->first()?->uuid }}">
    <aside class="sidebar">
        <a class="brand" href="{{ route('projects.index') }}"><span class="brand-symbol" aria-hidden="true">u</span>Uploadiny</a>
        <div class="sidebar-heading"><h2>Projects</h2><button type="button" class="sidebar-add" data-new-project aria-label="Create project">+</button></div>
        <nav aria-label="Projects" class="project-nav">
            @forelse($projects as $entry)
                <a href="{{ route('projects.show', $entry) }}" @class(['project-link', 'active' => $project?->id === $entry->id]) @if($project?->id === $entry->id) aria-current="page" @endif>
                    <span>{{ $entry->name }}</span><span class="project-count">{{ $entry->images_count }}</span>
                </a>
            @empty
                <p class="sidebar-empty">Create a project to start collecting feedback.</p>
            @endforelse
        </nav>
        <div class="sidebar-footer"><span>Bruno’s workspace</span><form method="POST" action="{{ route('device-access.destroy') }}">@csrf @method('DELETE')<button type="submit" class="text-button">Revoke iPhone access</button></form><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="text-button">Sign out</button></form></div>
    </aside>
    <main class="main-content">
        <header class="workspace-header">
            <div><p class="breadcrumb">Your workspace / {{ $project?->name ?? 'Projects' }}</p><h1>{{ $project?->name ?? 'Your projects' }}</h1>@if($project?->description)<p class="muted project-description">{{ $project->description }}</p>@endif</div>
            <div class="header-actions">
                @if($project)<button type="button" class="button" data-edit-project>Project settings</button><button type="button" class="button button-primary" data-upload-trigger>Upload images</button>@else<button type="button" class="button button-primary" data-new-project>Create project</button>@endif
            </div>
        </header>
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice error-message" role="alert">{{ $errors->first() }}</div>@endif
        <div id="workspace-message" role="status" aria-live="polite" class="notice" hidden></div>
        <section id="gallery">
        @if(!$project)
            <div class="intro"><h2>A home for every feedback group.</h2><p>Choose a project or create one. Upload images together, mark what needs attention, and give your coding agent the whole picture.</p></div>
            <div class="project-grid">
            @foreach($projects as $entry)<a class="project-tile" href="{{ route('projects.show', $entry) }}"><h2>{{ $entry->name }}</h2><p>{{ $entry->description ?: 'Open project' }}</p><span>{{ $entry->images_count }} {{ Str::plural('image', $entry->images_count) }}</span></a>@endforeach
            </div>
        @else
            <input id="image-input" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp" multiple hidden>
            <div class="dropzone" id="dropzone" tabindex="0" role="button" aria-label="Upload images to {{ $project->name }}">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 16V3m-5 5 5-5 5 5M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>
                <div><strong>Drop images here, paste, or browse</strong><p>One upload makes one chunk, with one image or several.</p></div>
            </div>
            <div id="upload-progress" role="status" hidden><label>Uploading feedback <progress max="100" value="0"></progress><span></span></label></div>
            @forelse($chunks as $chunk)
                <section class="chunk-section" aria-labelledby="chunk-{{ $chunk->uuid }}">
                    <div class="chunk-heading"><h2 id="chunk-{{ $chunk->uuid }}">{{ $loop->first ? 'Latest upload chunk' : 'Upload chunk' }} @if($loop->first)<span class="badge">Latest</span>@endif</h2><p><time datetime="{{ $chunk->created_at->toIso8601String() }}">{{ $chunk->created_at->format('d M Y, H:i') }}</time> · {{ $chunk->images->count() }} {{ Str::plural('image', $chunk->images->count()) }}</p></div>
                    <div class="image-grid">
                    @foreach($chunk->images as $image)
                        <button class="image-tile" type="button" data-open-image="{{ $image->uuid }}">
                            <span class="thumbnail"><img src="{{ route('images.preview', $image) }}" alt="{{ $image->name }}" loading="lazy"></span>
                            <span class="image-caption"><strong>{{ $image->name }}</strong><span>{{ $image->comments || $image->annotations ? 'Has feedback' : 'Add feedback' }}</span></span>
                        </button>
                    @endforeach
                    </div>
                </section>
            @empty
                <div class="empty-state"><h2>Your first feedback starts here.</h2><p>Upload screenshots from this page or share them from your iPhone. They will appear together in a chunk.</p></div>
            @endforelse
        @endif
        </section>
        <section id="editor" class="editor" hidden aria-label="Image feedback editor">
            <div class="editor-header"><button type="button" class="button" id="close-editor">Back to project</button><h2 id="editor-name"></h2><a class="button" id="download-original">Download original</a></div>
            <div class="editor-body">
                <div class="drawing-workspace">
                    <div class="drawing-toolbar" role="toolbar" aria-label="Drawing tools">
                        <button type="button" class="tool active" data-tool="pen" aria-pressed="true">Draw</button><button type="button" class="tool" data-tool="arrow" aria-pressed="false">Arrow</button><button type="button" class="tool" data-tool="rectangle" aria-pressed="false">Box</button>
                        <label class="color-label">Color<input type="color" id="drawing-color" value="#ef4444"></label>
                        <button type="button" class="tool" id="undo-drawing" title="Undo drawing (⌘Z / Ctrl+Z)" aria-keyshortcuts="Meta+Z Control+Z">Undo</button><button type="button" class="tool" id="redo-drawing" title="Redo drawing (⌘⇧Z / Ctrl+Shift+Z)" aria-keyshortcuts="Meta+Shift+Z Control+Shift+Z Control+Y">Redo</button><button type="button" class="tool" id="clear-drawing">Clear drawings</button>
                    </div>
                    <div class="canvas-stage"><canvas id="annotation-canvas" aria-label="Draw on this image with your pointer"></canvas><p id="image-load-error" class="error-message" hidden></p></div>
                    <p class="canvas-hint">Draw directly on the image. Your original stays intact.</p>
                </div>
                <aside class="feedback-panel">
                    <label class="qr-field-label" for="image-comments">Your feedback</label><p class="muted">What is wrong? What should improve?</p><textarea class="qr-field qr-field-default qr-textarea" id="image-comments" rows="9" placeholder="Describe the changes you want…"></textarea>
                    <div class="save-row"><button type="button" class="button button-primary" id="save-feedback">Save feedback</button><span id="save-state" role="status" aria-live="polite"></span></div>
                    <section class="vision-section" aria-labelledby="vision-heading">
                        <header class="vision-heading"><span class="vision-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="4"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/></svg></span><div><h3 id="vision-heading">Image context</h3><p class="vision-caption">AI-generated description</p></div><span id="vision-status" class="vision-status" role="status"></span></header>
                        <div class="vision-content" tabindex="0" role="region" aria-label="AI image description"><p id="vision-description"></p></div>
                        <footer class="vision-footer"><p>Included when your coding agent reads this image.</p><button type="button" class="text-button" id="retry-description" hidden>Retry description</button></footer>
                    </section>
                    <section class="move-section"><label class="qr-field-label" for="move-project">Move to project</label><div class="qr-field-shell"><select class="qr-field qr-field-default qr-select" id="move-project">@foreach($projects as $entry)<option value="{{ $entry->id }}">{{ $entry->name }}</option>@endforeach</select><span class="qr-select-indicator" aria-hidden="true">⌄</span></div><button type="button" class="button" id="move-image">Move image</button></section>
                    <button type="button" class="text-button danger" id="delete-image">Delete image and feedback</button>
                </aside>
            </div>
        </section>
    </main>
</div>
<dialog id="project-dialog" class="project-dialog">
    <form id="project-form" method="POST" action="{{ route('projects.store') }}" class="stack">
        @csrf<input id="project-method" name="_method" value="POST" type="hidden">
        <div class="dialog-heading"><h2 id="project-dialog-title">Create project</h2><button type="button" class="tool" data-close-project aria-label="Close project form">Close</button></div>
        <label class="form-label">Project name<input class="qr-field qr-field-default" id="project-name" name="name" maxlength="120" required></label>
        <label class="form-label">Project identifier<input class="qr-field qr-field-default" id="project-slug" name="slug" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="120" required><span class="field-hint">Used to find this project, for example taxiny.</span></label>
        <label class="form-label">Description<textarea class="qr-field qr-field-default qr-textarea" id="project-description" name="description" maxlength="2000" rows="3"></textarea></label>
        <button class="button button-primary" id="project-submit" type="submit">Create project</button>
    </form>
    @if($project)<form id="delete-project-form" action="{{ route('projects.destroy', $project) }}" method="POST" class="delete-project">@csrf @method('DELETE')<p>Deleting this project permanently removes its images and annotations.</p><button class="text-button danger" type="submit">Delete project permanently</button></form>@endif
</dialog>
<script type="application/json" id="workspace-config">{!! json_encode(['project' => $project ? ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'description' => $project->description] : null, 'create_url' => route('projects.store'), 'project_url' => $project ? route('projects.show', $project) : null, 'upload_url' => $project ? route('chunks.start', $project) : null, 'latest_url' => $project ? route('projects.latest', $project) : null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
