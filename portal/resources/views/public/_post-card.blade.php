<a class="post-card" href="{{ route('post', $post->slug) }}" style="text-decoration:none;color:inherit">
    @if($post->image)<img src="{{ $post->image->url() }}" alt="" loading="lazy">@endif
    <div class="body">
        <span class="badge {{ $post->type === 'announcement' ? 'EXPIRING' : 'ACTIVE' }}">{{ strtoupper(\App\Models\Post::TYPES[$post->type]) }}</span>@if($post->is_pinned) <span class="badge REMOVED">PINNED</span>@endif
        <h3>{{ $post->title }}</h3>
        <div class="meta">{{ $post->published_at->format('j M Y') }}</div>
        <p>{{ $post->summary ?: \Illuminate\Support\Str::limit(strip_tags(\App\Support\Markdown::render($post->body)), 140) }}</p>
    </div>
</a>
