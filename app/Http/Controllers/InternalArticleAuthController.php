<?php

namespace App\Http\Controllers;

use App\Enums\EarnSessionStatus;
use App\Models\InternalArticleView;
use Illuminate\Http\Request;
use Illuminate\View\View;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-view inline-reader page (`/read-articles/internal/{token}`).
 *
 * The user came here from /read-articles → start endpoint, which
 * minted a fresh InternalArticleView with `status=pending` and an
 * unguessable 28-char epoch_token. Same single-use semantics as
 * the shortlink + PTC auth landings:
 *
 *   - 404 if the token doesn't match a click owned by this user
 *   - 410 if the click already resolved (no replay)
 *   - otherwise render article body inline with a read-time
 *     countdown + captcha + claim button
 *
 * Markdown is rendered via league/commonmark's GitHub-flavoured
 * converter (already in tree). The converter strips arbitrary HTML
 * by default — operators get GFM headings, paragraphs, lists,
 * code blocks, and links, but a hostile body can't inject script.
 */
class InternalArticleAuthController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $view = InternalArticleView::where('user_id', $request->user()->id)
            ->where('epoch_token', $token)
            ->with('article')
            ->first();

        if (! $view) {
            throw new NotFoundHttpException('Article view not found.');
        }
        if ($view->status !== EarnSessionStatus::Pending) {
            throw new HttpException(410, 'This article view has already been resolved.');
        }
        if (! $view->article) {
            throw new HttpException(410, 'The underlying article was removed.');
        }

        // Pre-render Markdown server-side so the blade only emits
        // pre-sanitised HTML. The converter is stateless + cheap;
        // no need to cache for this volume.
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $bodyHtml = $converter->convert($view->article->body)->getContent();

        // Compute remaining seconds from started_at so a refresh resumes
        // the countdown rather than restarting it.
        $elapsedSec = (int) $view->started_at->diffInSeconds(now(), absolute: true);
        $remainingSec = max(0, $view->read_seconds - $elapsedSec);

        return view('internal_articles.auth', [
            'view' => $view,
            'article' => $view->article,
            'body_html' => $bodyHtml,
            'reward_sat' => (int) $view->reward_sat,
            'read_seconds' => (int) $view->read_seconds,
            'remaining_sec' => $remainingSec,
        ]);
    }
}
