<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no">

    <!-- ===============================================-->
    <!--    Document Title-->
    <!-- ===============================================-->
    <title><?php echo e($site_settings['application_name'] ?? ''); ?></title>

    <!-- CSRF Token -->
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>" />

    <?php echo $__env->yieldContent('css'); ?>


    <link href="<?php echo e(asset('assets/css/light/loader.css')); ?>" rel="stylesheet" type="text/css" />

    <!-- ===============================================-->
    <!--    Favicons-->
    <!-- ===============================================-->
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo e(asset('storage/' . ($site_settings['favicon'] ?? '') )); ?>" />
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo e(asset('storage/' .($site_settings['favicon'] ?? '') )); ?>" />
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo e(asset('storage/' .($site_settings['favicon'] ?? '') )); ?>" />
    <link rel="shortcut icon" type="image/x-icon" href="<?php echo e(asset('storage/' .($site_settings['favicon'] ?? '') )); ?>" />

    <!-- ===============================================-->
    <!--    Stylesheets-->
    <!-- ===============================================-->
    <link href="https://fonts.googleapis.com/css?family=Nunito:400,600,700" rel="stylesheet">
    <link href="<?php echo e(asset('assets/plugins/bootstrap/bootstrap.min.css')); ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo e(asset('assets/plugins/fontawesome-pro/css/all.min.css')); ?>" rel="stylesheet" />
    <link href="<?php echo e(asset('assets/plugins/toastr/toastr.min.css')); ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo e(asset('assets/css/light/main.min.css')); ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo e(asset('assets/css/light/structure.min.css')); ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo e(asset('assets/css/light/waves.min.css')); ?>" rel="stylesheet" type="text/css" />
    <!--    ADDED--> <link href="<?php echo e(asset('assets/css/light/authentication/auth-cover.css')); ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo e(asset('assets/css/custom.css')); ?>" rel="stylesheet" id="user-style-default" />

</head>

<body class="login-body d-flex align-items-center justify-content-end" style="margin:0; padding:0;">


            <?php echo $__env->yieldContent('content'); ?>
    
</body>
    <!-- BEGIN GLOBAL MANDATORY SCRIPTS -->
    <script src="<?php echo e(asset('assets/plugins/bootstrap/bootstrap.bundle.min.js')); ?>"></script>
    <script src="<?php echo e(asset('assets/js/jquery.min.js')); ?>"></script>
    <script src="<?php echo e(asset('assets/js/jquery.validate.js')); ?>"></script>
    <script src="<?php echo e(asset('assets/js/custom-methods.js')); ?>"></script>
    <script src="<?php echo e(asset('assets/plugins/toastr/toastr.min.js')); ?>"></script>
    <script src="<?php echo e(asset('assets/plugins/perfect-scrollbar/perfect-scrollbar.min.js')); ?>"></script>
    <?php echo $__env->yieldContent('js'); ?>
    <!-- END GLOBAL MANDATORY SCRIPTS -->
    <?php echo $__env->make('partial.toastr', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</body>

</html><?php /**PATH C:\wamp64\www\shipxpeed_new\resources\views/layouts/auth.blade.php ENDPATH**/ ?>