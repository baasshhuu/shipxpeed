<script>
    toastr.options = {
        closeButton: true,
        progressBar: true,
        hideEasing: "linear",
        showMethod: "fadeIn",
        hideMethod: "fadeOut",
        debug: false,
        newestOnTop: false,
        positionClass: "toast-top-right",
        preventDuplicates: false,
        onclick: null,
        showDuration: "300",
        hideDuration: "1000",
        timeOut: "5000",
        extendedTimeOut: "1000",
        showEasing: "swing",
    }

    <?php if(Session::has('success')): ?>
    toastr.success("<?php echo e(Session::get('success')); ?>");
    <?php endif; ?>

    <?php if(Session::has('info')): ?>
    toastr.info("<?php echo e(Session::get('info')); ?>");
    <?php endif; ?>

    <?php if(Session::has('warning')): ?>
    toastr.warning("<?php echo e(Session::get('warning')); ?>");
    <?php endif; ?>

    <?php if(Session::has('error')): ?>
    toastr.error("<?php echo e(Session::get('error')); ?>");
    <?php endif; ?>
</script>
<?php /**PATH C:\wamp64\www\shipxpeed_new\resources\views/partial/toastr.blade.php ENDPATH**/ ?>