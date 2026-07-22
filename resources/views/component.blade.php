@php
    // Page meta passed to the directive, e.g. @userInteractionTracker(['page_id' => $page->id, 'page_title' => $page->title])
    $pageMeta = $pageMeta ?? [];
    $endpoint = '/' . config('user-interaction-tracker.route.path', 'api/track-interaction');
    $linkPatterns = config('user-interaction-tracker.link_clicks', []);
@endphp

<script>
window.UIT_ENDPOINT = {{ json_encode($endpoint) }};
window.UIT_PAGE_META = {{ json_encode($pageMeta) }};
window.UIT_LINK_PATTERNS = {{ json_encode($linkPatterns) }};
</script>
<script src="{{ asset('vendor/user-interaction-tracker/tracker.js') }}"></script>
