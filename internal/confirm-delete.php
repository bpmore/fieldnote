<?php
use function Fieldnote\e;
use function Fieldnote\csrf_field;
use function Fieldnote\fn_post_url;

// Server-rendered delete confirmation. Reached by GET on the delete route —
// the inline owner control on a public post links here because that page runs
// under a no-JS CSP and cannot use the dashboard's data-confirm guard. The
// POST below is the real delete, gated by the central CSRF check.
require __DIR__ . '/header.php';
?>
<div class="row">
    <div class="col-md-3"></div>
    <div class="col-md-6">
        <h1 class="setupH1 text-center">Delete post</h1>
        <div class="alert alert-warning" role="alert">
            Permanently delete <strong><?= e($post['title'] ?? 'this post') ?></strong>? This cannot be undone.
        </div>
        <div class="d-flex gap-2 justify-content-center">
            <form method="post" action="<?= e($router->generate('deletePost', ['id' => (int) $post['_id']])) ?>" class="m-0">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">Delete permanently</button>
            </form>
            <a href="<?= e(fn_post_url($router, $post)) ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
    <div class="col-md-3"></div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
