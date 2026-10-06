@extends('layouts.app', ['title' => ($project?->name ?? 'Projects').' · Uploadiny'])
@section('content')
<div class="workspace" data-workspace data-project="{{ $project?->slug }}" data-latest="{{ $chunks->first()?->uuid }}" data-latest-completed="{{ $chunks->first() ? ($chunks->first()->completed_at ?? $chunks->first()->created_at)->toIso8601String() : '' }}" data-latest-count="{{ $chunks->first()?->images->count() }}">
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
                <a class="button" href="{{ route('agent-access.show') }}">Agent API access</a>
                @if($project)<button type="button" class="button" data-edit-project>Project settings</button><button type="button" class="button button-primary" data-upload-trigger>Upload files</button>@else<button type="button" class="button button-primary" data-new-project>Create project</button>@endif
            </div>
        </header>
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice error-message" role="alert">{{ $errors->first() }}</div>@endif
        <div id="workspace-message" role="status" aria-live="polite" class="notice" hidden></div>
        @if($project)
            <div class="project-canonical">
                <label for="project-canonical">Project code</label>
                <button type="button" id="project-canonical" class="canonical-chip" data-copy-text="{{ $project->canonical }}" title="Copy project code" aria-label="Copy project code {{ $project->canonical }}" aria-describedby="canonical-hint"><span>{{ $project->canonical }}</span><svg class="copy-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg><svg class="check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg></button>
                <p id="canonical-hint" class="muted">Give this code to Claude or Codex so they pick this project.</p>
                <p class="copy-status muted" data-copy-status role="status" aria-live="polite"></p>
            </div>
        @endif
        <section id="gallery" @if($openImage ?? null) hidden @endif>
        @if(!$project)
            @if($projects->isEmpty())
            <div class="empty-projects">
                <svg class="empty-illustration" viewBox="0 0 160 120" fill="none" aria-hidden="true">
                    <rect class="sheet sheet-back" x="38" y="22" width="84" height="62" rx="10"/>
                    <rect class="sheet sheet-mid" x="30" y="30" width="84" height="62" rx="10"/>
                    <g class="sheet-front"><rect x="22" y="38" width="84" height="62" rx="10"/><rect x="32" y="49" width="30" height="6" rx="3" class="ink-soft"/><rect x="32" y="61" width="52" height="5" rx="2.5" class="ink-faint"/><rect x="32" y="71" width="44" height="5" rx="2.5" class="ink-faint"/></g>
                    <ellipse class="marker" cx="76" cy="66" rx="26" ry="15"/>
                    <circle class="plus-badge" cx="118" cy="92" r="15"/><path class="plus" d="M118 85v14M111 92h14"/>
                </svg>
                <h2>No projects yet</h2>
                <p>Create a project, then drop in screenshots or share them from your iPhone. Mark what needs fixing — your coding agent sees the whole picture.</p>
                <button type="button" class="button button-primary" data-new-project>Create your first project</button>
            </div>
            @else
            <div class="intro"><p>Pick a project, drop in screenshots, mark what needs fixing — your coding agent sees the whole picture.</p></div>
            @endif
            <div class="project-grid">
            @foreach($projects as $entry)<a class="project-tile" href="{{ route('projects.show', $entry) }}"><h2>{{ $entry->name }}</h2><p>{{ $entry->description ?: 'Open project' }}</p><span>Project code: <code>{{ $entry->canonical }}</code></span><span>{{ $entry->images_count }} {{ Str::plural('file', $entry->images_count) }}</span></a>@endforeach
            </div>
        @else
            <input id="image-input" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,video/mp4,video/quicktime,video/x-m4v,.mov,.m4v" multiple hidden>
            <div class="dropzone" id="dropzone" tabindex="0" role="button" aria-label="Upload files to {{ $project->name }}">
                <svg class="drop-illustration" viewBox="0 0 120 88" fill="none" aria-hidden="true">
                    <path class="folder-back" d="M10 22a8 8 0 0 1 8-8h22l8 9h54a8 8 0 0 1 8 8v41a8 8 0 0 1-8 8H18a8 8 0 0 1-8-8Z"/>
                    <g class="drop-sheet drop-sheet-1"><rect x="28" y="12" width="40" height="50" rx="5"/><rect x="34" y="20" width="18" height="4" rx="2" class="sheet-ink"/><rect x="34" y="29" width="28" height="3" rx="1.5" class="sheet-faint"/><rect x="34" y="36" width="22" height="3" rx="1.5" class="sheet-faint"/></g>
                    <g class="drop-sheet drop-sheet-2"><rect x="54" y="8" width="40" height="50" rx="5"/><circle cx="74" cy="30" r="9" class="sheet-mark"/><rect x="60" y="45" width="26" height="3" rx="1.5" class="sheet-faint"/></g>
                    <path class="folder-front" d="M6 40a7 7 0 0 1 7-7h94a7 7 0 0 1 7 7l-4 34a8 8 0 0 1-8 7H18a8 8 0 0 1-8-7Z"/>
                    <path class="drop-arrow" d="M60 68V50m-7 7 7-7 7 7"/>
                </svg>
                <div class="drop-copy"><strong>Drop screenshots or recordings</strong><p>or click to browse · paste with ⌘V. One upload keeps its files together in one feedback chunk.</p></div>
                <span class="drop-button" aria-hidden="true">Choose files</span>
                <p class="drop-formats">PNG · JPG · GIF · WebP · BMP · MP4 · MOV · up to 95 MB each</p>
            </div>
            <div id="upload-progress" role="status" hidden><label>Uploading feedback <progress max="100" value="0"></progress><span></span></label></div>
            <div id="chunk-list">
            @if($chunks->isNotEmpty())
                <header class="uploads-heading"><h2>Latest uploads</h2><p>Newest first</p></header>
            @endif
            <div class="chunk-gallery">
            @forelse($chunks as $chunk)
                @php($image = $chunk->images->last())
                <article class="chunk-card" aria-labelledby="chunk-{{ $chunk->uuid }}">
                    <header class="chunk-card-heading"><h3 id="chunk-{{ $chunk->uuid }}"><time datetime="{{ $chunk->created_at->toIso8601String() }}">{{ $chunk->created_at->format('d M Y, H:i') }}</time></h3>@if($loop->first)<span class="badge">Latest</span>@endif</header>
                    <button class="image-tile chunk-stack {{ $chunk->images->count() > 1 ? 'has-stack' : '' }}" type="button" data-open-image="{{ $image->uuid }}" data-play-recording="{{ $image->isVideo() ? 'true' : 'false' }}" data-chunk="{{ $chunk->uuid }}" data-chunk-images="{{ $chunk->images->pluck('uuid')->toJson() }}" aria-label="Open {{ $chunk->images->count() }} {{ Str::plural('file', $chunk->images->count()) }} in this upload chunk">
                        @foreach($chunk->images->reverse()->slice(1)->take(2) as $previous)
                            <span class="stack-layer stack-layer-{{ $loop->iteration }}" aria-hidden="true">@if($previous->isVideo())<span class="stack-recording-preview" data-recording-preview="{{ route('images.preview', $previous) }}"></span>@else<img src="{{ route('images.preview', $previous) }}" alt="" loading="lazy">@endif</span>
                        @endforeach
                        <span class="chunk-cover">
                            <span class="thumbnail">@if($image->isVideo())<span class="recording-thumbnail" data-recording-preview="{{ route('images.preview', $image) }}" data-recording-frame-alt="First frame of {{ $image->name }}"><span class="recording-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="m10 8 6 4-6 4Z"/></svg><span>Screen recording</span></span><span class="recording-play-badge">Play recording</span></span>@else<img src="{{ route('images.preview', $image) }}" alt="{{ $image->name }}" loading="lazy">@endif<span class="chunk-file-count">{{ $chunk->images->count() }} {{ Str::plural('file', $chunk->images->count()) }}</span></span>
                            <span class="image-caption"><strong>{{ $image->name }}</strong><span>{{ $chunk->images->count() > 1 ? 'Latest file · open to browse' : ($image->comments || $image->annotations ? 'Has feedback' : 'Add feedback') }}</span></span>
                        </span>
                    </button>
                </article>
            @empty
                <div class="empty-state"><h2>Your first feedback starts here.</h2><p>Upload screenshots or recordings from this page or share them from your iPhone. They will appear together in a chunk.</p></div>
            @endforelse
            </div>
            </div>
        @endif
        </section>
        <section id="editor" class="editor" @unless($openImage ?? null) hidden @endunless aria-label="File feedback editor">
            <div class="editor-header"><div class="editor-title-row"><button type="button" class="button" id="close-editor">Back to project</button><h2 id="editor-name" tabindex="-1">{{ ($openImage ?? null)?->name }}</h2><nav class="chunk-navigation" id="chunk-navigation" aria-label="Files in this upload chunk"><button type="button" id="first-file" title="First file" aria-label="First file"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 6-6 6 6 6m7-12-6 6 6 6"/></svg></button><button type="button" id="previous-file" title="Previous file (⌘← / Ctrl+←)" aria-label="Previous file" aria-keyshortcuts="Meta+ArrowLeft Control+ArrowLeft"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button><span id="chunk-position" role="status" aria-live="polite">@if($openChunk ?? null){{ $openChunk->images->pluck('uuid')->search($openImage->uuid) + 1 }} of {{ $openChunk->images->count() }}@endif</span><button type="button" id="next-file" title="Next file (⌘→ / Ctrl+→)" aria-label="Next file" aria-keyshortcuts="Meta+ArrowRight Control+ArrowRight"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button><button type="button" id="last-file" title="Last file" aria-label="Last file"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 6 6 6-6 6m7-12 6 6-6 6"/></svg></button></nav></div><div class="editor-actions"><button type="button" class="button" id="duplicate-image" title="Duplicate the original image without annotations">Duplicate</button><a class="button" id="download-original">Download original</a><button type="button" class="button button-danger" id="delete-image" title="Delete this file and its feedback">Delete</button></div></div>
            <div class="editor-body">
                <div class="drawing-workspace" id="drawing-workspace" @if(($openImage ?? null)?->isVideo()) hidden @endif>
                    <div class="drawing-toolbar" role="group" aria-label="Annotation controls">
                        <div class="drawing-toolbar-row">
                            <div class="tool-group" role="group" aria-label="Drawing tools">
                                <button type="button" class="tool" data-tool="select" aria-pressed="false" title="Select — click a mark, then press Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 3 14 7-6 2-2 6Z"/></svg><span>Select</span></button>
                                <button type="button" class="tool active" data-tool="pen" aria-pressed="true" title="Pen"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 3 5 5-12 12-6 1 1-6Z"/><path d="m14 5 5 5"/></svg><span>Pen</span></button>
                                <button type="button" class="tool" data-tool="callout" aria-pressed="false" title="Annotation — click to add a rectangle, arrow and note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="9" height="7" rx="1"/><path d="M18 14 8 9m2 5-2-5 5 1"/><rect x="13" y="15" width="9" height="6" rx="1"/></svg><span>Annotation</span></button>
                                <button type="button" class="tool" data-tool="arrow" aria-pressed="false" title="Arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20 20 4M8 4h12v12"/></svg><span>Arrow</span></button>
                                <button type="button" class="tool" data-tool="line" aria-pressed="false" title="Line"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20 20 4"/><circle cx="4" cy="20" r="1"/><circle cx="20" cy="4" r="1"/></svg><span>Line</span></button>
                                <button type="button" class="tool" data-tool="rectangle" aria-pressed="false" title="Rectangle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="1"/></svg><span>Rectangle</span></button>
                                <button type="button" class="tool" data-tool="ellipse" aria-pressed="false" title="Ellipse"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="12" rx="9" ry="7"/></svg><span>Ellipse</span></button>
                                <button type="button" class="tool" data-tool="eraser" aria-pressed="false" title="Eraser — remove a whole mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 3 5 5a2 2 0 0 1 0 3L11 21H7l-5-5a2 2 0 0 1 0-3L13 3a2 2 0 0 1 3 0Z"/><path d="m7 8 9 9M11 21h11"/></svg><span>Eraser</span></button>
                            </div>
                            <div class="tool-group history-tools" role="group" aria-label="Drawing history">
                                <button type="button" class="tool history-tool" id="undo-drawing" disabled title="Undo (⌘Z / Ctrl+Z)" aria-keyshortcuts="Meta+Z Control+Z"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 4-5 5 5 5M3 9h11a7 7 0 0 1 0 14"/></svg><span>Undo</span></button>
                                <button type="button" class="tool history-tool" id="redo-drawing" disabled title="Redo (⌘⇧Z / Ctrl+Shift+Z)" aria-keyshortcuts="Meta+Shift+Z Control+Shift+Z Control+Y"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 4 5 5-5 5M21 9H10a7 7 0 0 0 0 14"/></svg><span>Redo</span></button>
                                <button type="button" class="tool history-tool" id="delete-mark" disabled title="Delete selected mark (Delete)" aria-keyshortcuts="Delete Backspace"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg><span>Delete</span></button>
                                <button type="button" class="tool history-tool" id="clear-drawing" disabled title="Clear all drawings — you can undo this"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg><span>Clear all</span></button>
                            </div>
                        </div>
                        <div class="drawing-toolbar-row drawing-options">
                            <div class="stroke-control"><label for="drawing-width">Thickness</label><span id="stroke-preview" aria-hidden="true"></span><input type="range" id="drawing-width" min="2" max="24" value="6" step="1"><output id="drawing-width-value" for="drawing-width">6 px</output></div>
                            <div class="color-control" role="group" aria-label="Ink color"><span>Color</span>
                                <button type="button" class="color-swatch" data-color="#ef4444" style="--swatch: #ef4444" title="Red" aria-label="Red ink" aria-pressed="true"></button>
                                <button type="button" class="color-swatch" data-color="#16a34a" style="--swatch: #16a34a" title="Green" aria-label="Green ink" aria-pressed="false"></button>
                                <button type="button" class="color-swatch" data-color="#3b82f6" style="--swatch: #3b82f6" title="Blue" aria-label="Blue ink" aria-pressed="false"></button>
                                <button type="button" class="color-swatch" data-color="#182337" style="--swatch: #182337" title="Dark ink" aria-label="Dark ink" aria-pressed="false"></button>
                                <button type="button" class="color-swatch" data-color="#ffffff" style="--swatch: #ffffff" title="White" aria-label="White ink" aria-pressed="false"></button>
                                <div class="color-gradient" id="color-gradient" role="slider" tabindex="0" aria-label="Colour gradient" aria-valuemin="0" aria-valuemax="360" aria-valuenow="0" aria-valuetext="No colour picked from the gradient" title="Click or drag to pick a colour. Arrow keys fine-tune it."><span class="color-gradient-thumb" id="color-gradient-thumb" hidden></span></div>
                                <label class="custom-color" style="--custom: #ef4444" title="Pick any colour"><input type="color" id="drawing-color" value="#ef4444" aria-label="Custom ink color"></label>
                                <input type="text" id="drawing-color-hex" class="color-hex" value="#ef4444" maxlength="7" spellcheck="false" autocomplete="off" aria-label="Custom colour code" placeholder="#RRGGBB">
                            </div>
                            <div class="zoom-controls" role="group" aria-label="Canvas zoom">
                                <button type="button" class="tool icon-tool" id="zoom-out" aria-label="Zoom out" title="Zoom out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/></svg></button>
                                <output id="canvas-zoom" aria-live="polite">100%</output>
                                <button type="button" class="tool icon-tool" id="zoom-in" aria-label="Zoom in" title="Zoom in"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5v14"/></svg></button>
                                <button type="button" class="tool fit-tool" id="zoom-fit" title="Fit the image to the canvas"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/></svg><span>Fit</span></button>
                            </div>
                        </div>
                    </div>
                    <div class="canvas-stage" id="canvas-stage"><div class="canvas-bed"><div class="canvas-frame"><canvas id="annotation-canvas" aria-label="Draw on this image with your pointer"></canvas><div id="callout-overlay" class="callout-overlay" hidden></div></div></div><p id="image-load-error" class="error-message" hidden></p></div>
                    <div class="canvas-footer"><p id="canvas-tool-hint">Pen: draw freely on the image.</p><span id="canvas-dimensions"></span><span class="original-hint">Original preserved</span></div>
                </div>
                <div class="video-workspace" id="video-workspace" @unless(($openImage ?? null)?->isVideo()) hidden @endunless>
                    <div class="video-stage"><video id="recording-player" controls playsinline preload="metadata" aria-label="Screen recording playback"></video></div>
                    <div class="video-footer"><button type="button" class="button button-primary recording-play-button" id="recording-play"><svg id="recording-play-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7Z"/></svg><svg id="recording-pause-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" hidden><path d="M6 5h4v14H6Zm8 0h4v14h-4Z"/></svg><span id="recording-play-label">Play recording</span></button><strong>Screen recording</strong><span>Play, pause or scrub to review. Add timestamps in your feedback.</span></div>
                    <p id="video-load-error" class="error-message" hidden>This browser cannot play this recording. Download the original to watch it.</p>
                </div>
                <aside class="feedback-panel">
                    <label class="qr-field-label" for="image-comments">Your feedback</label><p class="muted">What is wrong? What should improve?</p><textarea class="qr-field qr-field-default qr-textarea" id="image-comments" rows="5" placeholder="Describe the changes you want…"></textarea>
                    <div class="save-row"><button type="button" class="button button-primary" id="save-feedback" title="Save feedback (⌘Enter / Ctrl+Enter)" aria-keyshortcuts="Meta+Enter Control+Enter">Save feedback</button><span id="save-state" role="status" aria-live="polite"></span></div>
                    <p class="field-hint">Annotations and feedback save automatically. ⌘Enter saves immediately.</p>
                    <details id="vision-section" class="vision-section" @if(($openImage ?? null)?->isVideo()) hidden @endif>
                        <summary class="vision-heading"><span class="vision-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="4"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/></svg></span><div><h3 id="vision-heading">Image context</h3><p class="vision-caption">AI-generated description</p></div><span id="vision-status" class="vision-status" role="status"></span><svg class="vision-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
                        <div class="vision-content" tabindex="0" role="region" aria-label="AI image description"><p id="vision-description"></p></div>
                        <footer class="vision-footer"><p>Included when your coding agent reads this image.</p><button type="button" class="text-button" id="retry-description" hidden>Retry description</button></footer>
                    </details>
                    <details class="file-actions">
                        <summary><span>Move or copy this file</span><svg class="vision-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
                    <section class="move-section chunk-transfer-section"><label class="qr-field-label" for="target-chunk">Copy or move to chunk</label><div class="qr-field-shell"><select class="qr-field qr-field-default qr-select" id="target-chunk"><option value="">Choose an upload chunk…</option></select><span class="qr-select-indicator" aria-hidden="true">⌄</span></div><p class="muted" id="chunk-transfer-hint">Annotations and comments go with the file.</p><div class="chunk-transfer-actions"><button type="button" class="button" id="copy-to-chunk">Copy to chunk</button><button type="button" class="button" id="move-to-chunk">Move to chunk</button></div></section>
                    <section class="move-section"><label class="qr-field-label" for="move-project">Move to project</label><div class="qr-field-shell"><select class="qr-field qr-field-default qr-select" id="move-project">@foreach($projects as $entry)<option value="{{ $entry->id }}">{{ $entry->name }}</option>@endforeach</select><span class="qr-select-indicator" aria-hidden="true">⌄</span></div><button type="button" class="button" id="move-image">Move file</button></section>
                    </details>
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
        <label class="form-label">Project URL identifier<input class="qr-field qr-field-default" id="project-slug" name="slug" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="120" required><span class="field-hint">Used in this project’s website address, for example taxiny. The agent’s project code stays the same when this changes.</span></label>
        <label class="form-label">Description<textarea class="qr-field qr-field-default qr-textarea" id="project-description" name="description" maxlength="2000" rows="3"></textarea></label>
        <button class="button button-primary" id="project-submit" type="submit">Create project</button>
    </form>
    @if($project)<form id="delete-project-form" action="{{ route('projects.destroy', $project) }}" method="POST" class="delete-project">@csrf @method('DELETE')<p>Deleting this project permanently removes its files and annotations.</p><button class="text-button danger" type="submit">Delete project permanently</button></form>@endif
</dialog>
<script type="application/json" id="workspace-config">{!! json_encode(['project' => $project ? ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'description' => $project->description] : null, 'create_url' => route('projects.store'), 'project_url' => $project ? route('projects.show', $project) : null, 'upload_url' => $project ? route('chunks.start', $project) : null, 'last_chunk_url' => $project ? route('projects.last-chunk', $project) : null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
