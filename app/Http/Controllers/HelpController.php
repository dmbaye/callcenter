<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app help center: Markdown articles from resources/help, listed
 * in config/help.php. Every article is open to every signed-in user; the
 * reader's role only decides which ones are recommended first.
 */
class HelpController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('help/Index', [
            ...$this->navigation($request),
            'audience' => $this->audiences($request),
        ]);
    }

    public function show(Request $request, string $article): Response
    {
        $articles = $this->articles($request);
        $meta = $articles[$article] ?? null;
        $path = resource_path("help/{$article}.md");

        abort_unless(is_array($meta) && File::exists($path), 404);

        $slugs = array_keys($articles);
        $position = array_search($article, $slugs, true);
        $markdown = $this->forMode($this->mode($request), $this->withTeam($request, File::get($path)));

        [$html, $toc] = $this->render($markdown);
        $html = $this->unlinkHidden($html, $articles);

        return Inertia::render('help/Show', [
            ...$this->navigation($request),
            'article' => [
                'slug' => $article,
                'title' => $meta['title'],
                'summary' => $meta['summary'],
                'section' => config("help.sections.{$meta['section']}"),
                'html' => $html,
                'toc' => $toc,
            ],
            'previous' => $this->link($position > 0 ? $slugs[$position - 1] : null),
            'next' => $this->link($slugs[$position + 1] ?? null),
        ]);
    }

    /**
     * Sections with their articles, and a plain-text index for the search box.
     *
     * @return array<string, mixed>
     */
    private function navigation(Request $request): array
    {
        $articles = collect($this->articles($request));

        return [
            'sections' => collect((array) config('help.sections'))
                // A section with nothing for this organization is not listed.
                ->filter(fn (string $title, string $key) => $articles->contains(fn (array $meta) => $meta['section'] === $key))
                ->map(fn (string $title, string $key) => [
                    'key' => $key,
                    'title' => $title,
                    'articles' => $articles
                        ->filter(fn (array $meta) => $meta['section'] === $key)
                        ->map(fn (array $meta, string $slug) => [
                            'slug' => $slug,
                            'title' => $meta['title'],
                            'summary' => $meta['summary'],
                            'audience' => $meta['audience'],
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            // Searched in the browser: a few kilobytes for the whole help center.
            'searchIndex' => $articles
                ->map(fn (array $meta, string $slug) => [
                    'slug' => $slug,
                    'text' => $this->plainText($this->forMode($this->mode($request), File::get(resource_path("help/{$slug}.md")))),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Articles for the reader's organization: an internal-only one does not
     * get the call-center documentation (config/help.php `modes`).
     *
     * @return array<string, array<string, mixed>>
     */
    private function articles(Request $request): array
    {
        $mode = $this->mode($request);

        return array_filter(
            (array) config('help.articles'),
            fn (array $meta) => in_array($mode, $meta['modes'] ?? Team::USAGE_MODES, true),
        );
    }

    private function mode(Request $request): string
    {
        return $request->user()->currentTeam?->usage_mode ?? Team::USAGE_CALL_CENTER;
    }

    /**
     * Passages between <!-- centre-appels --> and <!-- /centre-appels -->
     * only apply to call centers; the marker lines never reach the page.
     */
    private function forMode(string $mode, string $markdown): string
    {
        $pattern = '/^<!-- centre-appels -->\R(.*?)^<!-- \/centre-appels -->\R?/ms';

        return (string) preg_replace($pattern, $mode === Team::USAGE_CALL_CENTER ? '$1' : '', $markdown);
    }

    /**
     * Links to articles the organization does not see become plain text.
     *
     * @param  array<string, mixed>  $articles
     */
    private function unlinkHidden(string $html, array $articles): string
    {
        return (string) preg_replace_callback(
            '#<a href="/help/([a-z0-9-]+)(?:\#[^"]*)?">(.*?)</a>#s',
            fn (array $m) => isset($articles[$m[1]]) ? $m[0] : $m[2],
            $html,
        );
    }

    /**
     * Audiences the reader belongs to: an agent reads agent articles, a
     * supervisor also supervision ones, an admin everything.
     *
     * @return list<string>
     */
    private function audiences(Request $request): array
    {
        $user = $request->user();
        $role = $user->currentTeam ? $user->teamRole($user->currentTeam) : null;

        return match (true) {
            $role?->isAtLeast(TeamRole::Admin) ?? false => ['all', 'agent', 'supervisor', 'admin'],
            $role?->isAtLeast(TeamRole::Supervisor) ?? false => ['all', 'agent', 'supervisor'],
            default => ['all', 'agent'],
        };
    }

    /**
     * Markdown to HTML (GitHub flavour: tables), with an anchor on each
     * section title for the table of contents.
     *
     * @return array{0: string, 1: list<array{id: string, title: string}>}
     */
    private function render(string $markdown): array
    {
        // The page shows the title itself.
        $markdown = preg_replace('/\A#\s+.+\R+/u', '', ltrim($markdown)) ?? $markdown;

        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        // Wide tables scroll inside their own box on a phone.
        $html = str_replace(['<table>', '</table>'], ['<div class="table-wrap"><table>', '</table></div>'], $html);

        $toc = [];

        $html = preg_replace_callback('#<h2>(.*?)</h2>#s', function (array $matches) use (&$toc) {
            $title = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5);
            $id = Str::slug($title);
            $toc[] = ['id' => $id, 'title' => $title];

            return '<h2 id="'.e($id).'">'.$matches[1].'</h2>';
        }, $html) ?? $html;

        return [$html, $toc];
    }

    /** Links such as /{team}/calls open the reader's own team. */
    private function withTeam(Request $request, string $markdown): string
    {
        $slug = $request->user()->currentTeam?->slug;

        return str_replace('/{team}/', $slug ? "/{$slug}/" : '/dashboard#', $markdown);
    }

    private function plainText(string $markdown): string
    {
        $text = preg_replace(['/\[([^\]]+)\]\([^)]+\)/u', '/[#*`>|]+/u', '/\s+/u'], ['$1', ' ', ' '], $markdown) ?? $markdown;

        return Str::limit(trim($text), 4000, '');
    }

    /** @return array{slug: string, title: string}|null */
    private function link(?string $slug): ?array
    {
        return $slug === null ? null : ['slug' => $slug, 'title' => config("help.articles.{$slug}.title")];
    }
}
