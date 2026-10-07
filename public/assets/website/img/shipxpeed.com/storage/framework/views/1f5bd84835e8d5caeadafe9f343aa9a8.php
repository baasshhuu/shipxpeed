<!-- ===============================================-->
<!--    JavaScripts-->
<!-- ===============================================-->

<div id="overlay" class="bg-loader">
    <div class="flexbox">
        <span class="hm-spinner cv-spinner-"></span>
    </div>
</div>
<input name="config" type="hidden" value="<?php echo e(isset($config) ? json_encode($config) : null); ?>">
<script src="<?php echo e(asset('assets/plugins/bootstrap/bootstrap.bundle.min.js')); ?>"></script>
<script src="<?php echo e(asset('assets/js/jquery.min.js')); ?>"></script>
<script src="<?php echo e(asset('assets/js/jquery.validate.js')); ?>"></script>
<script src="<?php echo e(asset('assets/js/custom-methods.js')); ?>"></script>
<script src="<?php echo e(asset('assets/plugins/toastr/toastr.min.js')); ?>"></script>
<script src="<?php echo e(asset('assets/plugins/perfect-scrollbar/perfect-scrollbar.min.js')); ?>"></script>
<script src="<?php echo e(asset('assets/js/waves.min.js')); ?>"></script>
<script src="<?php echo e(asset('assets/plugins/datatables/datatables.min.js')); ?>"></script>
<script src="<?php echo e(asset('assets/js/app.js')); ?>"></script>
<script src="<?php echo e(asset('assets/js/custom.js')); ?>"></script>
<?php echo $__env->yieldContent('js'); ?>


<?php echo $__env->make('partial.toastr', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<?php /**PATH C:\wamp64\www\shipxpeed_new\public\assets\website\img\shipxpeed.com\resources\views/partial/common/footer.blade.php ENDPATH**/ ?>