<?php $__env->startSection('title', 'Page Not Found'); ?>

<?php $__env->startSection('content'); ?>
<div class="container py-vh-10">
    <div class="row min-vh-80">
        <div class="col-md-6 d-flex justify-content-center align-items-center">
            <img src="<?php echo e(asset('assets/img/error.svg')); ?>" alt="404" class="error-image">
        </div>
        <div class="col-md-6 d-flex flex-column justify-content-center align-items-center">
            <h1 class="error-number">404</h1>
            <p class="h4">Ooops!</p>
            <p class="h6 mb-3 mt-1">The page you requested was not found.!</p>
            <div>
                <a href="<?php echo e(route('home')); ?>" class="btn btn-dark mt-3">Go to Home Page</a>
            </div>
        </div>
    </div>
</div>

<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.error', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\wamp64\www\shipxpeed_new\resources\views/errors/404.blade.php ENDPATH**/ ?>