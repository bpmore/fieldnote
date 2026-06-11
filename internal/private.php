<?php
use function Dropplets\e;
use function Dropplets\csrf_field;
require __DIR__ . '/header.php';
?>
<div class="row my-1">
    <div class="col-md-3"></div>
    <div class="col-md-6 text-center">
        <h1><?php i18n("private_title"); ?></h1>
        <form method="post" role="form" action="">
            <?= csrf_field() ?>
            <fieldset class="my-3 w-50 mx-auto">
                <label for="password" class="form-label"><?php i18n("private_password_legend"); ?></label>
                <input type="password" class="form-control" name="password" id="password" placeholder="<?php i18n("private_password_placeholder"); ?>" required>
            </fieldset>
            <div class="row mx-auto w-50 text-center">
                <button class="btn btn-primary mb-3" type="submit"><?php i18n("private_submit"); ?></button>
            </div>
        </form>
        <div class="justify-content-center">
            <button type="button" class="btn btn-secondary w-25 mx-1" data-back>Go Back</button>
            <a class="btn btn-secondary w-25 mx-1" href="<?= e($router->generate('home')) ?>">Go Home</a>
        </div>
    </div>
    <div class="col-md-3"></div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
